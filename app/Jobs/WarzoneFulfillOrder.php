<?php

namespace App\Jobs;

use App\Exceptions\RuleValidationException;
use App\Exceptions\WarzoneApiException;
use App\Models\Carmis;
use App\Models\Order;
use App\Models\WarzoneSupplierPurchase;
use App\Models\WarzoneSupplierSetting;
use App\Service\OrderProcessService;
use App\Service\WarzoneInventoryService;
use App\Service\WarzoneShopClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class WarzoneFulfillOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $timeout = 75;

    /** @var int */
    private $purchaseId;

    public function __construct(int $purchaseId)
    {
        $this->purchaseId = $purchaseId;
    }

    public function purchaseId(): int
    {
        return $this->purchaseId;
    }

    public function handle(WarzoneShopClient $client): void
    {
        $purchase = WarzoneSupplierPurchase::query()->find($this->purchaseId);
        if (!$purchase || $purchase->isTerminal()) {
            return;
        }

        $lock = Cache::lock('warzone:purchase-account', 90);
        if (!$lock->get()) {
            $this->release(5);
            return;
        }

        try {
            $purchase = WarzoneSupplierPurchase::query()->find($this->purchaseId);
            if (!$purchase || $purchase->isTerminal()) {
                return;
            }
            if ((string) $purchase->status === WarzoneSupplierPurchase::STATUS_STOCKED) {
                $this->finishLocalFulfillment($purchase);
                return;
            }
            // A worker that died after changing this state may have sent POST.
            // The provider has no idempotency key, so a second POST is unsafe.
            if ((string) $purchase->status === WarzoneSupplierPurchase::STATUS_PURCHASING) {
                $this->markAmbiguous(
                    $purchase,
                    '上一轮供应商采购在请求发出后中断，结果需要人工核对。'
                );
                return;
            }

            $order = Order::query()->find($purchase->order_id);
            $setting = WarzoneSupplierSetting::query()->find($purchase->setting_id);
            if (!$order || !$setting || !$setting->isReady()) {
                $this->markFailed($purchase, '供应商配置不可用，请检查后重试发货。');
                return;
            }
            if ((int) $order->status === Order::STATUS_COMPLETED) {
                $purchase->status = WarzoneSupplierPurchase::STATUS_COMPLETED;
                $purchase->completed_at = now();
                $purchase->last_error = null;
                $purchase->save();
                return;
            }

            $localStock = $this->localStock((int) $purchase->goods_id);
            $missing = max(0, (int) $order->buy_amount - $localStock);
            if ($missing === 0) {
                $purchase->status = WarzoneSupplierPurchase::STATUS_STOCKED;
                $purchase->quantity = 0;
                $purchase->stocked_at = now();
                $purchase->last_error = null;
                $purchase->save();
                $this->finishLocalFulfillment($purchase);
                return;
            }

            try {
                $snapshot = $client->snapshot($setting);
            } catch (WarzoneApiException $exception) {
                $this->handlePrePurchaseFailure($purchase, $exception);
                return;
            }
            $service = (array) ($snapshot['service'] ?? []);
            $setting->recordSnapshot((string) $snapshot['balance_usd'], $service)->save();
            $effectiveUnitCost = $this->effectiveUnitCost($setting, $service);
            $estimatedCost = bcmul($effectiveUnitCost, (string) $missing, 4);
            if (empty($service['orderable'])
                || (int) ($service['stock'] ?? 0) < $missing
                || bccomp((string) $snapshot['balance_usd'], $estimatedCost, 4) < 0) {
                $this->markFailed($purchase, '供应商库存或账户余额不足，付款已保留，请补充后重试发货。');
                return;
            }

            DB::transaction(function () use ($missing, $effectiveUnitCost, $estimatedCost) {
                $purchase = WarzoneSupplierPurchase::query()
                    ->whereKey($this->purchaseId)
                    ->lockForUpdate()
                    ->firstOrFail();
                if ((string) $purchase->status !== WarzoneSupplierPurchase::STATUS_QUEUED) {
                    return;
                }
                $purchase->quantity = $missing;
                $purchase->unit_cost_usd = $effectiveUnitCost;
                $purchase->total_cost_usd = $estimatedCost;
                $purchase->status = WarzoneSupplierPurchase::STATUS_PURCHASING;
                $purchase->attempt_count = (int) $purchase->attempt_count + 1;
                $purchase->started_at = now();
                $purchase->last_error = null;
                $purchase->save();
            });

            $purchase = WarzoneSupplierPurchase::query()->findOrFail($this->purchaseId);
            if ((string) $purchase->status !== WarzoneSupplierPurchase::STATUS_PURCHASING) {
                return;
            }

            try {
                $result = $client->order($setting, $missing);
            } catch (WarzoneApiException $exception) {
                $this->handlePostFailure($purchase, $exception);
                return;
            } catch (Throwable $exception) {
                $this->markAmbiguous($purchase, '供应商采购响应未确认，需要人工核对。');
                return;
            }
            $setting->recordSnapshot((string) $result['new_balance'], $service)->save();
            app(WarzoneInventoryService::class)->invalidate($setting);

            try {
                $this->storePurchasedProducts($purchase, $result);
            } catch (Throwable $exception) {
                Log::error('Warzone purchase succeeded but local stock persistence failed.', [
                    'purchase_id' => $purchase->getKey(),
                    'provider_order_id' => $result['order_id'] ?? null,
                    'exception' => get_class($exception),
                ]);
                $this->markAmbiguous(
                    $purchase,
                    '供应商已返回订单，但本地写入失败，需要按供应商订单人工核对。',
                    $result
                );
                return;
            }

            $this->finishLocalFulfillment(
                WarzoneSupplierPurchase::query()->findOrFail($this->purchaseId)
            );
        } finally {
            $lock->release();
        }
    }

    private function localStock(int $goodsId): int
    {
        return (int) Carmis::query()
            ->where('goods_id', $goodsId)
            ->where('status', Carmis::STATUS_UNSOLD)
            ->count();
    }

    private function effectiveUnitCost(WarzoneSupplierSetting $setting, array $service): string
    {
        $configured = (string) $setting->unit_cost_usd;
        $provider = isset($service['price']) && is_numeric($service['price'])
            ? (string) $service['price']
            : '0';

        return bccomp($provider, $configured, 4) > 0 ? $provider : $configured;
    }

    private function handlePrePurchaseFailure(
        WarzoneSupplierPurchase $purchase,
        WarzoneApiException $exception
    ): void {
        if ($exception->isRetryable()) {
            $purchase->last_error = mb_substr($exception->getMessage(), 0, 1000);
            $purchase->save();
            $this->release(10);
            return;
        }

        $this->markFailed($purchase, $exception->getMessage());
    }

    private function handlePostFailure(
        WarzoneSupplierPurchase $purchase,
        WarzoneApiException $exception
    ): void {
        if ($exception->isAmbiguous()) {
            $this->markAmbiguous($purchase, $exception->getMessage());
            return;
        }
        if ($exception->isRetryable()) {
            $purchase->status = WarzoneSupplierPurchase::STATUS_QUEUED;
            $purchase->last_error = mb_substr($exception->getMessage(), 0, 1000);
            $purchase->save();
            $this->release(10);
            return;
        }

        $this->markFailed($purchase, $exception->getMessage());
    }

    private function storePurchasedProducts(WarzoneSupplierPurchase $purchase, array $result): void
    {
        DB::transaction(function () use ($purchase, $result) {
            $locked = WarzoneSupplierPurchase::query()
                ->whereKey($purchase->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ((string) $locked->status === WarzoneSupplierPurchase::STATUS_STOCKED
                || (string) $locked->status === WarzoneSupplierPurchase::STATUS_COMPLETED) {
                return;
            }

            $products = array_values((array) ($result['products'] ?? []));
            if (count($products) !== (int) $locked->quantity) {
                throw new \RuntimeException('Supplier product count does not match the purchase.');
            }

            $locked->provider_order_id = (string) $result['order_id'];
            $locked->unit_cost_usd = (string) $result['unit_price'];
            $locked->total_cost_usd = (string) $result['total_cost'];
            $locked->setProducts($products);
            $locked->status = WarzoneSupplierPurchase::STATUS_STOCKED;
            $locked->stocked_at = now();
            $locked->last_error = null;
            $locked->save();

            foreach ($products as $product) {
                $exists = DB::table('carmis')
                    ->where('goods_id', $locked->goods_id)
                    ->where('carmi', (string) $product)
                    ->exists();
                if (!$exists) {
                    DB::table('carmis')->insert([
                        'goods_id' => $locked->goods_id,
                        'carmi' => (string) $product,
                        'status' => Carmis::STATUS_UNSOLD,
                        'is_loop' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    private function finishLocalFulfillment(WarzoneSupplierPurchase $purchase): void
    {
        $order = Order::query()->find($purchase->order_id);
        if (!$order) {
            $this->markFailed($purchase, '商城订单不存在，供应商卡密已保存在采购记录中。');
            return;
        }
        if ((int) $order->status === Order::STATUS_COMPLETED) {
            $purchase->status = WarzoneSupplierPurchase::STATUS_COMPLETED;
            $purchase->completed_at = now();
            $purchase->last_error = null;
            $purchase->save();
            return;
        }

        try {
            $completed = (new OrderProcessService())->completedOrder(
                $order->order_sn,
                (float) $order->actual_price,
                (string) $order->trade_no,
                false,
                true
            );
        } catch (RuleValidationException $exception) {
            $this->markFailed($purchase, '供应商卡密已入库，但商城发货失败：'.$exception->getMessage());
            return;
        }

        if ((int) $completed->status !== Order::STATUS_COMPLETED) {
            $this->markFailed($purchase, '供应商卡密已入库，但商城订单仍未完成。');
            return;
        }

        $purchase->status = WarzoneSupplierPurchase::STATUS_COMPLETED;
        $purchase->completed_at = now();
        $purchase->last_error = null;
        $purchase->save();
    }

    private function markAmbiguous(
        WarzoneSupplierPurchase $purchase,
        string $message,
        array $result = []
    ): void {
        $purchase = WarzoneSupplierPurchase::query()->find($purchase->getKey()) ?: $purchase;
        if (!empty($result['order_id'])) {
            $purchase->provider_order_id = (string) $result['order_id'];
        }
        if (!empty($result['products']) && is_array($result['products'])) {
            $purchase->setProducts($result['products']);
        }
        $purchase->status = WarzoneSupplierPurchase::STATUS_AMBIGUOUS;
        $purchase->last_error = mb_substr($message, 0, 1000);
        $purchase->save();
        $setting = WarzoneSupplierSetting::query()->find($purchase->setting_id);
        if ($setting) {
            app(WarzoneInventoryService::class)->invalidate($setting);
        }
        $this->markOrderAbnormal($purchase, '支付已确认，供应商采购结果待核对，请勿重复付款。');
    }

    private function markFailed(WarzoneSupplierPurchase $purchase, string $message): void
    {
        $purchase = WarzoneSupplierPurchase::query()->find($purchase->getKey()) ?: $purchase;
        $purchase->status = WarzoneSupplierPurchase::STATUS_FAILED;
        $purchase->last_error = mb_substr($message, 0, 1000);
        $purchase->save();
        $this->markOrderAbnormal($purchase, '支付已确认，供应商自动发货失败，请管理员处理。');
    }

    private function markOrderAbnormal(WarzoneSupplierPurchase $purchase, string $summary): void
    {
        DB::transaction(function () use ($purchase, $summary) {
            $order = Order::query()
                ->whereKey($purchase->order_id)
                ->lockForUpdate()
                ->first();
            if (!$order || (int) $order->status === Order::STATUS_COMPLETED) {
                return;
            }
            $order->status = Order::STATUS_ABNORMAL;
            $order->info = $summary.PHP_EOL.'失败原因：'.(string) $purchase->last_error;
            $order->save();
        });
    }

    public function failed(Throwable $exception): void
    {
        $purchase = WarzoneSupplierPurchase::query()->find($this->purchaseId);
        if (!$purchase || $purchase->isTerminal()) {
            return;
        }
        $this->markFailed($purchase, '供应商自动发货重试次数已耗尽。');
    }
}
