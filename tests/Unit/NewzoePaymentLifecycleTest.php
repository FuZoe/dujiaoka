<?php

namespace Tests\Unit;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\Pay\NewzoePayController;
use App\Jobs\CouponBack;
use App\Jobs\OrderExpired;
use App\Models\Order;
use App\Service\OrderProcessService;
use App\Service\TelegramOrderNotificationService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class NewzoePaymentLifecycleTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.newzoe_pay.payment_minutes' => 20,
            'services.newzoe_pay.settlement_grace_minutes' => 5,
        ]);
        Cache::forever('system-setting', ['order_expire_time' => 3]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        putenv('NEWZOE_PAY_SECRET');
        unset($_ENV['NEWZOE_PAY_SECRET'], $_SERVER['NEWZOE_PAY_SECRET']);

        parent::tearDown();
    }

    public function test_newzoe_order_expiry_is_deferred_from_payment_deadline_to_response_deadline(): void
    {
        $createdAt = Carbon::parse('2026-08-17 12:00:00');
        $order = $this->storedNewzoeOrder($createdAt);
        Carbon::setTestNow($createdAt->copy()->addMinutes(20));
        Queue::fake();

        (new OrderExpired($order->order_sn))->handle();

        $this->assertSame(Order::STATUS_WAIT_PAY, (int) $order->fresh()->status);
        Queue::assertPushed(OrderExpired::class, function (OrderExpired $job) use ($createdAt) {
            return $job->delay instanceof Carbon
                && $job->delay->equalTo($createdAt->copy()->addMinutes(25));
        });
        Queue::assertNotPushed(CouponBack::class);
    }

    public function test_newzoe_order_actually_expires_at_response_deadline(): void
    {
        $createdAt = Carbon::parse('2026-08-17 12:00:00');
        $order = $this->storedNewzoeOrder($createdAt);
        Carbon::setTestNow($createdAt->copy()->addMinutes(25));
        Queue::fake();

        (new OrderExpired($order->order_sn))->handle();

        $this->assertSame(Order::STATUS_EXPIRED, (int) $order->fresh()->status);
        Queue::assertNotPushed(OrderExpired::class);
        Queue::assertPushed(CouponBack::class, 1);
    }

    public function test_coupon_back_skips_a_queued_expiry_after_order_has_been_completed(): void
    {
        $createdAt = Carbon::parse('2026-08-17 12:00:00');
        $order = $this->storedNewzoeOrder($createdAt);
        DB::table('orders')->where('id', $order->id)->update([
            'status' => Order::STATUS_EXPIRED,
            'coupon_id' => 7,
            'coupon_ret_back' => Order::COUPON_BACK_WAIT,
        ]);
        $queuedOrder = $order->fresh();
        DB::table('orders')->where('id', $order->id)->update([
            'status' => Order::STATUS_COMPLETED,
        ]);
        $coupons = Mockery::mock();
        $coupons->shouldNotReceive('retIncrByID');
        $this->app->instance('Service\\CouponService', $coupons);

        (new CouponBack($queuedOrder))->handle();

        $persisted = $order->fresh();
        $this->assertSame(Order::STATUS_COMPLETED, (int) $persisted->status);
        $this->assertSame(Order::COUPON_BACK_WAIT, (int) $persisted->coupon_ret_back);
    }

    public function test_coupon_back_marks_an_expired_order_after_returning_its_coupon_once(): void
    {
        $createdAt = Carbon::parse('2026-08-17 12:00:00');
        $order = $this->storedNewzoeOrder($createdAt);
        DB::table('orders')->where('id', $order->id)->update([
            'status' => Order::STATUS_EXPIRED,
            'coupon_id' => 7,
            'coupon_ret_back' => Order::COUPON_BACK_WAIT,
        ]);
        $coupons = Mockery::mock();
        $coupons->shouldReceive('retIncrByID')->once()->with(7);
        $this->app->instance('Service\\CouponService', $coupons);

        (new CouponBack($order->fresh()))->handle();

        $this->assertSame(
            Order::COUPON_BACK_OK,
            (int) $order->fresh()->coupon_ret_back
        );
    }

    public function test_expired_override_reclaims_a_returned_coupon_before_fulfilment(): void
    {
        $createdAt = Carbon::parse('2026-08-17 12:00:00');
        $order = $this->storedNewzoeOrder($createdAt);
        $this->buildFulfilmentTables();
        DB::table('coupons')->insert([
            'id' => 7,
            'ret' => 3,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        DB::table('goods')->insert([
            'id' => 1,
            'gd_name' => 'Test product',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        DB::table('orders')->where('id', $order->id)->update([
            'goods_id' => 1,
            'coupon_id' => 7,
            'coupon_ret_back' => Order::COUPON_BACK_OK,
            'status' => Order::STATUS_EXPIRED,
        ]);
        Queue::fake();

        $carmis = Mockery::mock();
        $carmis->shouldReceive('withGoodsByAmountAndStatusUnsold')
            ->once()->with(1, 1)->andReturn([['id' => 11, 'carmi' => 'CARD-CODE']]);
        $carmis->shouldReceive('soldByIDS')->once()->with([11]);
        $emailTemplates = Mockery::mock();
        $emailTemplates->shouldReceive('detailByToken')
            ->once()->with('card_send_user_email')->andReturn([
                'tpl_name' => 'Order {order_id}',
                'tpl_content' => '{ord_info}',
            ]);
        $goods = Mockery::mock();
        $goods->shouldReceive('salesVolumeIncr')->once()->with(1, 1);
        $telegram = Mockery::mock();
        $telegram->shouldReceive('queuePaid')->once();
        $telegram->shouldReceive('queueStatus')->once();

        $this->app->instance('Service\\CouponService', Mockery::mock());
        $this->app->instance('Service\\OrderService', Mockery::mock());
        $this->app->instance('Service\\CarmisService', $carmis);
        $this->app->instance('Service\\EmailtplService', $emailTemplates);
        $this->app->instance('Service\\GoodsService', $goods);
        $this->app->instance(TelegramOrderNotificationService::class, $telegram);

        $completed = (new OrderProcessService())->completedOrder(
            $order->order_sn,
            4.01,
            'MANUAL-OVERRIDE-1',
            true
        );

        $this->assertSame(Order::STATUS_COMPLETED, (int) $completed->status);
        $this->assertSame(Order::COUPON_BACK_WAIT, (int) $completed->coupon_ret_back);
        $this->assertSame(2, (int) DB::table('coupons')->where('id', 7)->value('ret'));
    }

    public function test_duplicate_trade_on_pending_order_is_idempotent_without_fulfilment(): void
    {
        $order = $this->storedProcessedOrder('IDEMPOTENT-TRADE-1');
        $service = $this->orderProcessServiceWithNoExpectedFulfilment();
        Queue::fake();

        $first = $service->completedOrder($order->order_sn, 4.01, 'IDEMPOTENT-TRADE-1');
        $second = $service->completedOrder($order->order_sn, 4.01, 'IDEMPOTENT-TRADE-1');

        $this->assertSame(Order::STATUS_PENDING, (int) $first->status);
        $this->assertSame(Order::STATUS_PENDING, (int) $second->status);
        $this->assertSame('IDEMPOTENT-TRADE-1', $order->fresh()->trade_no);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_different_trade_on_pending_order_is_rejected_without_fulfilment(): void
    {
        $order = $this->storedProcessedOrder('ORIGINAL-TRADE-1');
        $service = $this->orderProcessServiceWithNoExpectedFulfilment();
        Queue::fake();

        try {
            $service->completedOrder($order->order_sn, 4.01, 'DIFFERENT-TRADE-2');
            $this->fail('A different trade number must not reuse a processed order.');
        } catch (RuleValidationException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $persisted = $order->fresh();
        $this->assertSame(Order::STATUS_PENDING, (int) $persisted->status);
        $this->assertSame('ORIGINAL-TRADE-1', $persisted->trade_no);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_empty_trade_number_does_not_make_a_processed_order_idempotent(): void
    {
        $order = $this->storedProcessedOrder('');
        $service = $this->orderProcessServiceWithNoExpectedFulfilment();
        Queue::fake();

        try {
            $service->completedOrder($order->order_sn, 4.01, '');
            $this->fail('An empty trade number must not identify a duplicate payment.');
        } catch (RuleValidationException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
        $this->assertSame('', (string) $order->fresh()->trade_no);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_callback_is_accepted_just_before_response_deadline(): void
    {
        $createdAt = Carbon::parse('2026-08-17 12:00:00');
        Carbon::setTestNow($createdAt->copy()->addMinutes(25)->subMillisecond());
        $order = $this->inMemoryOrder($createdAt);
        $processor = Mockery::mock();
        $processor->shouldReceive('completedOrder')
            ->once()
            ->with($order->order_sn, 4.01, 'WX-TRANSACTION-1', false)
            ->andReturn($order);

        $response = $this->controllerFor($order, $processor)->notifyUrl($this->signedRequest([
            'orderId' => strtolower($order->order_sn),
            'amountFen' => 401,
            'transactionId' => 'WX-TRANSACTION-1',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['accepted' => true], $response->getData(true));
    }

    public function test_callback_is_rejected_at_response_deadline(): void
    {
        $createdAt = Carbon::parse('2026-08-17 12:00:00');
        Carbon::setTestNow($createdAt->copy()->addMinutes(25));
        $order = $this->inMemoryOrder($createdAt);
        $processor = Mockery::mock();
        $processor->shouldNotReceive('completedOrder');

        $response = $this->controllerFor($order, $processor)->notifyUrl($this->signedRequest([
            'orderId' => $order->order_sn,
            'amountFen' => 401,
            'transactionId' => 'WX-TRANSACTION-2',
        ]));

        $this->assertSame(410, $response->getStatusCode());
        $this->assertSame(['error' => 'order_expired'], $response->getData(true));
    }

    public function test_callback_received_after_deadline_accepts_a_payment_matched_before_deadline(): void
    {
        $createdAt = Carbon::parse('2026-08-17 12:00:00');
        Carbon::setTestNow($createdAt->copy()->addMinutes(25)->addSecond());
        $order = $this->inMemoryOrder($createdAt, Order::STATUS_EXPIRED);
        $processor = Mockery::mock();
        $processor->shouldReceive('completedOrder')
            ->once()
            ->with($order->order_sn, 4.01, 'WX-TRANSACTION-DELAYED', true)
            ->andReturn($order);

        $response = $this->controllerFor($order, $processor)->notifyUrl($this->signedRequest([
            'orderId' => $order->order_sn,
            'amountFen' => 401,
            'matchedAt' => $createdAt->copy()->addMinutes(25)->subMillisecond()->toIso8601String(),
            'transactionId' => 'WX-TRANSACTION-DELAYED',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['accepted' => true], $response->getData(true));
    }

    public function test_callback_received_after_deadline_rejects_a_late_payment_timestamp(): void
    {
        $createdAt = Carbon::parse('2026-08-17 12:00:00');
        Carbon::setTestNow($createdAt->copy()->addMinutes(25)->addSecond());
        $order = $this->inMemoryOrder($createdAt, Order::STATUS_EXPIRED);
        $processor = Mockery::mock();
        $processor->shouldNotReceive('completedOrder');

        $response = $this->controllerFor($order, $processor)->notifyUrl($this->signedRequest([
            'orderId' => $order->order_sn,
            'amountFen' => 401,
            'matchedAt' => $createdAt->copy()->addMinutes(25)->toIso8601String(),
            'transactionId' => 'WX-TRANSACTION-TOO-LATE',
        ]));

        $this->assertSame(410, $response->getStatusCode());
        $this->assertSame(['error' => 'order_expired'], $response->getData(true));
    }

    public function test_signed_manual_override_accepts_an_expired_order(): void
    {
        $createdAt = Carbon::parse('2026-08-17 12:00:00');
        Carbon::setTestNow($createdAt->copy()->addMinutes(40));
        $order = $this->inMemoryOrder($createdAt, Order::STATUS_EXPIRED);
        $processor = Mockery::mock();
        $processor->shouldReceive('completedOrder')
            ->once()
            ->with($order->order_sn, 4.01, 'MANUAL-TRANSACTION-1', true)
            ->andReturn($order);

        $response = $this->controllerFor($order, $processor)->notifyUrl($this->signedRequest([
            'orderId' => $order->order_sn,
            'amountFen' => 401,
            'transactionId' => 'MANUAL-TRANSACTION-1',
            'manualOverride' => true,
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['accepted' => true], $response->getData(true));
    }

    public function test_signed_manual_override_accepts_a_waiting_order_after_response_deadline(): void
    {
        $createdAt = Carbon::parse('2026-08-17 12:00:00');
        Carbon::setTestNow($createdAt->copy()->addMinutes(40));
        $order = $this->inMemoryOrder($createdAt);
        $processor = Mockery::mock();
        $processor->shouldReceive('completedOrder')
            ->once()
            ->with($order->order_sn, 4.01, 'MANUAL-TRANSACTION-2', true)
            ->andReturn($order);

        $response = $this->controllerFor($order, $processor)->notifyUrl($this->signedRequest([
            'orderId' => $order->order_sn,
            'amountFen' => 401,
            'transactionId' => 'MANUAL-TRANSACTION-2',
            'manualOverride' => true,
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['accepted' => true], $response->getData(true));
    }

    public function test_regular_callback_still_rejects_an_expired_order(): void
    {
        $createdAt = Carbon::parse('2026-08-17 12:00:00');
        Carbon::setTestNow($createdAt->copy()->addMinutes(40));
        $order = $this->inMemoryOrder($createdAt, Order::STATUS_EXPIRED);
        $processor = Mockery::mock();
        $processor->shouldNotReceive('completedOrder');

        $response = $this->controllerFor($order, $processor)->notifyUrl($this->signedRequest([
            'orderId' => $order->order_sn,
            'amountFen' => 401,
            'transactionId' => 'WX-TRANSACTION-LATE',
        ]));

        $this->assertSame(410, $response->getStatusCode());
        $this->assertSame(['error' => 'order_expired'], $response->getData(true));
    }

    private function storedNewzoeOrder(Carbon $createdAt): Order
    {
        $this->buildExpiryTables();
        $payId = DB::table('pays')->insertGetId([
            'pay_check' => 'newzoe-wechat',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'order_sn' => 'NEWZOEORDER001',
            'pay_id' => $payId,
            'status' => Order::STATUS_WAIT_PAY,
            'actual_price' => '4.01',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return Order::query()->findOrFail($orderId);
    }

    private function buildExpiryTables(): void
    {
        Schema::create('pays', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('pay_check');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('order_sn', 150)->unique();
            $table->unsignedBigInteger('pay_id')->nullable();
            $table->unsignedBigInteger('goods_id')->nullable();
            $table->unsignedBigInteger('coupon_id')->nullable();
            $table->unsignedTinyInteger('coupon_ret_back')->default(Order::COUPON_BACK_WAIT);
            $table->string('title')->default('Test order');
            $table->unsignedTinyInteger('type')->default(Order::AUTOMATIC_DELIVERY);
            $table->unsignedInteger('buy_amount')->default(1);
            $table->string('email')->default('buyer@example.test');
            $table->text('info')->nullable();
            $table->string('trade_no')->default('');
            $table->unsignedTinyInteger('status')->default(Order::STATUS_WAIT_PAY);
            $table->decimal('actual_price', 10, 2);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('binance_pay_attempts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id');
            $table->string('status', 20);
            $table->timestamp('expires_at')->nullable();
        });
    }

    private function buildFulfilmentTables(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('ret')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('goods', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('gd_name');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function storedProcessedOrder(string $tradeNo): Order
    {
        $createdAt = Carbon::parse('2026-08-17 12:00:00');
        $order = $this->storedNewzoeOrder($createdAt);
        $this->buildFulfilmentTables();
        DB::table('goods')->insert([
            'id' => 1,
            'gd_name' => 'Already processed product',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        DB::table('orders')->where('id', $order->id)->update([
            'goods_id' => 1,
            'status' => Order::STATUS_PENDING,
            'trade_no' => $tradeNo,
        ]);

        return $order->fresh();
    }

    private function orderProcessServiceWithNoExpectedFulfilment(): OrderProcessService
    {
        $carmis = Mockery::mock();
        $carmis->shouldNotReceive('withGoodsByAmountAndStatusUnsold');
        $carmis->shouldNotReceive('soldByIDS');
        $emailTemplates = Mockery::mock();
        $emailTemplates->shouldNotReceive('detailByToken');
        $goods = Mockery::mock();
        $goods->shouldNotReceive('inStockDecr');
        $goods->shouldNotReceive('salesVolumeIncr');

        $this->app->instance('Service\\CouponService', Mockery::mock());
        $this->app->instance('Service\\OrderService', Mockery::mock());
        $this->app->instance('Service\\CarmisService', $carmis);
        $this->app->instance('Service\\EmailtplService', $emailTemplates);
        $this->app->instance('Service\\GoodsService', $goods);

        return new OrderProcessService();
    }

    private function inMemoryOrder(Carbon $createdAt, int $status = Order::STATUS_WAIT_PAY): Order
    {
        $order = new Order();
        $order->order_sn = 'NEWZOECALLBACK001';
        $order->status = $status;
        $order->actual_price = '4.01';
        $order->created_at = $createdAt;

        return $order;
    }

    private function controllerFor(Order $order, $processor): NewzoePayController
    {
        $orders = Mockery::mock();
        $orders->shouldReceive('detailOrderSN')
            ->once()
            ->with($order->order_sn)
            ->andReturn($order);

        $this->app->instance('Service\\OrderService', $orders);
        $this->app->instance('Service\\PayService', Mockery::mock());
        $this->app->instance('Service\\OrderProcessService', $processor);

        return new NewzoePayController();
    }

    private function signedRequest(array $payload): Request
    {
        putenv('NEWZOE_PAY_SECRET='.self::SECRET);
        $_ENV['NEWZOE_PAY_SECRET'] = self::SECRET;
        $_SERVER['NEWZOE_PAY_SECRET'] = self::SECRET;

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) round(microtime(true) * 1000);
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET);

        return Request::create('/pay/newzoe/notify_url', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOP_SIGNATURE' => $signature,
            'HTTP_X_SHOP_TIMESTAMP' => $timestamp,
        ], $body);
    }
}
