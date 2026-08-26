@extends('unicorn.layouts.default')

@section('content')
<main class="store-main account-page">
    <div class="store-shell account-shell">
        <div class="page-heading account-heading">
            <div><div class="eyebrow">{{ __('store.auth.eyebrow') }}</div><h1>{{ __('store.auth.my_account') }}</h1><p>{{ $customer->email }}</p></div>
            <form method="post" action="{{ shop_route('logout') }}">{{ csrf_field() }}<button class="text-action" type="submit">{{ __('store.auth.logout') }}</button></form>
        </div>

        @if(session('status'))<div class="account-notice">{{ session('status') }}</div>@endif

        <section id="telegram-account" class="account-section telegram-status">
            <div>
                <div class="eyebrow">{{ __('store.auth.telegram_eyebrow') }}</div>
                <h2>{{ $customer->isTelegramBound() ? __('store.auth.bound') : __('store.auth.not_bound') }}</h2>
                <p>{{ $customer->isTelegramBound() ? ('@'.($customer->telegram_username ?: __('store.auth.telegram_connected')).' · '.($customer->telegram_name ?: __('store.auth.telegram_user'))) : __('store.auth.telegram_copy') }}</p>
            </div>
            <div class="account-actions">
                @if($customer->isTelegramBound())
                    <a class="secondary-action" href="{{ shop_route('telegram.bind') }}">{{ __('store.auth.rebind') }}</a>
                    <form method="post" action="{{ shop_route('telegram.unbind') }}">{{ csrf_field() }}{{ method_field('DELETE') }}<button class="text-action danger" type="submit">{{ __('store.auth.unbind') }}</button></form>
                @else
                    <a class="primary-action compact-action" href="{{ shop_route('telegram.bind') }}">{{ __('store.auth.bind_telegram') }}</a>
                @endif
            </div>
        </section>

        <section class="account-section">
            <div class="section-heading"><div><div class="eyebrow">{{ __('store.order.eyebrow') }}</div><h2>{{ __('store.auth.recent_orders') }}</h2></div><a href="{{ shop_url('order-search') }}">{{ __('store.auth.guest_lookup') }}</a></div>
            <div class="account-orders">
                @forelse($orders as $order)
                    @php
                        $lastNotification = $order->telegramNotifications->sortByDesc('id')->first();
                        $statusLabel = [
                            \App\Models\Order::STATUS_WAIT_PAY => __('store.order.pending_payment'),
                            \App\Models\Order::STATUS_EXPIRED => __('store.order.expired'),
                            \App\Models\Order::STATUS_PENDING => __('store.order.pending'),
                            \App\Models\Order::STATUS_PROCESSING => __('store.order.processing'),
                            \App\Models\Order::STATUS_COMPLETED => __('store.order.completed'),
                            \App\Models\Order::STATUS_FAILURE => __('store.order.failed'),
                            \App\Models\Order::STATUS_ABNORMAL => __('store.order.abnormal'),
                        ][$order->status] ?? __('store.order.unknown');
                        $notificationLabel = [
                            'queued' => __('store.auth.notification_queued'), 'sending' => __('store.auth.notification_sending'), 'retrying' => __('store.auth.notification_retrying'),
                            'sent' => __('store.auth.notification_sent'), 'failed' => __('store.auth.notification_failed'), 'skipped' => __('store.auth.notification_skipped'),
                        ][$lastNotification ? $lastNotification->status : ''] ?? __('store.auth.notification_disabled');
                    @endphp
                    <a class="account-order" href="{{ shop_route('account.order', $order->id) }}">
                        <div><strong>{{ $order->title }}</strong><span class="mono">{{ $order->order_sn }}</span></div>
                        <div><strong>¥{{ $order->actual_price }}</strong><span>{{ $statusLabel }} · {{ __('store.auth.notification', ['status' => $notificationLabel]) }}</span></div>
                    </a>
                @empty
                    <div class="empty-state">{{ __('store.auth.no_orders') }}</div>
                @endforelse
            </div>
        </section>
    </div>
</main>
@stop
