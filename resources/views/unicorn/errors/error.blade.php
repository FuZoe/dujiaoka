@extends('unicorn.layouts.default')

@section('content')
<main class="store-main order-page">
    <div class="store-shell narrow-shell">
        <section class="order-sheet error-sheet">
            <div class="error-symbol">!</div>
            <div class="eyebrow">{{ __('store.error.eyebrow') }}</div>
            <h1>{{ $title }}</h1>
            <p>{{ $content }}</p>
            <a href="{{ $url ?: 'javascript:history.back()' }}" class="primary-action">{{ __('store.error.back') }}</a>
        </section>
    </div>
</main>
@stop
