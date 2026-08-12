@extends('unicorn.layouts.default')

@section('content')
<main class="store-main account-page">
    <div class="store-shell auth-shell">
        <section class="auth-panel">
            <div class="eyebrow">顾客账户</div>
            <h1>注册</h1>
            <p>邮箱用于登录和关联订单，密码至少 10 位。</p>
            @if($errors->any())<div class="form-error">{{ $errors->first() }}</div>@endif
            <form method="post" action="{{ route('register') }}">
                {{ csrf_field() }}
                <label class="field"><span>邮箱</span><input type="email" name="email" value="{{ old('email') }}" required autocomplete="email"></label>
                <label class="field"><span>密码</span><input type="password" name="password" minlength="10" required autocomplete="new-password"></label>
                <label class="field"><span>确认密码</span><input type="password" name="password_confirmation" minlength="10" required autocomplete="new-password"></label>
                <button class="primary-action" type="submit">创建账户</button>
            </form>
            <div class="auth-switch">已有账户？<a href="{{ route('login') }}">登录</a></div>
        </section>
    </div>
</main>
@stop
