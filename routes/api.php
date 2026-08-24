<?php

use Illuminate\Http\Request;

Route::get('newzoe/orders', 'NewzoeApiController@orders');
Route::get('telegram/webhook/health', 'TelegramWebhookController@health');
Route::post('telegram/webhook', 'TelegramWebhookController@webhook')->middleware('throttle:120,1');

Route::prefix('v1')->middleware('shop.api')->namespace('Api\\V1')->group(function () {
    Route::get('products', 'ShopApiController@products');
    Route::get('payment-methods', 'ShopApiController@paymentMethods');
    Route::post('orders', 'ShopApiController@createOrder');
    Route::get('orders/{orderSN}', 'ShopApiController@order');
    Route::post('orders/{orderSN}/pay', 'ShopApiController@pay');
    Route::get('orders/{orderSN}/delivery', 'ShopApiController@delivery');
    Route::post('orders/{orderSN}/deliver', 'ShopApiController@deliver');
});

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});
