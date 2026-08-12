@extends('unicorn.layouts.default')

@section('content')
<main class="store-main order-page">
    <div class="store-shell narrow-shell">
        <section class="order-sheet error-sheet">
            <div class="error-symbol">!</div>
            <div class="eyebrow">操作未完成</div>
            <h1>{{ $title }}</h1>
            <p>{{ $content }}</p>
            <a href="{{ $url ?: 'javascript:history.back()' }}" class="primary-action">返回上一页</a>
        </section>
    </div>
</main>
@stop
