<?php
/**
 * The file was created by Assimon.
 *
 * @author    assimon<ashang@utf8.hk>
 * @copyright assimon<ashang@utf8.hk>
 * @link      http://utf8.hk/
 */
use Illuminate\Support\Facades\Route;

$registerStorefrontRoutes = function (array $attributes): void {
    Route::group($attributes + ['namespace' => 'Home'], function () {
        // 首页
        Route::get('/', 'HomeController@index')->name('home');
        // 极验效验
        Route::get('check-geetest', 'HomeController@geetest');
        // 商品详情
        Route::get('buy/{id}', 'HomeController@buy');
        // 提交订单
        Route::post('create-order', 'OrderController@createOrder');
        // 结算页
        Route::get('bill/{orderSN}', 'OrderController@bill');
        // 通过订单号详情页
        Route::get('detail-order-sn/{orderSN}', 'OrderController@detailOrderSN');
        // 订单查询页
        Route::get('order-search', 'OrderController@orderSearch');
        // 检查订单状态
        Route::get('check-order-status/{orderSN}', 'OrderController@checkOrderStatus');
        // 通过订单号查询
        Route::post('search-order-by-sn', 'OrderController@searchOrderBySN');
        // 通过邮箱查询
        Route::post('search-order-by-email', 'OrderController@searchOrderByEmail');
        // 通过浏览器查询
        Route::post('search-order-by-browser', 'OrderController@searchOrderByBrowser');

        Route::middleware('guest')->group(function () {
            Route::get('login', 'AccountController@showLogin')->name('login');
            Route::get('register', 'AccountController@showRegister')->name('register');
            Route::middleware('throttle:5,1')->group(function () {
                Route::post('login', 'AccountController@login');
                Route::post('register', 'AccountController@register');
            });
        });

        Route::middleware('auth')->group(function () {
            Route::post('logout', 'AccountController@logout')->name('logout');
            Route::get('account', 'AccountController@account')->name('account');
            Route::get('account/orders/{id}', 'AccountController@order')->name('account.order');
            Route::get('account/telegram/bind', 'TelegramBindingController@create')->name('telegram.bind');
            Route::get('account/telegram/bind/{id}/status', 'TelegramBindingController@status')->name('telegram.bind.status');
            Route::delete('account/telegram', 'TelegramBindingController@destroy')->name('telegram.unbind');
        });
    });
};

// Chinese remains the canonical storefront at the domain root.
$registerStorefrontRoutes([
    'middleware' => ['dujiaoka.boot'],
]);

// English has the same complete storefront flow under /en, preserving the
// locale throughout catalog, checkout, account, and Telegram binding pages.
$registerStorefrontRoutes([
    'prefix' => 'en',
    'as' => 'en.',
    'middleware' => ['shop.locale:en', 'dujiaoka.boot'],
]);

Route::group(['middleware' => ['install.check'],'namespace' => 'Home'], function () {
    // 安装
    Route::get('install', 'HomeController@install');
    // 执行安装
    Route::post('do-install', 'HomeController@doInstall');
});
