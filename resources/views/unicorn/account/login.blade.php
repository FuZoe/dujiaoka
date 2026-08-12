@extends('unicorn.layouts.default')

@section('content')
<main class="store-main account-page">
    <div class="store-shell auth-shell">
        <section class="auth-panel">
            <div class="eyebrow">顾客账户</div>
            <h1>登录</h1>
            <p>登录后可绑定 Telegram，并接收自己的订单通知。</p>
            @if($errors->any())<div class="form-error">{{ $errors->first() }}</div>@endif
            <form method="post" action="{{ route('login') }}">
                {{ csrf_field() }}
                <label class="field"><span>邮箱</span><input type="email" name="email" value="{{ old('email') }}" required autocomplete="email"></label>
                <label class="field"><span>密码</span><input type="password" name="password" required autocomplete="current-password"></label>
                <button class="primary-action" type="submit">登录</button>
            </form>
            <div class="auth-switch">还没有账户？<a href="{{ route('register') }}">注册</a></div>
        </section>
    </div>
</main>
@stop
