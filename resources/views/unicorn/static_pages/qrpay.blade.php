@extends('unicorn.layouts.default')

@section('content')
<main class="store-main order-page">
    <div class="store-shell narrow-shell">
        <section class="order-sheet qr-sheet">
            <div class="eyebrow">{{ $payname }}</div>
            <h1>扫码完成支付</h1>
            <p>订单将在 {{ dujiaoka_config_get('order_expire_time', 5) }} 分钟后失效</p>
            <div class="qr-frame"><img src="data:image/png;base64,{!! base64_encode(QrCode::format('png')->size(240)->generate($qr_code)) !!}" alt="付款二维码"></div>
            <div class="amount-due centered"><span>应付金额</span><strong>¥{{ $actual_price }}</strong></div>
            @if(Agent::isMobile() && isset($jump_payuri))
                <a href="{{ $jump_payuri }}" class="primary-action">打开支付应用</a>
            @endif
            <div class="payment-waiting" id="payment-waiting"><i></i>正在等待支付结果</div>
        </section>
    </div>
</main>
@stop

@section('js')
<script>
    var timer = window.setInterval(function () {
        $.getJSON('{{ url('check-order-status', ['orderSN' => $orderid]) }}', function (res) {
            if (res.code === 400001) {
                window.clearInterval(timer);
                document.getElementById('payment-waiting').textContent = '订单已过期';
            }
            if (res.code === 200) {
                window.clearInterval(timer);
                document.getElementById('payment-waiting').textContent = '支付成功，正在打开订单';
                window.setTimeout(function () { window.location.href = '{{ url('detail-order-sn', ['orderSN' => $orderid]) }}'; }, 1200);
            }
        });
    }, 3000);
</script>
@stop
