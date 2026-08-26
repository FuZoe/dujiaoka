@extends('unicorn.layouts.default')

@section('content')
<main class="store-main account-page">
    <div class="store-shell auth-shell bind-shell">
        <section class="auth-panel bind-panel">
            <div class="eyebrow">Telegram</div>
            <h1>{{ __('store.telegram.title') }}</h1>
            <p id="bind-status">{{ __('store.telegram.waiting', ['minutes' => \App\Service\TelegramBindingService::TOKEN_TTL_MINUTES]) }}</p>
            <div class="bind-qr"><img src="data:image/png;base64,{!! base64_encode(QrCode::format('png')->size(220)->generate($deepLink)) !!}" alt="{{ __('store.telegram.qr_alt') }}"></div>
            <a class="primary-action" href="{{ $deepLink }}" rel="noopener">{{ __('store.telegram.open') }}</a>
            <a class="secondary-action" href="{{ shop_route('account') }}">{{ __('store.telegram.back') }}</a>
        </section>
    </div>
</main>
@stop

@section('js')
<script>
    (function poll() {
        fetch(@json(shop_route('telegram.bind.status', $binding->id)), {headers: {'Accept': 'application/json'}})
            .then(function (response) { return response.json(); })
            .then(function (data) {
                var label = document.getElementById('bind-status');
                if (data.status === 'bound') { label.textContent = @json(__('store.telegram.success')); setTimeout(function () { window.location = @json(shop_route('account')); }, 900); return; }
                if (data.status === 'expired') { label.textContent = @json(__('store.telegram.expired')); return; }
                if (data.status === 'failed') { label.textContent = @json(__('store.telegram.failed')); return; }
                setTimeout(poll, 2000);
            })
            .catch(function () { setTimeout(poll, 3000); });
    }());
</script>
@stop
