<?php

namespace Tests\Unit;

use App\Models\BinancePayAttempt;
use App\Models\BinancePaySetting;
use App\Models\Order;
use App\Service\BinancePayQuoteService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsBinancePayTables;
use Tests\TestCase;

class BinancePayQuoteServiceTest extends TestCase
{
    use BuildsBinancePayTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBinancePayTables();
        config([
            'services.binance_pay.currency' => 'USDT',
            'services.binance_pay.quote_ttl_minutes' => 15,
        ]);

        $setting = new BinancePaySetting([
            'receiver_binance_id' => '12345678',
            'receive_qr_payload' => 'https://app.binance.com/uni-qr/Sg9jgWUd',
            'cny_per_usdt' => '7.20000000',
            'enabled' => true,
        ]);
        $setting->id = 1;
        $setting->setApiKey('KEY')->setApiSecret('SECRET');
        $setting->markConnectionTest(true)->save();
    }

    public function test_same_price_orders_receive_unique_quotes_and_refresh_reuses_active_quote(): void
    {
        $first = $this->order('BINANCEORDER001', '9.90');
        $second = $this->order('BINANCEORDER002', '9.90');
        $quotes = new BinancePayQuoteService();

        $firstQuote = $quotes->quote($first);
        $secondQuote = $quotes->quote($second);
        $refreshed = $quotes->quote($first);

        $this->assertSame('1.38', $firstQuote->expected_usdt);
        $this->assertSame('1.39', $secondQuote->expected_usdt);
        $this->assertSame($firstQuote->id, $refreshed->id);
        $this->assertSame('9.90', number_format((float) $firstQuote->cny_amount, 2, '.', ''));
    }

    public function test_quote_is_rounded_up_to_the_next_usdt_cent(): void
    {
        $quote = (new BinancePayQuoteService())->quote($this->order('BINANCEORDER019', '1.00'));

        $this->assertSame('0.14', $quote->expected_usdt);
    }

    public function test_whole_cent_quotes_keep_two_decimal_places(): void
    {
        $quote = (new BinancePayQuoteService())->quote($this->order('BINANCEORDER020', '1.44'));

        $this->assertSame('0.20', $quote->expected_usdt);
    }

    public function test_rounding_does_not_lose_a_fraction_beyond_intermediate_precision(): void
    {
        $setting = BinancePaySetting::query()->findOrFail(1);
        $setting->cny_per_usdt = '5.31372549';
        $setting->save();

        $quote = (new BinancePayQuoteService())->quote($this->order('BINANCEORDER021', '2.71'));

        $this->assertSame('0.52', $quote->expected_usdt);
    }

    public function test_legacy_sub_cent_quote_keeps_its_original_precision(): void
    {
        $attempt = new BinancePayAttempt(['quoted_amount' => '0.00147100']);

        $this->assertSame('0.001471', $attempt->expected_usdt);
    }

    public function test_official_receive_url_and_current_credentials_are_required(): void
    {
        $setting = BinancePaySetting::query()->findOrFail(1);
        $setting->receive_qr_payload = 'http://app.binance.com/uni-qr/Sg9jgWUd';
        $setting->save();

        $this->expectException(\RuntimeException::class);
        (new BinancePayQuoteService())->quote($this->order('BINANCEORDER011', '9.90'));
    }

    public function test_receiver_binance_id_is_required_before_quotes_can_be_created(): void
    {
        $setting = BinancePaySetting::query()->findOrFail(1);
        $setting->receiver_binance_id = null;
        $setting->save();

        $this->expectException(\RuntimeException::class);
        (new BinancePayQuoteService())->quote($this->order('BINANCEORDER013', '9.90'));
    }

    public function test_settlement_grace_does_not_consume_a_five_minute_checkout_window(): void
    {
        $now = Carbon::parse('2026-08-14 09:28:02');
        Carbon::setTestNow($now);
        Cache::forever('system-setting', ['order_expire_time' => 5]);
        config(['services.binance_pay.settlement_grace_seconds' => 300]);

        try {
            $quote = (new BinancePayQuoteService())->quote(
                $this->order('BINANCEORDER014', '0.01')
            );

            $this->assertTrue($quote->expires_at->equalTo($now->copy()->addMinutes(5)));
            $this->assertTrue($quote->expires_at->gt($now->copy()->addMinute()));
        } finally {
            Cache::forget('system-setting');
            Carbon::setTestNow();
        }
    }

    private function order(string $orderSn, string $price): Order
    {
        $id = DB::table('orders')->insertGetId([
            'order_sn' => $orderSn,
            'status' => Order::STATUS_WAIT_PAY,
            'actual_price' => $price,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Order::query()->findOrFail($id);
    }
}
