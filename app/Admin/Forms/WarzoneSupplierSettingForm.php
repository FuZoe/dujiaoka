<?php

namespace App\Admin\Forms;

use App\Models\Goods;
use App\Models\WarzoneSupplierSetting;
use Dcat\Admin\Widgets\Form;
use Illuminate\Support\Facades\DB;

class WarzoneSupplierSettingForm extends Form
{
    private $statusContext = [];

    public function handle(array $input)
    {
        $goodsId = filter_var($input['goods_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $goods = $goodsId
            ? Goods::query()->whereKey($goodsId)->where('type', Goods::AUTOMATIC_DELIVERY)->first()
            : null;
        if (!$goods) {
            return $this->response()->error(admin_trans('warzone-supplier.errors.invalid_goods'));
        }

        $setting = WarzoneSupplierSetting::query()->firstOrNew(
            ['goods_id' => (int) $goods->id],
            ['service_id' => 'S_01', 'unit_cost_usd' => '0.4000', 'enabled' => false]
        );
        $apiKey = trim((string) ($input['api_key'] ?? ''));
        $serviceId = strtoupper(trim((string) ($input['service_id'] ?? '')));
        $unitCost = trim((string) ($input['unit_cost_usd'] ?? ''));
        $requestedEnabled = (int) ($input['enabled'] ?? 0) === 1;

        if (!preg_match('/^[A-Z0-9_-]{1,64}$/', $serviceId)) {
            return $this->response()->error(admin_trans('warzone-supplier.errors.invalid_service_id'));
        }
        if (!is_numeric($unitCost) || bccomp($unitCost, '0', 4) <= 0) {
            return $this->response()->error(admin_trans('warzone-supplier.errors.invalid_unit_cost'));
        }
        if ($apiKey !== '' && (strlen($apiKey) > 512 || preg_match('/\s/', $apiKey))) {
            return $this->response()->error(admin_trans('warzone-supplier.errors.invalid_api_key'));
        }

        $currentApiKey = $setting->exists ? $setting->getApiKey() : '';
        $apiKeyChanged = $apiKey !== ''
            && ($currentApiKey === '' || !hash_equals($currentApiKey, $apiKey));
        $serviceChanged = !$setting->exists
            || !hash_equals((string) $setting->service_id, $serviceId);
        $configurationChanged = $apiKeyChanged || $serviceChanged;

        if ($requestedEnabled
            && !$configurationChanged
            && (!$setting->hasApiKey() || !$setting->hasSuccessfulConnectionTest())) {
            return $this->response()->error(admin_trans('warzone-supplier.errors.test_required'));
        }

        DB::transaction(function () use (
            $setting,
            $apiKey,
            $apiKeyChanged,
            $serviceId,
            $unitCost,
            $requestedEnabled,
            $configurationChanged
        ) {
            if ($apiKeyChanged) {
                $setting->setApiKey($apiKey);
            }

            $setting->service_id = $serviceId;
            $setting->unit_cost_usd = $unitCost;
            $setting->enabled = $configurationChanged ? false : $requestedEnabled;
            if ($configurationChanged) {
                $setting->connection_test_ok = false;
                $setting->tested_credentials_hash = null;
                $setting->last_tested_at = null;
                $setting->last_balance_usd = null;
                $setting->last_supplier_stock = null;
                $setting->last_supplier_orderable = null;
                $setting->last_product_price_usd = null;
                $setting->last_snapshot_at = null;
                $setting->last_error = null;
            }
            $setting->save();
        });

        $message = $configurationChanged
            ? admin_trans('warzone-supplier.messages.configuration_saved')
            : admin_trans('warzone-supplier.messages.saved');

        return $this->response()->success($message)->refresh();
    }

    public function form()
    {
        if ($this->statusContext) {
            $this->html(view('admin.warzone-supplier.status', $this->statusContext));
        }

        $this->hidden('goods_id');
        $this->password('api_key', admin_trans('warzone-supplier.fields.api_key'))
            ->help(admin_trans('warzone-supplier.helps.api_key'));
        $this->text('service_id', admin_trans('warzone-supplier.fields.service_id'))
            ->rules('required|string|max:64')
            ->help(admin_trans('warzone-supplier.helps.service_id'));
        $this->decimal('unit_cost_usd', admin_trans('warzone-supplier.fields.unit_cost_usd'))
            ->rules('required|numeric|min:0.0001')
            ->help(admin_trans('warzone-supplier.helps.unit_cost_usd'));
        $this->switch('enabled', admin_trans('warzone-supplier.fields.enabled'))
            ->help(admin_trans('warzone-supplier.helps.enabled'));
        $this->html(view('admin.warzone-supplier.actions', [
            'goodsId' => (int) ($this->model()->goods_id ?? 0),
        ]));
        $this->confirm(
            admin_trans('warzone-supplier.confirm.title'),
            admin_trans('warzone-supplier.confirm.content')
        );
    }

    public function withStatusContext(array $context): self
    {
        $this->statusContext = $context;

        return $this;
    }
}
