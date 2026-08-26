@extends('unicorn.layouts.default')

@section('content')
<main class="store-main order-page">
    <div class="store-shell narrow-shell">
        <div class="page-heading"><div><div class="eyebrow">{{ __('store.order.eyebrow') }}</div><h1>{{ __('store.order.title') }}</h1></div><a href="{{ shop_url('order-search') }}">{{ __('store.order.other_orders') }}</a></div>
        <div class="order-list">
            @foreach($orders as $order)
                @php
                    $statusClass = 'pending';
                    $statusLabel = __('store.order.pending_payment');
                    if ($order['status'] == \App\Models\Order::STATUS_EXPIRED) { $statusClass = 'expired'; $statusLabel = __('store.order.expired'); }
                    elseif ($order['status'] == \App\Models\Order::STATUS_PENDING) { $statusClass = 'processing'; $statusLabel = __('store.order.pending'); }
                    elseif ($order['status'] == \App\Models\Order::STATUS_PROCESSING) { $statusClass = 'processing'; $statusLabel = __('store.order.processing'); }
                    elseif ($order['status'] == \App\Models\Order::STATUS_COMPLETED) { $statusClass = 'completed'; $statusLabel = __('store.order.completed'); }
                    elseif ($order['status'] == \App\Models\Order::STATUS_FAILURE) { $statusClass = 'failed'; $statusLabel = __('store.order.failed'); }
                    elseif ($order['status'] == \App\Models\Order::STATUS_ABNORMAL) { $statusClass = 'failed'; $statusLabel = __('store.order.abnormal'); }
                    $orderTitle = shop_locale() === 'en' && optional($order->goods)->gd_name_en ? $order->goods->gd_name_en : $order['title'];
                @endphp
                <article class="order-sheet order-result">
                    <div class="order-result-head">
                        <div><span class="mono">{{ $order['order_sn'] }}</span><h2>{{ $orderTitle }}</h2></div>
                        <span class="order-status {{ $statusClass }}">{{ $statusLabel }}</span>
                    </div>
                    <dl class="order-details compact">
                        <div><dt>{{ __('store.order.amount_paid') }}</dt><dd>¥{{ $order['actual_price'] }}</dd></div>
                        <div><dt>{{ __('store.order.quantity') }}</dt><dd>{{ $order['buy_amount'] }}</dd></div>
                        <div><dt>{{ __('store.order.email') }}</dt><dd>{{ $order['email'] }}</dd></div>
                        <div><dt>{{ __('store.order.payment') }}</dt><dd>{{ shop_payment_label($order['pay'] ?? null, '-') }}</dd></div>
                        <div><dt>{{ __('store.order.created_at') }}</dt><dd>{{ $order['created_at'] }}</dd></div>
                        <div><dt>{{ __('store.order.delivery') }}</dt><dd>{{ $order['type'] == \App\Models\Order::AUTOMATIC_DELIVERY ? __('store.order.automatic') : __('store.order.manual') }}</dd></div>
                    </dl>
                    @if($order['status'] == \App\Models\Order::STATUS_WAIT_PAY)
                        <a class="primary-action" href="{{ shop_url('/bill/' . $order['order_sn']) }}">{{ __('store.order.continue_payment') }} <span aria-hidden="true">&#8594;</span></a>
                    @endif
                    @if(!empty($order['info']))
                        <div class="delivery-result">
                            <div><strong>{{ __('store.order.delivery_content') }}</strong><span>{{ __('store.order.save_delivery') }}</span></div>
                            <textarea class="delivery-text" readonly>{{ $order['info'] }}</textarea>
                            <button type="button" class="secondary-action copy-card" data-copy="{{ $order['info'] }}">{{ __('store.order.copy_delivery') }}</button>
                        </div>
                    @elseif($order['status'] > \App\Models\Order::STATUS_WAIT_PAY)
                        <div class="delivery-result waiting">{{ __('store.order.waiting_delivery') }}</div>
                    @endif
                </article>
            @endforeach
        </div>
    </div>
</main>
@stop

@section('js')
<script>
    document.querySelectorAll('.copy-card').forEach(function (button) {
        button.addEventListener('click', function () {
            navigator.clipboard.writeText(button.dataset.copy).then(function () {
                button.textContent = @json(__('store.order.copied'));
            });
        });
    });
</script>
@stop
