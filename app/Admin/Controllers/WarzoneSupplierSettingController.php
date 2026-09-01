<?php

namespace App\Admin\Controllers;

use App\Admin\Forms\WarzoneSupplierSettingForm;
use App\Models\Carmis;
use App\Models\Goods;
use App\Models\WarzoneSupplierPurchase;
use App\Models\WarzoneSupplierSetting;
use App\Service\WarzoneInventoryService;
use App\Service\WarzoneShopClient;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Widgets\Card;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarzoneSupplierSettingController extends AdminController
{
    public function index(Content $content)
    {
        $inventory = app(WarzoneInventoryService::class);
        $goods = $this->resolveGoods((int) request('goods_id', 0));
        if (!$goods) {
            return $content
                ->title(admin_trans('warzone-supplier.title'))
                ->description(admin_trans('warzone-supplier.description'))
                ->body(new Card(
                    '<div class="alert alert-warning mb-0">'
                    . e(admin_trans('warzone-supplier.errors.no_automatic_goods'))
                    . '</div>'
                ));
        }

        $setting = WarzoneSupplierSetting::query()->firstOrNew(
            ['goods_id' => (int) $goods->id],
            ['service_id' => 'S_01', 'unit_cost_usd' => '0.4000', 'enabled' => false]
        );
        $localStock = $inventory->localStock($goods);
        $supplierAvailableStock = $inventory->supplierStock($goods);
        if ($setting->exists) {
            $setting->refresh();
        }
        $pendingQuantity = $setting->exists
            ? (int) WarzoneSupplierPurchase::query()
                ->where('setting_id', $setting->getKey())
                ->whereIn('status', [
                    WarzoneSupplierPurchase::STATUS_QUEUED,
                    WarzoneSupplierPurchase::STATUS_PURCHASING,
                ])
                ->sum('quantity')
            : 0;
        $status = $this->buildStatus(
            $setting,
            $localStock,
            $supplierAvailableStock,
            $pendingQuantity
        );
        $purchases = WarzoneSupplierPurchase::query()
            ->where('goods_id', $goods->id)
            ->orderByDesc('id')
            ->limit(10)
            ->get([
                'order_sn', 'quantity', 'status', 'provider_order_id',
                'last_error', 'created_at', 'updated_at',
            ]);
        $goodsOptions = Goods::query()
            ->where('type', Goods::AUTOMATIC_DELIVERY)
            ->orderBy('id')
            ->get(['id', 'gd_name'])
            ->mapWithKeys(function (Goods $item) {
                return [(int) $item->id => '#' . (int) $item->id . ' ' . $item->gd_name];
            })
            ->all();

        $form = new WarzoneSupplierSettingForm([
            'goods_id' => (int) $goods->id,
            'api_key' => '',
            'service_id' => (string) $setting->service_id,
            'unit_cost_usd' => (string) $setting->unit_cost_usd,
            'enabled' => (int) $setting->enabled,
        ], (int) $goods->id);
        $form->withStatusContext(array_merge($status, [
            'goods' => $goods,
            'goodsOptions' => $goodsOptions,
            'setting' => $setting,
            'purchases' => $purchases,
        ]));

        return $content
            ->title(admin_trans('warzone-supplier.title'))
            ->description(admin_trans('warzone-supplier.description'))
            ->body(new Card($form));
    }

    public function test(Request $request, WarzoneShopClient $client): JsonResponse
    {
        $goodsId = filter_var($request->input('goods_id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $setting = $goodsId
            ? WarzoneSupplierSetting::query()->where('goods_id', $goodsId)->first()
            : null;
        if (!$setting || !$setting->hasApiKey() || trim((string) $setting->service_id) === '') {
            return response()->json([
                'ok' => false,
                'message' => admin_trans('warzone-supplier.errors.save_before_test'),
            ], 422);
        }

        try {
            $snapshot = $client->snapshot($setting);
            $service = is_array($snapshot['service'] ?? null) ? $snapshot['service'] : [];
            $serviceId = (string) ($service['service_id'] ?? $service['id'] ?? '');
            if ($serviceId === '' || !hash_equals((string) $setting->service_id, $serviceId)) {
                throw new \RuntimeException(admin_trans('warzone-supplier.errors.service_not_found'));
            }

            $balance = $snapshot['balance_usd'] ?? null;
            if (!is_numeric($balance)) {
                throw new \RuntimeException(admin_trans('warzone-supplier.messages.test_failed'));
            }
            $setting->recordSnapshot((string) $balance, $service)
                ->markConnectionTest(true)
                ->save();

            $localStock = Carmis::query()
                ->where('goods_id', $goodsId)
                ->where('status', Carmis::STATUS_UNSOLD)
                ->count();
            $status = $this->buildStatus($setting, $localStock);

            return response()->json([
                'ok' => true,
                'message' => admin_trans('warzone-supplier.messages.test_ok'),
                'balance' => $status['balance'],
                'external_stock' => $status['externalStock'],
                'display_stock' => $status['displayStock'],
            ]);
        } catch (\Throwable $exception) {
            $message = mb_substr(trim($exception->getMessage()), 0, 300);
            if ($message === '') {
                $message = admin_trans('warzone-supplier.messages.test_failed');
            }
            $setting->enabled = false;
            $setting->markConnectionTest(false, $message)->save();

            return response()->json(['ok' => false, 'message' => $message], 422);
        }
    }

    private function resolveGoods(int $requestedId): ?Goods
    {
        $query = Goods::query()->where('type', Goods::AUTOMATIC_DELIVERY);
        if ($requestedId > 0) {
            return (clone $query)->whereKey($requestedId)->first();
        }

        $configuredGoodsId = WarzoneSupplierSetting::query()->orderBy('id')->value('goods_id');
        if ($configuredGoodsId) {
            $configured = (clone $query)->whereKey($configuredGoodsId)->first();
            if ($configured) {
                return $configured;
            }
        }

        return (clone $query)->whereKey(16)->first() ?: $query->orderBy('id')->first();
    }

    private function buildStatus(
        WarzoneSupplierSetting $setting,
        int $localStock,
        int $supplierAvailableStock = null,
        int $pendingQuantity = 0
    ): array
    {
        $balance = $setting->last_balance_usd === null ? null : (string) $setting->last_balance_usd;
        $unitCost = (string) ($setting->unit_cost_usd ?: '0');
        $effectiveCost = $unitCost;
        if ($setting->last_product_price_usd !== null
            && is_numeric($setting->last_product_price_usd)
            && (!is_numeric($effectiveCost)
                || bccomp((string) $setting->last_product_price_usd, $effectiveCost, 4) > 0)) {
            $effectiveCost = (string) $setting->last_product_price_usd;
        }
        $balanceCapacity = null;
        if ($balance !== null
            && is_numeric($balance)
            && is_numeric($effectiveCost)
            && bccomp($effectiveCost, '0', 4) > 0) {
            $balanceCapacity = (int) bcdiv($balance, $effectiveCost, 0);
        }
        $supplierStock = $setting->last_supplier_stock === null
            ? null
            : max(0, (int) $setting->last_supplier_stock);
        $supplierOrderable = $setting->getAttribute('last_supplier_orderable');
        $externalStock = $supplierAvailableStock === null
            ? max(0, (int) ($balanceCapacity ?: 0), (int) ($supplierStock ?: 0))
            : max(0, $supplierAvailableStock);
        $priceMismatch = $setting->last_product_price_usd !== null
            && is_numeric($setting->last_product_price_usd)
            && is_numeric($unitCost)
            && bccomp((string) $setting->last_product_price_usd, $unitCost, 4) !== 0;

        return [
            'localStock' => $localStock,
            'balance' => $balance,
            'effectiveCost' => $effectiveCost,
            'balanceCapacity' => $balanceCapacity,
            'supplierStock' => $supplierStock,
            'supplierOrderable' => $supplierOrderable,
            'pendingQuantity' => max(0, $pendingQuantity),
            'externalStock' => $externalStock,
            'displayStock' => $localStock + $externalStock,
            'priceMismatch' => $priceMismatch,
        ];
    }
}
