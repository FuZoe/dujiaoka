@extends('unicorn.layouts.seo')

@section('content')
<main class="store-main checkout-page">
    <div class="store-shell checkout-shell">
        <a class="back-link" href="/">&#8592; 返回商品列表</a>

        <div class="checkout-layout">
            <section class="product-summary">
                <div class="summary-media">
                    <img src="{{ picture_ulr($picture) }}" alt="{{ $gd_name }}">
                    <span class="delivery-tag @if($type != \App\Models\Goods::AUTOMATIC_DELIVERY) manual @endif">
                        {{ $type == \App\Models\Goods::AUTOMATIC_DELIVERY ? '自动发货' : '人工处理' }}
                    </span>
                </div>
                <div class="summary-copy">
                    <div class="eyebrow">商品详情</div>
                    <h1>{{ $gd_name }}</h1>
                    @if(!empty($gd_description))<p>{{ $gd_description }}</p>@endif
                    <div class="summary-facts">
                        <div><span>单价</span><strong>¥{{ $actual_price }}</strong></div>
                        <div><span>库存</span><strong>{{ $in_stock }}</strong></div>
                    </div>
                    @if($buy_limit_num > 0)
                        <div class="limit-note">每次最多购买 {{ $buy_limit_num }} 件</div>
                    @endif
                    @if(!empty($wholesale_price_cnf) && is_array($wholesale_price_cnf))
                        <div class="wholesale-list">
                            <strong>批量优惠</strong>
                            @foreach($wholesale_price_cnf as $ws)
                                <span>{{ $ws['number'] }} 件起，每件 ¥{{ $ws['price'] }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>

            <section class="checkout-panel" aria-labelledby="checkout-title">
                <div class="checkout-heading">
                    <div>
                        <div class="eyebrow">安全结算</div>
                        <h2 id="checkout-title">填写订单</h2>
                    </div>
                    <span class="stock-indicator">库存 {{ $in_stock }}</span>
                </div>

                <form action="{{ url('create-order') }}" method="post" id="checkout-form">
                    {{ csrf_field() }}
                    <input type="hidden" name="gid" value="{{ $id }}">

                    <div class="field-grid">
                        <label class="field">
                            <span>接收邮箱</span>
                            <input type="email" name="email" id="email" required placeholder="用于查询和接收订单" autocomplete="email"
                                   value="{{ auth()->check() ? auth()->user()->email : '' }}" @auth readonly @endauth>
                        </label>
                        @if(dujiaoka_config_get('is_open_search_pwd') == \App\Models\Goods::STATUS_OPEN)
                            <label class="field">
                                <span>查询密码</span>
                                <input type="text" name="search_pwd" id="search_pwd" required placeholder="请记住此密码" autocomplete="off">
                            </label>
                        @endif
                        @if(isset($open_coupon))
                            <label class="field">
                                <span>优惠码 <small>选填</small></span>
                                <input type="text" name="coupon_code" id="coupon" placeholder="输入优惠码" autocomplete="off">
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
                            <span>图片验证码</span>
                            <span class="verify-row">
                                <input type="text" name="img_verify_code" id="verifyCode" required autocomplete="off">
                                <img src="{{ captcha_src('buy') . time() }}" alt="刷新验证码" id="imageCode" title="点击刷新">
                            </span>
                        </label>
                    @endif

                    <div class="checkout-row">
                        <div class="quantity-control">
                            <span>购买数量</span>
                            <div class="stepper">
                                <button type="button" id="quantity-minus" aria-label="减少数量">&#8722;</button>
                                <input type="number" id="shop-number" name="by_amount" min="1"
                                       max="{{ $buy_limit_num > 0 ? min($buy_limit_num, $in_stock) : $in_stock }}" value="1" inputmode="numeric">
                                <button type="button" id="quantity-plus" aria-label="增加数量">&#43;</button>
                            </div>
                        </div>

                        <fieldset class="payment-options">
                            <legend>支付方式</legend>
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
                                        <img class="payment-mark alipay-mark" src="{{ asset('assets/common/images/alipay.png') }}?v=1" alt="支付宝">
                                    @elseif($isBinance)
                                        <span class="payment-mark binance-mark">币</span>
                                    @elseif($isWechat)
                                        <span class="payment-mark wechat-mark">微</span>
                                    @else
                                        <span class="payment-mark generic-mark">付</span>
                                    @endif
                                    <span><strong>{{ $way['pay_name'] }}</strong><small>支付后自动确认</small></span>
                                    <i aria-hidden="true"></i>
                                </label>
                            @empty
                                <div class="form-error">当前没有可用的支付方式</div>
                            @endforelse
                        </fieldset>
                    </div>

                    <div class="checkout-total">
                        <span>应付合计</span>
                        <strong>¥<span id="order-total">{{ number_format((float) $actual_price, 2, '.', '') }}</span></strong>
                    </div>
                    <button type="submit" id="submit" class="primary-action" @if(empty($payways)) disabled @endif>
                        创建订单并支付 <span aria-hidden="true">&#8594;</span>
                    </button>
                    <p class="checkout-note">付款成功后页面会自动更新并显示卡密</p>
                </form>
            </section>
        </div>

        @if(!empty($description))
            <section class="product-description">
                <h2>商品说明</h2>
                <div>{!! $description !!}</div>
            </section>
        @endif
    </div>
</main>

@if(!empty($buy_prompt))
<dialog class="store-dialog" id="buy-prompt">
    <div class="dialog-head"><h2>购买提示</h2><button type="button" data-close aria-label="关闭">&times;</button></div>
    <div class="dialog-body">{!! $buy_prompt !!}</div>
    <button type="button" class="secondary-action" data-close>我知道了</button>
</dialog>
@endif

<dialog class="store-dialog" id="validation-dialog">
    <div class="dialog-head"><h2>请检查数量</h2><button type="button" data-close aria-label="关闭">&times;</button></div>
    <p id="validation-message"></p>
    <button type="button" class="secondary-action" data-close>返回修改</button>
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
                document.getElementById('validation-message').textContent = '可购买数量为 1 至 ' + max + ' 件。';
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
