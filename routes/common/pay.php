<?php
/**
 * The file was created by Assimon.
 *
 * @author    assimon<ashang@utf8.hk>
 * @copyright assimon<ashang@utf8.hk>
 * @link      http://utf8.hk/
 */
use Illuminate\Support\Facades\Route;

Route::get('pay-gateway/{handle}/{payway}/{orderSN}', 'PayController@redirectGateway');
Route::get('en/pay-gateway/{handle}/{payway}/{orderSN}', 'PayController@redirectGateway')
    ->middleware('shop.locale:en');

// 支付相关
Route::group(['prefix' => 'pay', 'namespace' => 'Pay', 'middleware' => ['shop.locale', 'dujiaoka.pay_gate_way']], function () {
    // NewZoe 微信收款通知
    Route::get('newzoe/{payway}/{orderSN}', 'NewzoePayController@gateway');
    Route::post('newzoe/notify_url', 'NewzoePayController@notifyUrl');
    // Binance Pay personal receive QR
    Route::get('binance/status/{orderSN}', 'BinancePayController@status');
    Route::get('binance/{payway}/{orderSN}', 'BinancePayController@gateway');
    // 支付宝
    Route::get('alipay/{payway}/{orderSN}', 'AlipayController@gateway');
    Route::post('alipay/notify_url', 'AlipayController@notifyUrl');
    // 微信
    Route::get('wepay/{payway}/{orderSN}', 'WepayController@gateway');
    Route::post('wepay/notify_url', 'WepayController@notifyUrl');
    // 码支付
    Route::get('mapay/{payway}/{orderSN}', 'MapayController@gateway');
    Route::post('mapay/notify_url', 'MapayController@notifyUrl');
    // Paysapi
    Route::get('paysapi/{payway}/{orderSN}', 'PaysapiController@gateway');
    Route::post('paysapi/notify_url', 'PaysapiController@notifyUrl');
    Route::get('paysapi/return_url', 'PaysapiController@returnUrl')->name('paysapi-return');
    // payjs
    Route::get('payjs/{payway}/{orderSN}', 'PayjsController@gateway');
    Route::post('payjs/notify_url', 'PayjsController@notifyUrl');
    // 易支付
    Route::get('yipay/{payway}/{orderSN}', 'YipayController@gateway');
    Route::get('yipay/notify_url', 'YipayController@notifyUrl');
    Route::get('yipay/return_url', 'YipayController@returnUrl')->name('yipay-return');
    // paypal
    Route::get('paypal/{payway}/{orderSN}', 'PaypalPayController@gateway');
    Route::get('paypal/return_url', 'PaypalPayController@returnUrl')->name('paypal-return');
    Route::any('paypal/notify_url', 'PaypalPayController@notifyUrl');
    // V免签
    Route::get('vpay/{payway}/{orderSN}', 'VpayController@gateway');
    Route::get('vpay/notify_url', 'VpayController@notifyUrl');
    Route::get('vpay/return_url', 'VpayController@returnUrl')->name('vpay-return');
    // stripe
    Route::get('stripe/{payway}/{oid}','StripeController@gateway');
    Route::get('stripe/return_url','StripeController@returnUrl');
    Route::get('stripe/check','StripeController@check');
    Route::get('stripe/charge','StripeController@charge');
    // Coinbase
    Route::get('coinbase/{payway}/{orderSN}', 'CoinbaseController@gateway');
    Route::post('coinbase/notify_url', 'CoinbaseController@notifyUrl');
    // epusdt
    Route::get('epusdt/{payway}/{orderSN}', 'EpusdtController@gateway');
    Route::post('epusdt/notify_url', 'EpusdtController@notifyUrl');
    Route::get('epusdt/return_url', 'EpusdtController@returnUrl')->name('epusdt-return');
    // tokenpay
    Route::get('tokenpay/{payway}/{orderSN}', 'TokenPayController@gateway');
    Route::post('tokenpay/notify_url', 'TokenPayController@notifyUrl');
    Route::get('tokenpay/return_url', 'TokenPayController@returnUrl')->name('tokenpay-return');

});

// Browser-facing payment pages are mirrored under /en so an English checkout
// does not silently switch back to the Chinese storefront. Provider callback
// endpoints remain at their canonical root paths below/above this group.
Route::group([
    'prefix' => 'en/pay',
    'as' => 'en.',
    'namespace' => 'Pay',
    'middleware' => ['shop.locale:en', 'dujiaoka.pay_gate_way'],
], function () {
    Route::get('newzoe/{payway}/{orderSN}', 'NewzoePayController@gateway');
    Route::get('binance/status/{orderSN}', 'BinancePayController@status');
    Route::get('binance/{payway}/{orderSN}', 'BinancePayController@gateway');
    Route::get('alipay/{payway}/{orderSN}', 'AlipayController@gateway');
    Route::get('wepay/{payway}/{orderSN}', 'WepayController@gateway');
    Route::get('mapay/{payway}/{orderSN}', 'MapayController@gateway');
    Route::get('paysapi/{payway}/{orderSN}', 'PaysapiController@gateway');
    Route::get('paysapi/return_url', 'PaysapiController@returnUrl')->name('paysapi-return');
    Route::get('payjs/{payway}/{orderSN}', 'PayjsController@gateway');
    Route::get('yipay/{payway}/{orderSN}', 'YipayController@gateway');
    Route::get('yipay/return_url', 'YipayController@returnUrl')->name('yipay-return');
    Route::get('paypal/{payway}/{orderSN}', 'PaypalPayController@gateway');
    Route::get('paypal/return_url', 'PaypalPayController@returnUrl')->name('paypal-return');
    Route::get('vpay/{payway}/{orderSN}', 'VpayController@gateway');
    Route::get('vpay/return_url', 'VpayController@returnUrl')->name('vpay-return');
    Route::get('stripe/{payway}/{oid}', 'StripeController@gateway');
    Route::get('stripe/return_url', 'StripeController@returnUrl');
    Route::get('stripe/check', 'StripeController@check');
    Route::get('stripe/charge', 'StripeController@charge');
    Route::get('coinbase/{payway}/{orderSN}', 'CoinbaseController@gateway');
    Route::get('epusdt/{payway}/{orderSN}', 'EpusdtController@gateway');
    Route::get('epusdt/return_url', 'EpusdtController@returnUrl')->name('epusdt-return');
    Route::get('tokenpay/{payway}/{orderSN}', 'TokenPayController@gateway');
    Route::get('tokenpay/return_url', 'TokenPayController@returnUrl')->name('tokenpay-return');
});
