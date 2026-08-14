<?php

namespace Tests\Unit;

use App\Jobs\CouponBack;
use App\Jobs\OrderExpired;
use App\Models\BinancePayAttempt;
use App\Models\BinancePaySetting;
use App\Models\Order;
use App\Service\BinancePayClient;
use App\Service\BinancePayMatcher;
use App\Service\OrderProcessService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Support\BuildsBinancePayTables;
use Tests\TestCase;

class BinancePayMatcherTest extends TestCase
{
    use BuildsBinancePayTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBinancePayTables();
    }

    public function test_match_completes_order_with_original_cny_amount_and_records_transaction_once(): void
    {
        $now = Carbon::now();
        $this->readySetting();
        $order = $this->order('BINANCEORDER003', '9.90');
        $this->attempt($order, '1.38000000', $now->copy()->subMinute(), $now->copy()->addMinutes(10));
        $transaction = $this->transaction('M_P_TEST_TRANSACTION_1', '1.38000000', $now);
        $client = Mockery::mock(BinancePayClient::class);
        $expectedStart = ($now->copy()->subMinute()->getTimestamp() - 5) * 1000;
        $client->shouldReceive('transactions')->once()->withArgs(function ($settingArg, $startTime, $endTime, $limit) use ($expectedStart, $now) {
            return $settingArg instanceof BinancePaySetting
                && $startTime === $expectedStart
                && $endTime >= $now->getTimestamp() * 1000
                && $endTime <= ($now->getTimestamp() + 5) * 1000
                && $limit === 100;
        })->andReturn([$transaction]);
        $orders = Mockery::mock(OrderProcessService::class);
        $orders->shouldReceive('completedOrder')->once()->withArgs(function ($orderSn, $amount, $tradeNo) {
            return $orderSn === 'BINANCEORDER003'
                && abs($amount - 9.90) < 0.0001
                && $tradeNo === 'M_P_TEST_TRANSACTION_1';
        });

        $result = (new BinancePayMatcher($client, $orders))->poll();

        $this->assertSame(1, $result['matched']);
        $attempt = BinancePayAttempt::query()->firstOrFail();
        $this->assertSame(BinancePayAttempt::STATUS_PAID, $attempt->status);
        $this->assertSame('M_P_TEST_TRANSACTION_1', $attempt->transaction_id);
    }

    public function test_poll_skips_when_credentials_have_not_passed_connection_test(): void
    {
        $setting = new BinancePaySetting(['enabled' => true]);
        $setting->id = 1;
        $setting->setApiKey('KEY')->setApiSecret('SECRET')->save();

        $client = Mockery::mock(BinancePayClient::class);
        $client->shouldNotReceive('transactions');
        $orders = Mockery::mock(OrderProcessService::class);

        $result = (new BinancePayMatcher($client, $orders))->poll();

        $this->assertTrue($result['skipped']);
    }

    public function test_outgoing_wrong_asset_wrong_receiver_and_unsupported_transactions_are_ignored(): void
    {
        $now = Carbon::now();
        $this->readySetting();
        $order = $this->order('BINANCEORDER004', '9.90');
        $this->attempt($order, '1.38000000', $now->copy()->subMinute(), $now->copy()->addMinutes(10));

        $outgoing = $this->transaction('TX_OUTGOING', '-1.38000000', $now);
        $wrongAsset = $this->transaction('TX_WRONG_ASSET', '1.38000000', $now);
        $wrongAsset['currency'] = 'BUSD';
        $wrongReceiver = $this->transaction('TX_WRONG_RECEIVER', '1.38000000', $now);
        $wrongReceiver['receiverInfo']['binanceId'] = '87654321';
        $unsupported = $this->transaction('TX_UNSUPPORTED', '1.38000000', $now);
        $unsupported['orderType'] = 'PAY_REFUND';

        $client = Mockery::mock(BinancePayClient::class);
        $client->shouldReceive('transactions')->once()->andReturn([
            $outgoing,
            $wrongAsset,
            $wrongReceiver,
            $unsupported,
        ]);
        $orders = Mockery::mock(OrderProcessService::class);
        $orders->shouldNotReceive('completedOrder');

        $result = (new BinancePayMatcher($client, $orders))->poll();

        $this->assertSame(0, $result['matched']);
        $this->assertSame(BinancePayAttempt::STATUS_PENDING, BinancePayAttempt::query()->firstOrFail()->status);
        $this->assertSame(Order::STATUS_WAIT_PAY, (int) Order::query()->findOrFail($order->id)->status);
    }

    public function test_duplicate_transaction_id_is_never_applied_to_another_order(): void
    {
        $now = Carbon::now();
        $this->readySetting();
        $paidOrder = $this->order('BINANCEORDER005', '7.20', Order::STATUS_COMPLETED);
        $paidAttempt = $this->attempt($paidOrder, '1.00000000', $now->copy()->subMinutes(2), $now->copy()->addMinutes(8));
        $paidAttempt->status = BinancePayAttempt::STATUS_PAID;
        $paidAttempt->transaction_id = 'TX_DUPLICATE';
        $paidAttempt->save();
        $pendingOrder = $this->order('BINANCEORDER006', '9.90');
        $this->attempt($pendingOrder, '1.38000000', $now->copy()->subMinute(), $now->copy()->addMinutes(10));

        $client = Mockery::mock(BinancePayClient::class);
        $client->shouldReceive('transactions')->once()->andReturn([
            $this->transaction('TX_DUPLICATE', '1.38000000', $now),
        ]);
        $orders = Mockery::mock(OrderProcessService::class);
        $orders->shouldNotReceive('completedOrder');

        $result = (new BinancePayMatcher($client, $orders))->poll();

        $this->assertSame(0, $result['matched']);
        $this->assertSame(BinancePayAttempt::STATUS_PENDING, BinancePayAttempt::query()->where('order_id', $pendingOrder->id)->firstOrFail()->status);
    }

    public function test_payment_after_quote_expiry_is_recorded_for_manual_review(): void
    {
        $now = Carbon::now();
        $this->readySetting();
        $order = $this->order('BINANCEORDER007', '9.90');
        $this->attempt($order, '1.38000000', $now->copy()->subMinutes(10), $now->copy()->subMinute());
        $client = Mockery::mock(BinancePayClient::class);
        $client->shouldReceive('transactions')->once()->andReturn([
            $this->transaction('TX_LATE', '1.38000000', $now),
        ]);
        $orders = Mockery::mock(OrderProcessService::class);
        $orders->shouldNotReceive('completedOrder');

        $result = (new BinancePayMatcher($client, $orders))->poll();
        $attempt = BinancePayAttempt::query()->firstOrFail();

        $this->assertSame(1, $result['manual_review']);
        $this->assertSame(BinancePayAttempt::STATUS_MANUAL_REVIEW, $attempt->status);
        $this->assertSame('TX_LATE', $attempt->transaction_id);
        $this->assertSame(Order::STATUS_WAIT_PAY, (int) Order::query()->findOrFail($order->id)->status);
    }

    public function test_on_time_payment_discovered_after_expiry_still_completes(): void
    {
        $now = Carbon::now();
        $this->readySetting();
        $order = $this->order('BINANCEORDER008', '9.90');
        $attempt = $this->attempt($order, '1.38000000', $now->copy()->subMinutes(10), $now->copy()->subMinute());
        $attempt->status = BinancePayAttempt::STATUS_EXPIRED;
        $attempt->save();
        $client = Mockery::mock(BinancePayClient::class);
        $client->shouldReceive('transactions')->once()->andReturn([
            $this->transaction('TX_ON_TIME_DELAYED', '1.38000000', $now->copy()->subMinutes(2)),
        ]);
        $orders = Mockery::mock(OrderProcessService::class);
        $orders->shouldReceive('completedOrder')->once();

        $result = (new BinancePayMatcher($client, $orders))->poll();

        $this->assertSame(1, $result['matched']);
        $this->assertSame(BinancePayAttempt::STATUS_PAID, $attempt->fresh()->status);
    }

    public function test_order_expiry_is_deferred_while_an_on_time_payment_settles(): void
    {
        $expiresAt = Carbon::parse('2026-08-14 09:33:02');
        Carbon::setTestNow($expiresAt);
        Queue::fake();
        config([
            'services.binance_pay.settlement_grace_seconds' => 300,
            'services.binance_pay.poll_interval_seconds' => 60,
        ]);

        try {
            $this->readySetting();
            $order = $this->order('BINANCEORDER015', '0.01');
            $attempt = $this->attempt(
                $order,
                '0.00147100',
                $expiresAt->copy()->subMinutes(5),
                $expiresAt
            );

            (new OrderExpired($order->order_sn))->handle();

            $this->assertSame(
                Order::STATUS_WAIT_PAY,
                (int) $order->fresh()->status
            );
            Queue::assertPushed(OrderExpired::class, function (OrderExpired $job) use ($expiresAt) {
                return $job->delay instanceof Carbon
                    && $job->delay->equalTo($expiresAt->copy()->addMinutes(6));
            });
            Queue::assertNotPushed(CouponBack::class);

            Carbon::setTestNow($expiresAt->copy()->addMinute());
            $client = Mockery::mock(BinancePayClient::class);
            $client->shouldReceive('transactions')->once()->andReturn([
                $this->transaction(
                    'TX_ON_TIME_AFTER_SHOP_EXPIRY',
                    '0.00147100',
                    $expiresAt->copy()->subSecond()
                ),
            ]);
            $orders = Mockery::mock(OrderProcessService::class);
            $orders->shouldReceive('completedOrder')->once();

            $result = (new BinancePayMatcher($client, $orders))->poll();

            $this->assertSame(1, $result['matched']);
            $this->assertSame(BinancePayAttempt::STATUS_PAID, $attempt->fresh()->status);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_unpaid_binance_order_expires_after_the_settlement_window(): void
    {
        $now = Carbon::parse('2026-08-14 09:39:03');
        Carbon::setTestNow($now);
        Queue::fake();
        config([
            'services.binance_pay.settlement_grace_seconds' => 300,
            'services.binance_pay.poll_interval_seconds' => 60,
        ]);

        try {
            $order = $this->order('BINANCEORDER016', '0.01');
            $this->attempt(
                $order,
                '0.00147200',
                $now->copy()->subMinutes(11),
                $now->copy()->subMinutes(6)->subSecond()
            );

            (new OrderExpired($order->order_sn))->handle();

            $this->assertSame(Order::STATUS_EXPIRED, (int) $order->fresh()->status);
            Queue::assertNotPushed(OrderExpired::class);
            Queue::assertPushed(CouponBack::class, 1);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_expiry_update_does_not_overwrite_a_processing_order(): void
    {
        $order = $this->order('BINANCEORDER017', '0.01', Order::STATUS_PROCESSING);

        $updated = app('Service\OrderService')->expiredOrderSN($order->order_sn);

        $this->assertFalse($updated);
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
    }

    public function test_processing_order_keeps_an_expiry_check_after_settlement_deadline(): void
    {
        $now = Carbon::parse('2026-08-14 09:45:03');
        Carbon::setTestNow($now);
        Queue::fake();
        config([
            'services.binance_pay.settlement_grace_seconds' => 300,
            'services.binance_pay.poll_interval_seconds' => 60,
        ]);

        try {
            $order = $this->order('BINANCEORDER018', '0.01', Order::STATUS_PROCESSING);
            $attempt = $this->attempt(
                $order,
                '0.00147300',
                $now->copy()->subMinutes(11),
                $now->copy()->subMinutes(6)->subSecond()
            );
            $attempt->status = BinancePayAttempt::STATUS_PROCESSING;
            $attempt->save();

            (new OrderExpired($order->order_sn))->handle();

            $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
            Queue::assertPushed(OrderExpired::class, function (OrderExpired $job) use ($now) {
                return $job->delay instanceof Carbon
                    && $job->delay->equalTo($now->copy()->addMinute());
            });
            Queue::assertNotPushed(CouponBack::class);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_fulfilment_failure_releases_claim_for_retry(): void
    {
        $now = Carbon::now();
        $this->readySetting();
        $order = $this->order('BINANCEORDER009', '9.90');
        $attempt = $this->attempt($order, '1.38000000', $now->copy()->subMinute(), $now->copy()->addMinutes(10));
        $client = Mockery::mock(BinancePayClient::class);
        $client->shouldReceive('transactions')->once()->andReturn([
            $this->transaction('TX_RETRY', '1.38000000', $now),
        ]);
        $orders = Mockery::mock(OrderProcessService::class);
        $orders->shouldReceive('completedOrder')->once()->andThrow(new \RuntimeException('fixture fulfilment failure'));

        $result = (new BinancePayMatcher($client, $orders))->poll();
        $attempt = $attempt->fresh();

        $this->assertSame(0, $result['matched']);
        $this->assertSame(BinancePayAttempt::STATUS_PENDING, $attempt->status);
        $this->assertNull($attempt->transaction_id);
        $this->assertSame(Order::STATUS_WAIT_PAY, (int) Order::query()->findOrFail($order->id)->status);
        $this->assertStringContainsString('fixture fulfilment failure', $attempt->last_error);
    }

    public function test_stale_processing_claim_is_recovered_and_retried(): void
    {
        $now = Carbon::now();
        $this->readySetting();
        $order = $this->order('BINANCEORDER010', '9.90', Order::STATUS_PROCESSING);
        $attempt = $this->attempt($order, '1.38000000', $now->copy()->subMinutes(10), $now->copy()->addMinutes(10));
        $attempt->status = BinancePayAttempt::STATUS_PROCESSING;
        $attempt->transaction_id = 'TX_INTERRUPTED';
        $attempt->save();
        DB::table('binance_pay_attempts')->where('id', $attempt->id)->update(['updated_at' => $now->copy()->subMinutes(6)]);
        $client = Mockery::mock(BinancePayClient::class);
        $client->shouldReceive('transactions')->once()->andReturn([
            $this->transaction('TX_INTERRUPTED', '1.38000000', $now),
        ]);
        $orders = Mockery::mock(OrderProcessService::class);
        $orders->shouldReceive('completedOrder')->once();

        $result = (new BinancePayMatcher($client, $orders))->poll();

        $this->assertSame(1, $result['matched']);
        $this->assertSame(BinancePayAttempt::STATUS_PAID, $attempt->fresh()->status);
    }

    public function test_full_transaction_window_is_split_so_newer_payment_is_not_hidden(): void
    {
        $now = Carbon::now();
        $this->readySetting();
        $order = $this->order('BINANCEORDER012', '9.90');
        $this->attempt($order, '1.38000000', $now->copy()->subMinute(), $now->copy()->addMinutes(10));

        $fullWindow = [];
        for ($index = 0; $index < 100; $index++) {
            $row = $this->transaction('TX_OLD_'.$index, '2.00000000', $now->copy()->subSeconds(50 - ($index % 40)));
            $row['orderType'] = 'PAY_REFUND';
            $fullWindow[] = $row;
        }

        $client = Mockery::mock(BinancePayClient::class);
        $client->shouldReceive('transactions')->times(3)->andReturn(
            $fullWindow,
            [],
            [$this->transaction('TX_AFTER_SPLIT', '1.38000000', $now)]
        );
        $orders = Mockery::mock(OrderProcessService::class);
        $orders->shouldReceive('completedOrder')->once();

        $result = (new BinancePayMatcher($client, $orders))->poll();

        $this->assertSame(1, $result['matched']);
        $this->assertSame('TX_AFTER_SPLIT', BinancePayAttempt::query()->firstOrFail()->transaction_id);
    }

    public function test_expired_attempts_outside_settlement_grace_are_not_polled_again(): void
    {
        $now = Carbon::now();
        config(['services.binance_pay.settlement_grace_seconds' => 300]);
        $this->readySetting();
        $order = $this->order('BINANCEORDER013', '9.90');
        $attempt = $this->attempt(
            $order,
            '1.38000000',
            $now->copy()->subMinutes(20),
            $now->copy()->subMinutes(6)
        );
        $attempt->status = BinancePayAttempt::STATUS_EXPIRED;
        $attempt->save();

        $client = Mockery::mock(BinancePayClient::class);
        $client->shouldNotReceive('transactions');
        $orders = Mockery::mock(OrderProcessService::class);
        $orders->shouldNotReceive('completedOrder');

        $result = (new BinancePayMatcher($client, $orders))->poll();

        $this->assertSame(0, $result['checked']);
        $this->assertSame(BinancePayAttempt::STATUS_EXPIRED, $attempt->fresh()->status);
    }

    public function test_recycled_amount_matches_the_current_attempt_not_historical_attempt(): void
    {
        $now = Carbon::now();
        config([
            'services.binance_pay.settlement_grace_seconds' => 300,
            'services.binance_pay.poll_interval_seconds' => 60,
        ]);
        $this->readySetting();
        $historicalOrder = $this->order('BINANCEORDER026', '9.90', Order::STATUS_EXPIRED);
        $historicalAttempt = $this->attempt(
            $historicalOrder,
            '1.38000000',
            $now->copy()->subMinutes(12),
            $now->copy()->subMinutes(7)
        );
        $historicalAttempt->status = BinancePayAttempt::STATUS_EXPIRED;
        $historicalAttempt->save();
        $currentOrder = $this->order('BINANCEORDER027', '9.90');
        $currentAttempt = $this->attempt(
            $currentOrder,
            '1.38000000',
            $now->copy()->subMinute(),
            $now->copy()->addMinutes(4)
        );

        $client = Mockery::mock(BinancePayClient::class);
        $client->shouldReceive('transactions')->once()->andReturn([
            $this->transaction('TX_RECYCLED_AMOUNT', '1.38000000', $now),
        ]);
        $orders = Mockery::mock(OrderProcessService::class);
        $orders->shouldReceive('completedOrder')->once()->withArgs(function ($orderSn) {
            return $orderSn === 'BINANCEORDER027';
        });

        $result = (new BinancePayMatcher($client, $orders))->poll();

        $this->assertSame(1, $result['matched']);
        $this->assertSame(BinancePayAttempt::STATUS_EXPIRED, $historicalAttempt->fresh()->status);
        $this->assertSame(BinancePayAttempt::STATUS_PAID, $currentAttempt->fresh()->status);
    }

    public function test_full_page_at_one_timestamp_fails_instead_of_silently_skipping_rows(): void
    {
        $this->readySetting();
        $client = Mockery::mock(BinancePayClient::class);
        $client->shouldReceive('transactions')->once()->withArgs(function ($setting, $start, $end, $limit) {
            return $setting instanceof BinancePaySetting && $start === 1000 && $end === 1000 && $limit === 100;
        })->andReturn($this->fullPageAtTimestamp());
        $matcher = new BinancePayMatcher($client, Mockery::mock(OrderProcessService::class));
        $method = new \ReflectionMethod(BinancePayMatcher::class, 'transactionsInWindow');
        $method->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('indivisible transaction timestamp');
        $method->invoke($matcher, BinancePaySetting::current(), 1000, 1000);
    }

    private function readySetting(): BinancePaySetting
    {
        $setting = new BinancePaySetting([
            'receiver_binance_id' => '12345678',
            'receive_qr_payload' => 'https://app.binance.com/uni-qr/Sg9jgWUd',
            'cny_per_usdt' => '7.20000000',
            'enabled' => true,
        ]);
        $setting->id = 1;
        $setting->setApiKey('KEY')->setApiSecret('SECRET');
        $setting->markConnectionTest(true)->save();

        return $setting;
    }

    private function order(string $orderSn, string $price, int $status = Order::STATUS_WAIT_PAY): Order
    {
        $id = DB::table('orders')->insertGetId([
            'order_sn' => $orderSn,
            'status' => $status,
            'actual_price' => $price,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        return Order::query()->findOrFail($id);
    }

    private function attempt(Order $order, string $amount, Carbon $activatedAt, Carbon $expiresAt): BinancePayAttempt
    {
        return BinancePayAttempt::query()->create([
            'order_id' => $order->id,
            'order_sn' => $order->order_sn,
            'status' => BinancePayAttempt::STATUS_PENDING,
            'currency' => 'USDT',
            'quoted_amount' => $amount,
            'cny_amount' => (string) $order->actual_price,
            'rate' => '7.20000000',
            'activated_at' => $activatedAt,
            'expires_at' => $expiresAt,
        ]);
    }

    private function transaction(string $id, string $amount, Carbon $paidAt): array
    {
        return [
            'orderType' => 'PAY',
            'transactionId' => $id,
            'transactionTime' => $paidAt->getTimestamp() * 1000,
            'amount' => $amount,
            'currency' => 'USDT',
            'receiverInfo' => ['binanceId' => '12345678', 'type' => 'USER'],
        ];
    }

    private function fullPageAtTimestamp(): array
    {
        $transactions = [];
        for ($index = 0; $index < 100; $index++) {
            $transactions[] = $this->transaction('TX_SAME_MILLISECOND_'.$index, '1.00000000', Carbon::createFromTimestampMs(1000));
        }

        return $transactions;
    }
}
