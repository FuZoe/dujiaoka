@extends('unicorn.layouts.default')

@section('content')
<main class="store-main account-page">
    <div class="store-shell auth-shell">
        <section class="auth-panel">
            <div class="eyebrow">{{ __('store.auth.eyebrow') }}</div>
            <h1>{{ __('store.auth.register_title') }}</h1>
            <p>{{ __('store.auth.register_copy') }}</p>
            @if($errors->any())<div class="form-error">{{ $errors->first() }}</div>@endif
            <form method="post" action="{{ shop_route('register') }}">
                {{ csrf_field() }}
                <label class="field"><span>{{ __('store.auth.email') }}</span><input type="email" name="email" value="{{ old('email') }}" required autocomplete="email"></label>
                <label class="field"><span>{{ __('store.auth.password') }}</span><input type="password" name="password" minlength="10" required autocomplete="new-password"></label>
                <label class="field"><span>{{ __('store.auth.password_confirmation') }}</span><input type="password" name="password_confirmation" minlength="10" required autocomplete="new-password"></label>
                <button class="primary-action" type="submit">{{ __('store.auth.sign_up') }}</button>
            </form>
            <div class="auth-switch">{{ __('store.auth.have_account') }} <a href="{{ shop_route('login') }}">{{ __('store.auth.sign_in') }}</a></div>
        </section>
    </div>
</main>
@stop
