<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBinancePayAttemptsTable extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('binance_pay_attempts')) {
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
                $table->dateTime('transaction_time')->nullable();
                $table->dateTime('activated_at');
                $table->dateTime('expires_at');
                $table->dateTime('matched_at')->nullable();
                $table->longText('raw_transaction')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->index(['status', 'currency', 'quoted_amount'], 'binance_attempt_match_idx');
                $table->index(['status', 'expires_at'], 'binance_attempt_expiry_idx');
                $table->unique(['currency', 'quoted_amount'], 'binance_attempt_amount_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('binance_pay_attempts');
    }
}
