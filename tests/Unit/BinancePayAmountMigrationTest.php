<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BinancePayAmountMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('binance_pay_attempts');
        Schema::create('binance_pay_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('currency', 16);
            $table->decimal('quoted_amount', 24, 8);
            $table->timestamp('expires_at');
            $table->unique(
                ['currency', 'quoted_amount'],
                'binance_attempt_amount_unique'
            );
        });
    }

    public function test_migration_replaces_permanent_uniqueness_with_a_reservation_index(): void
    {
        require_once database_path(
            'migrations/2026_08_14_113300_recycle_binance_quote_amounts.php'
        );

        (new \RecycleBinanceQuoteAmounts())->up();

        $indexes = collect(DB::select("PRAGMA index_list('binance_pay_attempts')"))
            ->keyBy('name');
        $this->assertFalse($indexes->has('binance_attempt_amount_unique'));
        $this->assertSame(
            0,
            (int) $indexes->get('binance_attempt_amount_window_idx')->unique
        );

        $row = [
            'currency' => 'USDT',
            'quoted_amount' => '0.01000000',
            'expires_at' => now(),
        ];
        DB::table('binance_pay_attempts')->insert($row);
        DB::table('binance_pay_attempts')->insert($row);

        $this->assertSame(2, DB::table('binance_pay_attempts')->count());
    }
}
