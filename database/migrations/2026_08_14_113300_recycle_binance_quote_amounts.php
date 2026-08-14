<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RecycleBinanceQuoteAmounts extends Migration
{
    public function up(): void
    {
        Schema::table('binance_pay_attempts', function (Blueprint $table) {
            $table->index(
                ['currency', 'quoted_amount', 'expires_at'],
                'binance_attempt_amount_window_idx'
            );
        });
        Schema::table('binance_pay_attempts', function (Blueprint $table) {
            $table->dropUnique('binance_attempt_amount_unique');
        });
    }

    public function down(): void
    {
        $duplicates = DB::table('binance_pay_attempts')
            ->select(['currency', 'quoted_amount'])
            ->groupBy('currency', 'quoted_amount')
            ->havingRaw('COUNT(*) > 1')
            ->first();
        if ($duplicates) {
            throw new \RuntimeException(
                'Cannot restore permanent Binance amount uniqueness after an amount has been reused.'
            );
        }

        Schema::table('binance_pay_attempts', function (Blueprint $table) {
            $table->unique(
                ['currency', 'quoted_amount'],
                'binance_attempt_amount_unique'
            );
        });
        Schema::table('binance_pay_attempts', function (Blueprint $table) {
            $table->dropIndex('binance_attempt_amount_window_idx');
        });
    }
}
