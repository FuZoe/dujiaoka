<?php

namespace Tests\Unit;

use App\Models\BinancePaySetting;
use App\Models\Order;
use App\Service\BinancePayQuoteService;
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
            'services.binance_pay.amount_precision' => 2,
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

        $this->assertSame('1.375', $firstQuote->expected_usdt);
        $this->assertSame('1.375001', $secondQuote->expected_usdt);
        $this->assertSame($firstQuote->id, $refreshed->id);
        $this->assertSame('9.90', number_format((float) $firstQuote->cny_amount, 2, '.', ''));
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
