@php
    $hasCredentials = $setting->getApiKey() !== '' && $setting->getApiSecret() !== '';
    $connectionTested = $setting->hasSuccessfulConnectionTest();
    $hasReceiverId = $setting->hasReceiverId();
    $hasQr = $setting->hasOfficialReceiveUrl();
@endphp
<div class="alert {{ $setting->enabled ? 'alert-success' : 'alert-warning' }} mb-2">
    <strong>{{ $setting->enabled ? admin_trans('pay.binance.status.enabled') : admin_trans('pay.binance.status.disabled') }}</strong>
    <div class="mt-1">
        {{ admin_trans('pay.binance.status.credentials') }}：{{ $hasCredentials ? admin_trans('pay.binance.status.ready') : admin_trans('pay.binance.status.missing') }}；
        {{ admin_trans('pay.binance.status.connection_test') }}：{{ $connectionTested ? admin_trans('pay.binance.status.ready') : admin_trans('pay.binance.status.missing') }}；
        {{ admin_trans('pay.binance.status.receiver_id') }}：{{ $hasReceiverId ? admin_trans('pay.binance.status.ready') : admin_trans('pay.binance.status.missing') }}；
        {{ admin_trans('pay.binance.status.qr') }}：{{ $hasQr ? admin_trans('pay.binance.status.ready') : admin_trans('pay.binance.status.missing') }}；
        {{ admin_trans('pay.binance.status.proxy') }}：{{ config('services.binance_pay.proxy') ? admin_trans('pay.binance.status.ready') : admin_trans('pay.binance.status.direct') }}
    </div>
    @if($setting->last_polled_at)
        <div class="mt-1">{{ admin_trans('pay.binance.status.last_polled_at') }}：{{ $setting->last_polled_at }}</div>
    @endif
    @if($setting->last_tested_at)
        <div class="mt-1">{{ admin_trans('pay.binance.status.last_tested_at') }}：{{ $setting->last_tested_at }}</div>
    @endif
    @if($setting->last_error)
        <div class="mt-1 text-danger">{{ admin_trans('pay.binance.status.last_error') }}：{{ $setting->last_error }}</div>
    @endif
</div>
