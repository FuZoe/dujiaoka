@extends('unicorn.layouts.default')

@section('content')
<main class="store-main">
    <section class="store-shell home-topbar">
        <div>
            <h1>选择商品</h1>
            <p>自动发货，支付完成后立即查看卡密</p>
        </div>
        <label class="product-search" for="searchText">
            <i class="ali-icon" aria-hidden="true">&#xe65c;</i>
            <input id="searchText" type="search" placeholder="搜索商品" autocomplete="off">
        </label>
    </section>

    @if(dujiaoka_config_get('notice'))
        <section class="store-shell announcement" aria-label="网站公告">
            <strong>公告</strong>
            <div>{!! dujiaoka_config_get('notice') !!}</div>
        </section>
    @endif

    <section class="store-shell catalog">
        <div class="category-tabs" role="tablist" aria-label="商品分类">
            <button class="category-tab active" type="button" data-group="all">全部</button>
            @foreach($data as $group)
                <button class="category-tab" type="button" data-group="{{ $group['id'] }}">{{ $group['gp_name'] }}</button>
            @endforeach
        </div>

        <div class="product-grid" id="productGrid">
            @forelse($data as $group)
                @foreach($group['goods'] as $goods)
                    <a class="product-item" href="{{ url("/buy/{$goods['id']}") }}"
                       data-group="{{ $group['id'] }}" data-search="{{ strtolower($goods['gd_name'] . ' ' . ($goods['gd_description'] ?? '')) }}">
                        <div class="product-media">
                            <img src="{{ picture_ulr($goods['picture']) }}" alt="{{ $goods['gd_name'] }}" loading="lazy">
                            <span class="delivery-tag @if($goods['type'] != \App\Models\Goods::AUTOMATIC_DELIVERY) manual @endif">
                                {{ $goods['type'] == \App\Models\Goods::AUTOMATIC_DELIVERY ? '自动发货' : '人工处理' }}
                            </span>
                        </div>
                        <div class="product-body">
                            <h2>{{ $goods['gd_name'] }}</h2>
                            @if(!empty($goods['gd_description']))
                                <p>{{ $goods['gd_description'] }}</p>
                            @endif
                            <div class="product-meta">
                                <span>库存 {{ $goods['in_stock'] }}</span>
                                <span>已售 {{ $goods['sales_volume'] ?? 0 }}</span>
                            </div>
                            <div class="product-bottom">
                                <strong><small>¥</small>{{ $goods['actual_price'] }}</strong>
                                <span class="buy-arrow" aria-hidden="true">&#8594;</span>
                            </div>
                        </div>
                    </a>
                @endforeach
            @empty
                <div class="empty-state">暂无在售商品</div>
            @endforelse
        </div>
        <div class="empty-state" id="searchEmpty" hidden>没有找到匹配的商品</div>
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
