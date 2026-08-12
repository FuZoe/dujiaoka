@extends('unicorn.layouts.default')

@section('content')
<main class="store-main order-page">
    <div class="store-shell narrow-shell">
        <div class="page-heading"><div><div class="eyebrow">订单中心</div><h1>订单详情</h1></div><a href="{{ url('order-search') }}">查询其他订单</a></div>
        <div class="order-list">
            @foreach($orders as $order)
                @php
                    $statusClass = 'pending';
                    $statusLabel = '待支付';
                    if ($order['status'] == \App\Models\Order::STATUS_EXPIRED) { $statusClass = 'expired'; $statusLabel = '已过期'; }
                    elseif ($order['status'] == \App\Models\Order::STATUS_PENDING) { $statusClass = 'processing'; $statusLabel = '待处理'; }
                    elseif ($order['status'] == \App\Models\Order::STATUS_PROCESSING) { $statusClass = 'processing'; $statusLabel = '处理中'; }
                    elseif ($order['status'] == \App\Models\Order::STATUS_COMPLETED) { $statusClass = 'completed'; $statusLabel = '已完成'; }
                    elseif ($order['status'] == \App\Models\Order::STATUS_FAILURE) { $statusClass = 'failed'; $statusLabel = '处理失败'; }
                    elseif ($order['status'] == \App\Models\Order::STATUS_ABNORMAL) { $statusClass = 'failed'; $statusLabel = '异常'; }
                @endphp
                <article class="order-sheet order-result">
                    <div class="order-result-head">
                        <div><span class="mono">{{ $order['order_sn'] }}</span><h2>{{ $order['title'] }}</h2></div>
                        <span class="order-status {{ $statusClass }}">{{ $statusLabel }}</span>
                    </div>
                    <dl class="order-details compact">
                        <div><dt>实付金额</dt><dd>¥{{ $order['actual_price'] }}</dd></div>
                        <div><dt>数量</dt><dd>{{ $order['buy_amount'] }}</dd></div>
                        <div><dt>邮箱</dt><dd>{{ $order['email'] }}</dd></div>
                        <div><dt>支付方式</dt><dd>{{ $order['pay']['pay_name'] ?? '-' }}</dd></div>
                        <div><dt>创建时间</dt><dd>{{ $order['created_at'] }}</dd></div>
                        <div><dt>发货方式</dt><dd>{{ $order['type'] == \App\Models\Order::AUTOMATIC_DELIVERY ? '自动发货' : '人工处理' }}</dd></div>
                    </dl>
                    @if($order['status'] == \App\Models\Order::STATUS_WAIT_PAY)
                        <a class="primary-action" href="{{ url('/bill/' . $order['order_sn']) }}">继续支付 <span aria-hidden="true">&#8594;</span></a>
                    @endif
                    @if(!empty($order['info']))
                        <div class="delivery-result">
                            <div><strong>发货内容</strong><span>请及时妥善保存</span></div>
                            <textarea class="delivery-text" readonly>{{ $order['info'] }}</textarea>
                            <button type="button" class="secondary-action copy-card" data-copy="{{ $order['info'] }}">复制全部内容</button>
                        </div>
                    @elseif($order['status'] > \App\Models\Order::STATUS_WAIT_PAY)
                        <div class="delivery-result waiting">订单已支付，发货内容正在生成。</div>
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
                button.textContent = '已复制';
            });
        });
    });
</script>
@stop
