@extends('unicorn.layouts.default')

@section('content')
<main class="store-main account-page">
    <div class="store-shell auth-shell">
        <section class="auth-panel">
            <div class="eyebrow">{{ __('store.auth.eyebrow') }}</div>
            <h1>{{ __('store.auth.login_title') }}</h1>
            <p>{{ __('store.auth.login_copy') }}</p>
            @if($errors->any())<div class="form-error">{{ $errors->first() }}</div>@endif
            <form method="post" action="{{ shop_route('login') }}">
                {{ csrf_field() }}
                <label class="field"><span>{{ __('store.auth.email') }}</span><input type="email" name="email" value="{{ old('email') }}" required autocomplete="email"></label>
                <label class="field"><span>{{ __('store.auth.password') }}</span><input type="password" name="password" required autocomplete="current-password"></label>
                <button class="primary-action" type="submit">{{ __('store.auth.sign_in') }}</button>
            </form>
            <div class="auth-switch">{{ __('store.auth.no_account') }} <a href="{{ shop_route('register') }}">{{ __('store.auth.sign_up') }}</a></div>
        </section>
    </div>
</main>
@stop
