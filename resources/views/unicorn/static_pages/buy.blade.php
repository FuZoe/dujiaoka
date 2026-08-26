@extends('unicorn.layouts.seo')

@section('content')
<main class="store-main checkout-page">
    <div class="store-shell checkout-shell">
        <a class="back-link" href="{{ shop_url('/') }}">&#8592; {{ __('store.buy.back') }}</a>

        <div class="checkout-layout">
            <section class="product-summary">
                <div class="summary-media">
                    <img src="{{ picture_ulr($picture) }}" alt="{{ $gd_name }}">
                    <span class="delivery-tag @if($type != \App\Models\Goods::AUTOMATIC_DELIVERY) manual @endif">
                        {{ $type == \App\Models\Goods::AUTOMATIC_DELIVERY ? __('store.buy.automatic') : __('store.buy.manual') }}
                    </span>
                </div>
                <div class="summary-copy">
                    <div class="eyebrow">{{ __('store.buy.details') }}</div>
                    <h1>{{ $gd_name }}</h1>
                    @if(!empty($gd_description))<p>{{ $gd_description }}</p>@endif
                    <div class="summary-facts">
                        <div><span>{{ __('store.buy.unit_price') }}</span><strong>¥{{ $actual_price }}</strong></div>
                        <div><span>{{ __('store.buy.stock') }}</span><strong>{{ $in_stock }}</strong></div>
                    </div>
                    @if($buy_limit_num > 0)
                        <div class="limit-note">{{ __('store.buy.purchase_limit', ['count' => $buy_limit_num]) }}</div>
                    @endif
                    @if(!empty($wholesale_price_cnf) && is_array($wholesale_price_cnf))
                        <div class="wholesale-list">
                            <strong>{{ __('store.buy.wholesale') }}</strong>
                            @foreach($wholesale_price_cnf as $ws)
                                <span>{{ __('store.buy.wholesale_tier', ['count' => $ws['number'], 'price' => $ws['price']]) }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>

            <section class="checkout-panel" aria-labelledby="checkout-title">
                <div class="checkout-heading">
                    <div>
                        <div class="eyebrow">{{ __('store.buy.secure_checkout') }}</div>
                        <h2 id="checkout-title">{{ __('store.buy.fill_order') }}</h2>
                    </div>
                    <span class="stock-indicator">{{ __('store.buy.stock') }} {{ $in_stock }}</span>
                </div>

                <form action="{{ shop_url('create-order') }}" method="post" id="checkout-form">
                    {{ csrf_field() }}
                    <input type="hidden" name="gid" value="{{ $id }}">

                    <div class="field-grid">
                        <label class="field">
                            <span>{{ __('store.buy.email') }}</span>
                            <input type="email" name="email" id="email" required placeholder="{{ __('store.buy.email_hint') }}" autocomplete="email"
                                   value="{{ auth()->check() ? auth()->user()->email : '' }}" @auth readonly @endauth>
                        </label>
                        @if(dujiaoka_config_get('is_open_search_pwd') == \App\Models\Goods::STATUS_OPEN)
                            <label class="field">
                                <span>{{ __('store.buy.search_password') }}</span>
                                <input type="text" name="search_pwd" id="search_pwd" required placeholder="{{ __('store.buy.search_password_hint') }}" autocomplete="off">
                            </label>
                        @endif
                        @if(isset($open_coupon))
                            <label class="field">
                                <span>{{ __('store.buy.coupon') }} <small>{{ __('store.buy.optional') }}</small></span>
                                <input type="text" name="coupon_code" id="coupon" placeholder="{{ __('store.buy.coupon_hint') }}" autocomplete="off">
                            </label>
                        @endif
                        @if($type == \App\Models\Goods::MANUAL_PROCESSING && is_array($other_ipu))
                            @foreach($other_ipu as $ipu)
                                <label class="field">
                                    <span>{{ $ipu['desc'] }}</span>
                                    <input type="text" id="{{ $ipu['field'] }}" name="{{ $ipu['field'] }}"
                                           @if($ipu['rule'] !== false) required @endif placeholder="{{ $ipu['placeholder'] }}">
                                </label>
                            @endforeach
                        @endif
                    </div>

                    @if(dujiaoka_config_get('is_open_img_code') == \App\Models\Goods::STATUS_OPEN)
                        <label class="field verify-field">
                            <span>{{ __('store.buy.image_captcha') }}</span>
                            <span class="verify-row">
                                <input type="text" name="img_verify_code" id="verifyCode" required autocomplete="off">
                                <img src="{{ captcha_src('buy') . time() }}" alt="{{ __('store.buy.refresh_captcha') }}" id="imageCode" title="{{ __('store.buy.refresh_captcha') }}">
                            </span>
                        </label>
                    @endif

                    <div class="checkout-row">
                        <div class="quantity-control">
                            <span>{{ __('store.buy.quantity') }}</span>
                            <div class="stepper">
                                <button type="button" id="quantity-minus" aria-label="{{ __('store.buy.decrease') }}">&#8722;</button>
                                <input type="number" id="shop-number" name="by_amount" min="1"
                                       max="{{ $buy_limit_num > 0 ? min($buy_limit_num, $in_stock) : $in_stock }}" value="1" inputmode="numeric">
                                <button type="button" id="quantity-plus" aria-label="{{ __('store.buy.increase') }}">&#43;</button>
                            </div>
                        </div>

                        <fieldset class="payment-options">
                            <legend>{{ __('store.buy.payment_method') }}</legend>
                            @forelse($payways as $index => $way)
                                @php
                                    $payCheck = strtolower((string) ($way['pay_check'] ?? ''));
                                    $isBinance = $payCheck === 'binancepay';
                                    $isAlipay = strpos($payCheck, 'ali') === 0 || strpos($payCheck, 'zfb') !== false;
                                    $isWechat = strpos($payCheck, 'wx') === 0 || strpos($payCheck, 'wechat') !== false;
                                @endphp
                                <label class="payment-option @if($isBinance) binance-option @elseif($isAlipay) alipay-option @endif">
                                    <input type="radio" name="payway" value="{{ $way['id'] }}" @if($index == 0) checked @endif>
                                    @if($isAlipay)
                                        <img class="payment-mark alipay-mark" src="{{ asset('assets/common/images/alipay.png') }}?v=1" alt="{{ __('store.buy.alipay') }}">
                                    @elseif($isBinance)
                                        <img class="payment-mark binance-mark" src="{{ asset('assets/common/images/binance.png') }}?v=20260820-1" alt="{{ __('store.buy.binance') }}">
                                    @elseif($isWechat)
                                        <span class="payment-mark wechat-mark">{{ __('store.buy.wechat') }}</span>
                                    @else
                                        <span class="payment-mark generic-mark">{{ __('store.buy.generic_payment') }}</span>
                                    @endif
                                    <span><strong>{{ shop_payment_label($way) }}</strong><small>{{ __('store.buy.payment_auto_confirm') }}</small></span>
                                    <i aria-hidden="true"></i>
                                </label>
                            @empty
                                <div class="form-error">{{ __('store.buy.no_payments') }}</div>
                            @endforelse
                        </fieldset>
                    </div>

                    <div class="checkout-total">
                        <span>{{ __('store.buy.total') }}</span>
                        <strong>¥<span id="order-total">{{ number_format((float) $actual_price, 2, '.', '') }}</span></strong>
                    </div>
                    <button type="submit" id="submit" class="primary-action" @if(empty($payways)) disabled @endif>
                        {{ __('store.buy.create_and_pay') }} <span aria-hidden="true">&#8594;</span>
                    </button>
                    <p class="checkout-note">{{ __('store.buy.delivery_note') }}</p>
                </form>
            </section>
        </div>

        @if(!empty($description))
            <section class="product-description">
                <h2>{{ __('store.buy.description') }}</h2>
                <div>{!! $description !!}</div>
            </section>
        @endif
    </div>
</main>

@if(!empty($buy_prompt))
<dialog class="store-dialog" id="buy-prompt">
    <div class="dialog-head"><h2>{{ __('store.buy.purchase_notice') }}</h2><button type="button" data-close aria-label="{{ __('store.buy.close') }}">&times;</button></div>
    <div class="dialog-body">{!! $buy_prompt !!}</div>
    <button type="button" class="secondary-action" data-close>{{ __('store.buy.understood') }}</button>
</dialog>
@endif

<dialog class="store-dialog" id="validation-dialog">
    <div class="dialog-head"><h2>{{ __('store.buy.check_quantity') }}</h2><button type="button" data-close aria-label="{{ __('store.buy.close') }}">&times;</button></div>
    <p id="validation-message"></p>
    <button type="button" class="secondary-action" data-close>{{ __('store.buy.return_edit') }}</button>
</dialog>
@stop

@section('js')
<script>
    (function () {
        var quantity = document.getElementById('shop-number');
        var max = Number(quantity.max || {{ $in_stock }});
        var basePrice = Number({{ json_encode((float) $actual_price) }});
        var tiers = @json($wholesale_price_cnf ?: []);
        var total = document.getElementById('order-total');
        var validationDialog = document.getElementById('validation-dialog');

        function unitPrice(amount) {
            var price = basePrice;
            tiers.forEach(function (tier) {
                if (amount >= Number(tier.number)) price = Number(tier.price);
            });
            return price;
        }
        function clamp(value) { return Math.max(1, Math.min(max, Number(value) || 1)); }
        function update(value) {
            quantity.value = clamp(value);
            total.textContent = (unitPrice(Number(quantity.value)) * Number(quantity.value)).toFixed(2);
        }
        document.getElementById('quantity-minus').addEventListener('click', function () { update(Number(quantity.value) - 1); });
        document.getElementById('quantity-plus').addEventListener('click', function () { update(Number(quantity.value) + 1); });
        quantity.addEventListener('input', function () { update(quantity.value); });

        document.getElementById('checkout-form').addEventListener('submit', function (event) {
            if (Number(quantity.value) > max || Number(quantity.value) < 1) {
                event.preventDefault();
                document.getElementById('validation-message').textContent = @json(__('store.buy.quantity_range', ['count' => '__MAX__'])).replace('__MAX__', max);
                validationDialog.showModal();
            }
        });

        document.querySelectorAll('[data-close]').forEach(function (button) {
            button.addEventListener('click', function () { button.closest('dialog').close(); });
        });
        @if(dujiaoka_config_get('is_open_img_code') == \App\Models\Goods::STATUS_OPEN)
        document.getElementById('imageCode').addEventListener('click', function () {
            this.src = '{{ captcha_src('buy') }}' + Math.random();
        });
        @endif
        @if(!empty($buy_prompt))
        document.getElementById('buy-prompt').showModal();
        @endif
        update(1);
    }());
</script>
@stop
