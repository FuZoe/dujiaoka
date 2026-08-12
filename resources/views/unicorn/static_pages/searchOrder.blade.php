@extends('unicorn.layouts.default')

@section('content')
<main class="store-main order-page">
    <div class="store-shell search-shell">
        <section class="search-intro">
            <div class="eyebrow">订单中心</div>
            <h1>查询订单</h1>
            <p>输入下单时保存的信息，查看支付状态与发货内容。</p>
        </section>
        <section class="search-panel">
            <div class="search-tabs" role="tablist">
                <button class="active" type="button" data-target="search-sn">订单号</button>
                <button type="button" data-target="search-email">邮箱</button>
                <button type="button" data-target="search-browser">本机订单</button>
            </div>
            <div class="search-pane active" id="search-sn">
                <form action="{{ url('search-order-by-sn') }}" method="post">
                    {{ csrf_field() }}
                    <label class="field"><span>订单号</span><input type="text" name="order_sn" required placeholder="输入完整订单号" autocomplete="off"></label>
                    <button class="primary-action" type="submit">立即查询 <span aria-hidden="true">&#8594;</span></button>
                </form>
            </div>
            <div class="search-pane" id="search-email" hidden>
                <form action="{{ url('search-order-by-email') }}" method="post">
                    {{ csrf_field() }}
                    <label class="field"><span>下单邮箱</span><input type="email" name="email" required placeholder="name@example.com" autocomplete="email"></label>
                    @if(dujiaoka_config_get('is_open_search_pwd', \App\Models\BaseModel::STATUS_CLOSE) == \App\Models\BaseModel::STATUS_OPEN)
                        <label class="field"><span>查询密码</span><input type="password" name="search_pwd" required autocomplete="current-password"></label>
                    @endif
                    <button class="primary-action" type="submit">立即查询 <span aria-hidden="true">&#8594;</span></button>
                </form>
            </div>
            <div class="search-pane" id="search-browser" hidden>
                <form action="{{ url('search-order-by-browser') }}" method="post">
                    {{ csrf_field() }}
                    <p>查询当前浏览器中保存的历史订单。</p>
                    <button class="primary-action" type="submit">查看本机订单 <span aria-hidden="true">&#8594;</span></button>
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
