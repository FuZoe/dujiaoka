<header class="store-header sticky-top">
    <div class="store-shell store-nav">
        @php
            $storePath = trim(request()->path(), '/');
            $storePath = $storePath === 'en' ? '' : preg_replace('#^en/#', '', $storePath);
        @endphp
        <a class="store-brand" href="{{ shop_url('/') }}" aria-label="{{ dujiaoka_config_get('text_logo') }}">
            @if(dujiaoka_config_get('img_logo'))
                <img src="{{ picture_ulr(dujiaoka_config_get('img_logo')) }}" alt="">
            @endif
            <span>{{ dujiaoka_config_get('text_logo') ?: 'NewZoe' }}</span>
        </a>
        <nav class="store-nav-links" aria-label="{{ __('store.nav.products') }}">
            <a class="@if($storePath === '') active @endif" href="{{ shop_url('/') }}">{{ __('store.nav.products') }}</a>
            <a class="@if($storePath === 'order-search') active @endif" href="{{ shop_url('order-search') }}">{{ __('store.nav.order_search') }}</a>
            @auth
                <a class="@if(strpos($storePath, 'account') === 0) active @endif" href="{{ shop_route('account') }}">{{ __('store.nav.account') }}</a>
                @if(auth()->user()->isTelegramBound())
                    <a class="telegram-nav-link" href="{{ shop_route('account') }}#telegram-account">{{ __('store.nav.manage_telegram') }}</a>
                @else
                    <a class="telegram-nav-link" href="{{ shop_route('telegram.bind') }}">{{ __('store.nav.bind_telegram') }}</a>
                @endif
            @else
                <a class="telegram-nav-link" href="{{ shop_route('telegram.bind') }}">{{ __('store.nav.bind_telegram') }}</a>
                <a class="@if(in_array($storePath, ['login', 'register'], true)) active @endif" href="{{ shop_route('login') }}">{{ __('store.nav.login') }}</a>
            @endauth
            <a class="language-switch" href="{{ shop_switch_locale_url(shop_locale() === 'en' ? 'zh_CN' : 'en') }}" aria-label="{{ __('store.nav.language') }}">
                {{ shop_locale() === 'en' ? __('store.nav.chinese') : __('store.nav.english') }}
            </a>
        </nav>
    </div>
</header>
