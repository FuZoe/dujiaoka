@extends('unicorn.layouts.default')

@section('content')
<main class="store-main account-page">
    <div class="store-shell auth-shell bind-shell">
        <section class="auth-panel bind-panel">
            <div class="eyebrow">Telegram</div>
            <h1>绑定私聊账户</h1>
            <p id="bind-status">等待您在 Telegram 中确认，链接将在 {{ \App\Service\TelegramBindingService::TOKEN_TTL_MINUTES }} 分钟后过期。</p>
            <div class="bind-qr"><img src="data:image/png;base64,{!! base64_encode(QrCode::format('png')->size(220)->generate($deepLink)) !!}" alt="Telegram 绑定二维码"></div>
            <a class="primary-action" href="{{ $deepLink }}" rel="noopener">打开 Telegram 绑定</a>
            <a class="secondary-action" href="{{ route('account') }}">返回账户</a>
        </section>
    </div>
</main>
@stop

@section('js')
<script>
    (function poll() {
        fetch(@json(route('telegram.bind.status', $binding->id)), {headers: {'Accept': 'application/json'}})
            .then(function (response) { return response.json(); })
            .then(function (data) {
                var label = document.getElementById('bind-status');
                if (data.status === 'bound') { label.textContent = '绑定成功，正在返回账户。'; setTimeout(function () { window.location = @json(route('account')); }, 900); return; }
                if (data.status === 'expired') { label.textContent = '绑定链接已过期，请返回账户重新生成。'; return; }
                if (data.status === 'failed') { label.textContent = '绑定未完成，请确认是在机器人私聊中操作。'; return; }
                setTimeout(poll, 2000);
            })
            .catch(function () { setTimeout(poll, 3000); });
    }());
</script>
@stop
