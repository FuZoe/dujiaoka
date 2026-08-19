@extends('unicorn.layouts.default')

@section('content')
<main class="store-main order-page">
    <div class="store-shell narrow-shell">
        <div class="order-step"><span>1</span><i></i><span class="active">2</span><i></i><span>3</span></div>
        <section class="order-sheet">
            <div class="order-sheet-head">
                <div><div class="eyebrow">订单已创建</div><h1>确认并支付</h1></div>
                <div class="amount-due"><span>应付金额</span><strong>¥{{ $actual_price }}</strong></div>
            </div>
            <dl class="order-details">
                <div><dt>订单号</dt><dd class="mono">{{ $order_sn }}</dd></div>
                <div><dt>商品</dt><dd>{{ $title }}</dd></div>
                <div><dt>数量</dt><dd>{{ $buy_amount }}</dd></div>
                <div><dt>接收邮箱</dt><dd>{{ $email }}</dd></div>
                <div><dt>发货方式</dt><dd>{{ $type == \App\Models\Order::AUTOMATIC_DELIVERY ? '自动发货' : '人工处理' }}</dd></div>
                <div><dt>支付方式</dt><dd>{{ $pay['pay_name'] }}</dd></div>
                <div><dt>创建时间</dt><dd>{{ $created_at }}</dd></div>
                @if(!empty($info))<div><dt>订单信息</dt><dd>{{ $info }}</dd></div>@endif
            </dl>
            @if(!empty($coupon) || $wholesale_discount_price > 0)
                <div class="discount-summary">
                    @if(!empty($coupon))<span>优惠码已减 ¥{{ $coupon_discount_price }}</span>@endif
                    @if($wholesale_discount_price > 0)<span>批量优惠已减 ¥{{ $wholesale_discount_price }}</span>@endif
                </div>
            @endif
            @php
                $billPayCheck = strtolower((string) ($pay['pay_check'] ?? ''));
                $billIsAlipay = strpos($billPayCheck, 'ali') === 0 || strpos($billPayCheck, 'zfb') !== false;
                $billIsWechat = strpos($billPayCheck, 'wx') === 0 || strpos($billPayCheck, 'wechat') !== false;
                $billPayLabel = $billPayCheck === 'binancepay'
                    ? '前往币安支付'
                    : ($billIsAlipay ? '前往支付宝支付' : ($billIsWechat ? '前往微信支付' : '前往支付'));
            @endphp
            <a class="primary-action" href="{{ url('pay-gateway', ['handle' => urlencode($pay['pay_handleroute']), 'payway' => $pay['pay_check'], 'orderSN' => $order_sn]) }}">
                {{ $billPayLabel }} <span aria-hidden="true">&#8594;</span>
            </a>
            <p class="checkout-note">支付链接与本订单绑定，请核对金额后完成付款</p>
        </section>
    </div>
</main>
@stop
