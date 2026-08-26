@extends('unicorn.layouts.default')

@section('content')
<main class="store-main order-page">
    <div class="store-shell search-shell">
        <section class="search-intro">
            <div class="eyebrow">{{ __('store.search.eyebrow') }}</div>
            <h1>{{ __('store.search.title') }}</h1>
            <p>{{ __('store.search.subtitle') }}</p>
        </section>
        <section class="search-panel">
            <div class="search-tabs" role="tablist">
                <button class="active" type="button" data-target="search-sn">{{ __('store.search.by_order') }}</button>
                <button type="button" data-target="search-email">{{ __('store.search.by_email') }}</button>
                <button type="button" data-target="search-browser">{{ __('store.search.by_browser') }}</button>
            </div>
            <div class="search-pane active" id="search-sn">
                <form action="{{ shop_url('search-order-by-sn') }}" method="post">
                    {{ csrf_field() }}
                    <label class="field"><span>{{ __('store.search.order_no') }}</span><input type="text" name="order_sn" required placeholder="{{ __('store.search.order_no_hint') }}" autocomplete="off"></label>
                    <button class="primary-action" type="submit">{{ __('store.search.search_now') }} <span aria-hidden="true">&#8594;</span></button>
                </form>
            </div>
            <div class="search-pane" id="search-email" hidden>
                <form action="{{ shop_url('search-order-by-email') }}" method="post">
                    {{ csrf_field() }}
                    <label class="field"><span>{{ __('store.search.email') }}</span><input type="email" name="email" required placeholder="name@example.com" autocomplete="email"></label>
                    @if(dujiaoka_config_get('is_open_search_pwd', \App\Models\BaseModel::STATUS_CLOSE) == \App\Models\BaseModel::STATUS_OPEN)
                        <label class="field"><span>{{ __('store.search.password') }}</span><input type="password" name="search_pwd" required autocomplete="current-password"></label>
                    @endif
                    <button class="primary-action" type="submit">{{ __('store.search.search_now') }} <span aria-hidden="true">&#8594;</span></button>
                </form>
            </div>
            <div class="search-pane" id="search-browser" hidden>
                <form action="{{ shop_url('search-order-by-browser') }}" method="post">
                    {{ csrf_field() }}
                    <p>{{ __('store.search.browser_copy') }}</p>
                    <button class="primary-action" type="submit">{{ __('store.search.browser_action') }} <span aria-hidden="true">&#8594;</span></button>
                </form>
            </div>
        </section>
    </div>
</main>
@stop

@section('js')
<script>
    document.querySelectorAll('.search-tabs button').forEach(function (button) {
        button.addEventListener('click', function () {
            document.querySelectorAll('.search-tabs button').forEach(function (item) { item.classList.toggle('active', item === button); });
            document.querySelectorAll('.search-pane').forEach(function (pane) {
                var selected = pane.id === button.dataset.target;
                pane.hidden = !selected;
                pane.classList.toggle('active', selected);
            });
        });
    });
</script>
@stop
