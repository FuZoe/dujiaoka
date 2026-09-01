@php
    $hasApiKey = $setting->hasApiKey();
    $connectionTested = $setting->hasSuccessfulConnectionTest();
    $configuredPrice = number_format((float) $setting->unit_cost_usd, 4, '.', '');
    $supplierPrice = $setting->last_product_price_usd === null
        ? null
        : number_format((float) $setting->last_product_price_usd, 4, '.', '');
@endphp

<div class="mb-3">
    <label for="warzone-goods-selector" class="font-weight-bold">
        {{ admin_trans('warzone-supplier.fields.goods') }}
    </label>
    <select id="warzone-goods-selector" class="form-control" style="max-width: 520px">
        @foreach($goodsOptions as $id => $label)
            <option value="{{ $id }}" {{ (int) $id === (int) $goods->id ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
    </select>
</div>

<div class="alert {{ $setting->enabled && $connectionTested ? 'alert-success' : 'alert-warning' }} mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center">
        <strong>
            {{ $setting->enabled
                ? admin_trans('warzone-supplier.status.enabled')
                : admin_trans('warzone-supplier.status.disabled') }}
        </strong>
        <span>
            {{ admin_trans('warzone-supplier.status.api_key') }}：
            {{ $hasApiKey ? admin_trans('warzone-supplier.status.saved') : admin_trans('warzone-supplier.status.missing') }}；
            {{ admin_trans('warzone-supplier.status.connection') }}：
            {{ $connectionTested ? admin_trans('warzone-supplier.status.passed') : admin_trans('warzone-supplier.status.not_tested') }}
        </span>
    </div>
</div>

<div class="table-responsive mb-3">
    <table class="table table-bordered mb-0">
        <tbody>
        <tr>
            <th style="width: 22%">{{ admin_trans('warzone-supplier.status.local_stock') }}</th>
            <td>{{ $localStock }}</td>
            <th style="width: 22%">{{ admin_trans('warzone-supplier.status.balance') }}</th>
            <td>{{ $balance === null ? '-' : '$' . number_format((float) $balance, 4, '.', '') }}</td>
        </tr>
        <tr>
            <th>{{ admin_trans('warzone-supplier.status.configured_unit_cost') }}</th>
            <td>${{ $configuredPrice }}</td>
            <th>{{ admin_trans('warzone-supplier.status.api_unit_price') }}</th>
            <td>{{ $supplierPrice === null ? '-' : '$' . $supplierPrice }}</td>
        </tr>
        <tr>
            <th>{{ admin_trans('warzone-supplier.status.effective_unit_cost') }}</th>
            <td>${{ number_format((float) $effectiveCost, 4, '.', '') }}</td>
            <th>{{ admin_trans('warzone-supplier.status.balance_capacity') }}</th>
            <td>{{ $balanceCapacity === null ? '-' : $balanceCapacity }}</td>
        </tr>
        <tr>
            <th>{{ admin_trans('warzone-supplier.status.supplier_stock') }}</th>
            <td>{{ $supplierStock === null ? '-' : $supplierStock }}</td>
            <th>{{ admin_trans('warzone-supplier.status.pending_quantity') }}</th>
            <td>{{ $pendingQuantity }}</td>
        </tr>
        <tr>
            <th>{{ admin_trans('warzone-supplier.status.supplier_orderable') }}</th>
            <td>
                {{ $supplierOrderable === null
                    ? '-'
                    : ($supplierOrderable
                        ? admin_trans('warzone-supplier.status.orderable_yes')
                        : admin_trans('warzone-supplier.status.orderable_no')) }}
            </td>
            <th>{{ admin_trans('warzone-supplier.status.external_stock') }}</th>
            <td>{{ $externalStock }}</td>
        </tr>
        <tr>
            <th>{{ admin_trans('warzone-supplier.status.local_stock') }}</th>
            <td>{{ $localStock }}</td>
            <th>{{ admin_trans('warzone-supplier.status.display_stock') }}</th>
            <td><strong>{{ $displayStock }}</strong></td>
        </tr>
        </tbody>
    </table>
</div>

@if($priceMismatch)
    <div class="alert alert-danger">
        <strong>{{ admin_trans('warzone-supplier.status.price_warning_title') }}</strong>
        {{ admin_trans('warzone-supplier.status.price_warning', [
            'configured' => $configuredPrice,
            'supplier' => $supplierPrice,
        ]) }}
    </div>
@endif

@if($setting->last_snapshot_at)
    <div class="text-muted small mb-2">
        {{ admin_trans('warzone-supplier.status.last_snapshot_at') }}：{{ $setting->last_snapshot_at }}
    </div>
@endif
@if($setting->last_error)
    <div class="alert alert-danger">
        <strong>{{ admin_trans('warzone-supplier.status.last_error') }}：</strong>
        {{ $setting->last_error }}
    </div>
@endif

<h5 class="mt-3 mb-2">{{ admin_trans('warzone-supplier.purchases.title') }}</h5>
<div class="table-responsive mb-3">
    <table class="table table-striped table-bordered mb-0">
        <thead>
        <tr>
            <th>{{ admin_trans('warzone-supplier.purchases.order_sn') }}</th>
            <th>{{ admin_trans('warzone-supplier.purchases.quantity') }}</th>
            <th>{{ admin_trans('warzone-supplier.purchases.status') }}</th>
            <th>{{ admin_trans('warzone-supplier.purchases.provider_order_id') }}</th>
            <th>{{ admin_trans('warzone-supplier.purchases.time') }}</th>
            <th>{{ admin_trans('warzone-supplier.purchases.error') }}</th>
        </tr>
        </thead>
        <tbody>
        @forelse($purchases as $purchase)
            <tr>
                <td>{{ $purchase->order_sn }}</td>
                <td>{{ $purchase->quantity }}</td>
                <td>{{ admin_trans('warzone-supplier.purchase_status.' . $purchase->status) }}</td>
                <td>{{ $purchase->provider_order_id ?: '-' }}</td>
                <td>{{ $purchase->updated_at ?: $purchase->created_at }}</td>
                <td>{{ $purchase->last_error ? \Illuminate\Support\Str::limit($purchase->last_error, 120) : '-' }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="6" class="text-center text-muted">{{ admin_trans('warzone-supplier.purchases.empty') }}</td>
            </tr>
        @endforelse
        </tbody>
    </table>
</div>

<script>
Dcat.ready(function () {
    $('#warzone-goods-selector').on('change', function () {
        var url = @json(admin_url('warzone-supplier'));
        window.location.href = url + '?goods_id=' + encodeURIComponent(this.value);
    });
});
</script>
