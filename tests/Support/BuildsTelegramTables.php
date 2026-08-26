<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait BuildsTelegramTables
{
    protected function buildTelegramTables(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('email', 190)->unique();
            $table->string('password');
            $table->string('telegram_chat_id', 32)->nullable()->unique();
            $table->string('telegram_username', 64)->nullable();
            $table->string('telegram_name', 190)->nullable();
            $table->timestamp('telegram_bound_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->string('telegram_chat_id', 32)->nullable()->index();
            $table->string('order_sn', 150)->unique();
            $table->unsignedBigInteger('goods_id')->default(1);
            $table->string('title', 200);
            $table->unsignedTinyInteger('type')->default(1);
            $table->decimal('goods_price', 10, 2)->default(0);
            $table->unsignedInteger('buy_amount')->default(1);
            $table->decimal('actual_price', 10, 2)->default(0);
            $table->string('search_pwd')->default('');
            $table->string('email', 200);
            $table->text('info')->nullable();
            $table->unsignedBigInteger('pay_id')->nullable();
            $table->string('buy_ip', 50)->default('127.0.0.1');
            $table->string('trade_no')->default('');
            $table->integer('status')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('telegram_bindings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('customer_id')->index();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('failure_reason', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_order_notifications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id')->index();
            $table->string('event_key', 64);
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('next_part')->default(0);
            $table->string('last_error', 190)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'event_key']);
        });
    }
}
