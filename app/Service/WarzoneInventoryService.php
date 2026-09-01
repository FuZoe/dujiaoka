<?php

namespace App\Service;

use App\Models\Carmis;
use App\Models\Goods;
use App\Models\WarzoneSupplierPurchase;
use App\Models\WarzoneSupplierSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class WarzoneInventoryService
{
    private const CACHE_SECONDS = 45;

    /** @var WarzoneShopClient */
    private $client;

    public function __construct(WarzoneShopClient $client)
    {
        $this->client = $client;
    }

    /**
     * Attach local and estimated supplier stock while preserving the legacy
     * in_stock/carmis_count contract consumed by every storefront.
     */
    public function augment(Goods $goods): Goods
    {
        $localStock = $this->localStock($goods);
        $supplierStock = $this->supplierStock($goods);
        $availableStock = $localStock + $supplierStock;

        $goods->setAttribute('local_in_stock', $localStock);
        $goods->setAttribute('supplier_in_stock', $supplierStock);
        if ((int) $goods->getAttribute('type') === Goods::AUTOMATIC_DELIVERY) {
            $goods->setAttribute('carmis_count', $availableStock);
        } else {
            $goods->setAttribute('in_stock', $availableStock);
        }

        return $goods;
    }

    public function availableStock(Goods $goods, bool $refreshSupplier = true): int
    {
        return $this->localStock($goods) + $this->supplierStock($goods, $refreshSupplier);
    }

    public function localStock(Goods $goods): int
    {
        if ((int) $goods->getAttribute('type') !== Goods::AUTOMATIC_DELIVERY) {
            return max(0, (int) $goods->getAttribute('in_stock'));
        }

        $attributes = $goods->getAttributes();
        if (array_key_exists('local_in_stock', $attributes)) {
            return max(0, (int) $attributes['local_in_stock']);
        }
        if (array_key_exists('carmis_count', $attributes)) {
            return max(0, (int) $attributes['carmis_count']);
        }

        return (int) Carmis::query()
            ->where('goods_id', $goods->getKey())
            ->where('status', Carmis::STATUS_UNSOLD)
            ->count();
    }

    public function supplierStock(Goods $goods, bool $refresh = true): int
    {
        try {
            $setting = WarzoneSupplierSetting::query()
                ->where('goods_id', (int) $goods->getKey())
                ->first();
            if (!$setting || !$setting->isReady()) {
                return 0;
            }

            $cost = (string) $setting->unit_cost_usd;
            if (!is_numeric($cost) || bccomp($cost, '0', 8) <= 0) {
                return 0;
            }

            if ($refresh) {
                $cacheKey = $this->cacheKey($setting);
                $snapshot = Cache::remember($cacheKey, self::CACHE_SECONDS, function () use ($setting) {
                    $snapshot = $this->client->snapshot($setting);
                    $setting->recordSnapshot(
                        (string) $snapshot['balance_usd'],
                        (array) $snapshot['service']
                    )->save();

                    return $snapshot;
                });
            } else {
                // Payment settlement and stock-alert callbacks can run inside
                // an order transaction. Use the last persisted snapshot there
                // so a supplier outage cannot hold database locks.
                if ($setting->last_balance_usd === null
                    || $setting->last_supplier_stock === null
                    || $setting->last_supplier_orderable === null) {
                    return 0;
                }
                $snapshot = [
                    'balance_usd' => (string) $setting->last_balance_usd,
                    'service' => [
                        'service_id' => (string) $setting->service_id,
                        'stock' => (int) $setting->last_supplier_stock,
                        'orderable' => (bool) $setting->last_supplier_orderable,
                        'price' => $setting->last_product_price_usd === null
                            ? null
                            : (string) $setting->last_product_price_usd,
                    ],
                ];
            }

            $balance = $snapshot['balance_usd'] ?? null;
            $service = $snapshot['service'] ?? null;
            if (!is_numeric($balance)
                || !is_array($service)
                || (string) ($service['service_id'] ?? '') !== (string) $setting->service_id) {
                return 0;
            }

            // Storefront stock uses the larger of the two configured
            // estimates. If the supplier currently reports no stock but
            // the account has enough balance to buy units, show that balance
            // capacity; if the balance is empty, retain the supplier stock.
            // The purchase job still checks orderable before placing an order.
            $remoteStock = max(0, (int) ($service['stock'] ?? 0));
            $providerCost = isset($service['price']) && is_numeric($service['price'])
                ? (string) $service['price']
                : '0';
            $effectiveCost = bccomp($providerCost, $cost, 8) > 0 ? $providerCost : $cost;
            [$reservedQuantity, $reservedCost] = $this->pendingReservations($setting, $effectiveCost);
            $availableBalance = bcsub((string) $balance, $reservedCost, 8);
            if (bccomp($availableBalance, '0', 8) < 0) {
                $availableBalance = '0';
            }
            $remoteStock = max(0, $remoteStock - $reservedQuantity);
            $affordable = (int) bcdiv($availableBalance, $effectiveCost, 0);

            return max(0, $remoteStock, $affordable);
        } catch (Throwable $exception) {
            Log::warning('Warzone supplier inventory could not be refreshed.', [
                'goods_id' => $goods->getKey(),
                'exception' => get_class($exception),
            ]);

            return 0;
        }
    }

    public function invalidate(WarzoneSupplierSetting $setting): void
    {
        Cache::forget($this->cacheKey($setting));
    }

    private function cacheKey(WarzoneSupplierSetting $setting): string
    {
        return 'warzone:inventory:'.sha1(
            (string) $setting->getKey().'|'.
            (string) $setting->service_id.'|'.
            (string) $setting->unit_cost_usd.'|'.
            $setting->credentialFingerprint()
        );
    }

    private function pendingReservations(
        WarzoneSupplierSetting $setting,
        string $fallbackUnitCost
    ): array {
        $quantity = 0;
        $cost = '0';
        $purchases = WarzoneSupplierPurchase::query()
            ->where('setting_id', $setting->getKey())
            ->whereIn('status', [
                WarzoneSupplierPurchase::STATUS_QUEUED,
                WarzoneSupplierPurchase::STATUS_PURCHASING,
            ])
            ->get(['quantity', 'unit_cost_usd', 'total_cost_usd']);

        foreach ($purchases as $purchase) {
            $reservedQuantity = max(0, (int) $purchase->quantity);
            $quantity += $reservedQuantity;
            if (is_numeric($purchase->total_cost_usd)
                && bccomp((string) $purchase->total_cost_usd, '0', 8) > 0) {
                $cost = bcadd($cost, (string) $purchase->total_cost_usd, 8);
                continue;
            }
            $unitCost = is_numeric($purchase->unit_cost_usd)
                && bccomp((string) $purchase->unit_cost_usd, '0', 8) > 0
                ? (string) $purchase->unit_cost_usd
                : $fallbackUnitCost;
            $cost = bcadd($cost, bcmul((string) $reservedQuantity, $unitCost, 8), 8);
        }

        return [$quantity, $cost];
    }
}
