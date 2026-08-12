@extends('unicorn.layouts.default')

@section('content')
<main class="store-main account-page">
    <div class="store-shell account-shell">
        <div class="page-heading account-heading">
            <div><div class="eyebrow">顾客账户</div><h1>我的账户</h1><p>{{ $customer->email }}</p></div>
            <form method="post" action="{{ route('logout') }}">{{ csrf_field() }}<button class="text-action" type="submit">退出</button></form>
        </div>

        @if(session('status'))<div class="account-notice">{{ session('status') }}</div>@endif

        <section class="account-section telegram-status">
            <div>
                <div class="eyebrow">Telegram 私聊通知</div>
                <h2>{{ $customer->isTelegramBound() ? '已绑定' : '未绑定' }}</h2>
                <p>{{ $customer->isTelegramBound() ? ('@'.($customer->telegram_username ?: '已连接账户').' · '.($customer->telegram_name ?: 'Telegram 用户')) : '绑定后，仅在机器人私聊中接收属于您的订单状态。' }}</p>
            </div>
            <div class="account-actions">
                @if($customer->isTelegramBound())
                    <a class="secondary-action" href="{{ route('telegram.bind') }}">重新绑定</a>
                    <form method="post" action="{{ route('telegram.unbind') }}">{{ csrf_field() }}{{ method_field('DELETE') }}<button class="text-action danger" type="submit">解绑</button></form>
                @else
                    <a class="primary-action compact-action" href="{{ route('telegram.bind') }}">绑定 Telegram</a>
                @endif
            </div>
        </section>

        <section class="account-section">
            <div class="section-heading"><div><div class="eyebrow">订单中心</div><h2>最近订单</h2></div><a href="{{ url('order-search') }}">游客订单查询</a></div>
            <div class="account-orders">
                @forelse($orders as $order)
                    @php
                        $lastNotification = $order->telegramNotifications->sortByDesc('id')->first();
                        $statusLabel = \App\Models\Order::getStatusMap()[$order->status] ?? '未知';
                        $notificationLabel = [
                            'queued' => '排队中', 'sending' => '发送中', 'retrying' => '重试中',
                            'sent' => '已发送', 'failed' => '失败', 'skipped' => '已跳过',
                        ][$lastNotification ? $lastNotification->status : ''] ?? '未启用';
                    @endphp
                    <a class="account-order" href="{{ route('account.order', $order->id) }}">
                        <div><strong>{{ $order->title }}</strong><span class="mono">{{ $order->order_sn }}</span></div>
                        <div><strong>¥{{ $order->actual_price }}</strong><span>{{ $statusLabel }} · 通知{{ $notificationLabel }}</span></div>
                    </a>
                @empty
                    <div class="empty-state">登录后创建的订单会显示在这里。</div>
                @endforelse
            </div>
        </section>
    </div>
</main>
@stop
