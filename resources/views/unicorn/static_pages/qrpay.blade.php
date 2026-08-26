@extends('unicorn.layouts.default')

@section('content')
<main class="store-main order-page">
    <div class="store-shell narrow-shell">
        <section class="order-sheet qr-sheet">
            <div class="eyebrow">{{ shop_payment_label($payname) }}</div>
            <h1>{{ __('store.qr.title') }}</h1>
            <p>{{ __('store.qr.expires', ['minutes' => dujiaoka_config_get('order_expire_time', 20)]) }}</p>
            <div class="qr-frame"><img src="data:image/png;base64,{!! base64_encode(QrCode::format('png')->size(240)->generate($qr_code)) !!}" alt="{{ __('store.qr.alt') }}"></div>
            <div class="amount-due centered"><span>{{ __('store.qr.amount_due') }}</span><strong>¥{{ $actual_price }}</strong></div>
            @if(Agent::isMobile() && isset($jump_payuri))
                <a href="{{ $jump_payuri }}" class="primary-action">{{ __('store.qr.open_app') }}</a>
            @endif
            <div class="payment-waiting" id="payment-waiting"><i></i>{{ __('store.qr.waiting') }}</div>
        </section>
    </div>
</main>
@stop

@section('js')
<script>
    var timer = window.setInterval(function () {
        $.getJSON('{{ shop_url('check-order-status', ['orderSN' => $orderid]) }}', function (res) {
            if (res.code === 400001) {
                window.clearInterval(timer);
                document.getElementById('payment-waiting').textContent = @json(__('store.qr.expired'));
            }
            if (res.code === 200) {
                window.clearInterval(timer);
                document.getElementById('payment-waiting').textContent = @json(__('store.qr.paid'));
                window.setTimeout(function () { window.location.href = '{{ shop_url('detail-order-sn', ['orderSN' => $orderid]) }}'; }, 1200);
            }
        });
    }, 3000);
</script>
@stop
