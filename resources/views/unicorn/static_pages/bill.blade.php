@extends('unicorn.layouts.default')

@section('content')
<main class="store-main order-page">
    <div class="store-shell narrow-shell">
        <div class="order-step"><span>1</span><i></i><span class="active">2</span><i></i><span>3</span></div>
        <section class="order-sheet">
            <div class="order-sheet-head">
                <div><div class="eyebrow">{{ __('store.bill.created') }}</div><h1>{{ __('store.bill.confirm_pay') }}</h1></div>
                <div class="amount-due"><span>{{ __('store.bill.amount_due') }}</span><strong>¥{{ $actual_price }}</strong></div>
            </div>
            <dl class="order-details">
                <div><dt>{{ __('store.bill.order_no') }}</dt><dd class="mono">{{ $order_sn }}</dd></div>
                <div><dt>{{ __('store.bill.product') }}</dt><dd>{{ $title }}</dd></div>
                <div><dt>{{ __('store.bill.quantity') }}</dt><dd>{{ $buy_amount }}</dd></div>
                <div><dt>{{ __('store.bill.email') }}</dt><dd>{{ $email }}</dd></div>
                <div><dt>{{ __('store.bill.delivery') }}</dt><dd>{{ $type == \App\Models\Order::AUTOMATIC_DELIVERY ? __('store.bill.automatic') : __('store.bill.manual') }}</dd></div>
                <div><dt>{{ __('store.bill.payment') }}</dt><dd>{{ $pay['pay_name'] }}</dd></div>
                <div><dt>{{ __('store.bill.created_at') }}</dt><dd>{{ $created_at }}</dd></div>
                @if(!empty($info))<div><dt>{{ __('store.bill.order_info') }}</dt><dd>{{ $info }}</dd></div>@endif
            </dl>
            @if(!empty($coupon) || $wholesale_discount_price > 0)
                <div class="discount-summary">
                    @if(!empty($coupon))<span>{{ __('store.bill.coupon_discount', ['amount' => $coupon_discount_price]) }}</span>@endif
                    @if($wholesale_discount_price > 0)<span>{{ __('store.bill.wholesale_discount', ['amount' => $wholesale_discount_price]) }}</span>@endif
                </div>
            @endif
            @php
                $billPayCheck = strtolower((string) ($pay['pay_check'] ?? ''));
                $billIsAlipay = strpos($billPayCheck, 'ali') === 0 || strpos($billPayCheck, 'zfb') !== false;
                $billIsWechat = strpos($billPayCheck, 'wx') === 0 || strpos($billPayCheck, 'wechat') !== false;
                $billPayLabel = $billPayCheck === 'binancepay'
                    ? __('store.bill.pay_binance')
                    : ($billIsAlipay ? __('store.bill.pay_alipay') : ($billIsWechat ? __('store.bill.pay_wechat') : __('store.bill.pay_now')));
            @endphp
            <a class="primary-action" href="{{ shop_url('pay-gateway', ['handle' => urlencode($pay['pay_handleroute']), 'payway' => $pay['pay_check'], 'orderSN' => $order_sn]) }}">
                {{ $billPayLabel }} <span aria-hidden="true">&#8594;</span>
            </a>
            <p class="checkout-note">{{ __('store.bill.note') }}</p>
        </section>
    </div>
</main>
@stop
