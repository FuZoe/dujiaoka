<header class="store-header sticky-top">
    <div class="store-shell store-nav">
        <a class="store-brand" href="/" aria-label="{{ dujiaoka_config_get('text_logo') }}">
            @if(dujiaoka_config_get('img_logo'))
                <img src="{{ picture_ulr(dujiaoka_config_get('img_logo')) }}" alt="">
            @endif
            <span>{{ dujiaoka_config_get('text_logo') ?: 'NewZoe' }}</span>
        </a>
        <nav class="store-nav-links" aria-label="主导航">
            <a class="@if(\Illuminate\Support\Facades\Request::path() == '/') active @endif" href="/">商品</a>
            <a class="@if(\Illuminate\Support\Facades\Request::url() == url('order-search')) active @endif" href="{{ url('order-search') }}">查订单</a>
            @auth
                <a class="@if(\Illuminate\Support\Facades\Request::is('account*')) active @endif" href="{{ route('account') }}">账户</a>
                @if(!auth()->user()->isTelegramBound())
                    <a class="telegram-nav-link" href="{{ route('telegram.bind') }}">绑定 Telegram</a>
                @endif
            @else
                <a class="@if(\Illuminate\Support\Facades\Request::is('login') || \Illuminate\Support\Facades\Request::is('register')) active @endif" href="{{ route('login') }}">登录</a>
            @endauth
        </nav>
    </div>
</header>
