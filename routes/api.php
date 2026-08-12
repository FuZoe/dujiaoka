<?php

use Illuminate\Http\Request;

Route::get('newzoe/orders', 'NewzoeApiController@orders');
Route::get('telegram/webhook/health', 'TelegramWebhookController@health');
Route::post('telegram/webhook', 'TelegramWebhookController@webhook')->middleware('throttle:120,1');

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
