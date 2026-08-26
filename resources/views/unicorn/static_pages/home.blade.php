@extends('unicorn.layouts.default')

@section('content')
<main class="store-main">
    <section class="store-shell home-topbar">
        <div>
            <h1>{{ __('store.home.title') }}</h1>
            <p>{{ __('store.home.subtitle') }}</p>
        </div>
        <label class="product-search" for="searchText">
            <i class="ali-icon" aria-hidden="true">&#xe65c;</i>
            <input id="searchText" type="search" placeholder="{{ __('store.home.search') }}" autocomplete="off">
        </label>
    </section>

    @if(dujiaoka_config_get('notice'))
        <section class="store-shell announcement" aria-label="{{ __('store.home.announcement_label') }}">
            <strong>{{ __('store.home.announcement') }}</strong>
            <div>{!! dujiaoka_config_get('notice') !!}</div>
        </section>
    @endif

    <section class="store-shell catalog">
        <div class="category-tabs" role="tablist" aria-label="{{ __('store.home.categories') }}">
            <button class="category-tab active" type="button" data-group="all">{{ __('store.home.all') }}</button>
            @foreach($data as $group)
                <button class="category-tab" type="button" data-group="{{ $group['id'] }}">{{ shop_localized($group, 'gp_name') }}</button>
            @endforeach
        </div>

        <div class="product-grid" id="productGrid">
            @forelse($data as $group)
                @foreach($group['goods'] as $goods)
                    @php
                        $productName = shop_localized($goods, 'gd_name');
                        $productDescription = shop_localized($goods, 'gd_description');
                    @endphp
                    <a class="product-item" href="{{ shop_url("/buy/{$goods['id']}") }}"
                       data-group="{{ $group['id'] }}" data-search="{{ strtolower($productName . ' ' . ($productDescription ?? '')) }}">
                        <div class="product-media">
                            <img src="{{ picture_ulr($goods['picture']) }}" alt="{{ $productName }}" loading="lazy">
                            <span class="delivery-tag @if($goods['type'] != \App\Models\Goods::AUTOMATIC_DELIVERY) manual @endif">
                                {{ $goods['type'] == \App\Models\Goods::AUTOMATIC_DELIVERY ? __('store.home.automatic') : __('store.home.manual') }}
                            </span>
                        </div>
                        <div class="product-body">
                            <h2>{{ $productName }}</h2>
                            @if(!empty($productDescription))
                                <p>{{ $productDescription }}</p>
                            @endif
                            <div class="product-meta">
                                <span>{{ __('store.home.stock', ['count' => $goods['in_stock']]) }}</span>
                                <span>{{ __('store.home.sold', ['count' => $goods['sales_volume'] ?? 0]) }}</span>
                            </div>
                            <div class="product-bottom">
                                <strong><small>¥</small>{{ $goods['actual_price'] }}</strong>
                                <span class="buy-arrow" aria-hidden="true">&#8594;</span>
                            </div>
                        </div>
                    </a>
                @endforeach
            @empty
                <div class="empty-state">{{ __('store.home.no_products') }}</div>
            @endforelse
        </div>
        <div class="empty-state" id="searchEmpty" hidden>{{ __('store.home.no_matches') }}</div>
    </section>
</main>
@stop

@section('js')
<script>
    (function () {
        var search = document.getElementById('searchText');
        var tabs = Array.prototype.slice.call(document.querySelectorAll('.category-tab'));
        var items = Array.prototype.slice.call(document.querySelectorAll('.product-item'));
        var empty = document.getElementById('searchEmpty');
        var activeGroup = 'all';

        function filterProducts() {
            var query = search.value.trim().toLowerCase();
            var visible = 0;
            items.forEach(function (item) {
                var matchesGroup = activeGroup === 'all' || item.dataset.group === activeGroup;
                var matchesQuery = !query || item.dataset.search.indexOf(query) !== -1;
                item.hidden = !(matchesGroup && matchesQuery);
                if (!item.hidden) visible += 1;
            });
            empty.hidden = visible !== 0;
        }

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                activeGroup = tab.dataset.group;
                tabs.forEach(function (item) { item.classList.toggle('active', item === tab); });
                filterProducts();
            });
        });
        search.addEventListener('input', filterProducts);
    }());
</script>
@stop
