<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait BuildsBinancePayTables
{
    protected function buildBinancePayTables(): void
    {
        Schema::dropIfExists('binance_pay_attempts');
        Schema::dropIfExists('binance_pay_settings');
        Schema::dropIfExists('orders');

        Schema::create('orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('order_sn', 150)->unique();
            $table->unsignedTinyInteger('status')->default(1);
            $table->decimal('actual_price', 10, 2);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('binance_pay_settings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->text('api_key_encrypted')->nullable();
            $table->text('api_secret_encrypted')->nullable();
            $table->string('receiver_binance_id', 64)->nullable();
            $table->text('receive_qr_payload')->nullable();
            $table->string('receive_qr_image_path')->nullable();
            $table->decimal('cny_per_usdt', 18, 8)->default(7.2);
            $table->boolean('enabled')->default(false);
            $table->boolean('connection_test_ok')->default(false);
            $table->char('tested_credentials_hash', 64)->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('binance_pay_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id')->unique();
            $table->string('order_sn', 150)->index();
            $table->string('status', 20)->default('pending');
            $table->string('currency', 16)->default('USDT');
            $table->decimal('quoted_amount', 24, 8);
            $table->decimal('cny_amount', 10, 2);
            $table->decimal('rate', 18, 8);
            $table->string('transaction_id', 128)->nullable()->unique();
            $table->timestamp('transaction_time')->nullable();
            $table->timestamp('activated_at');
            $table->timestamp('expires_at');
            $table->timestamp('matched_at')->nullable();
            $table->longText('raw_transaction')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(
                ['currency', 'quoted_amount', 'expires_at'],
                'binance_attempt_amount_window_idx'
            );
        });
    }
}
