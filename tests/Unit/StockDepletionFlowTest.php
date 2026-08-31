<?php

namespace Tests\Unit;

use App\Jobs\EmailOutOfStockNotification;
use App\Jobs\EmailStockNotification;
use App\Models\Carmis;
use App\Models\Goods;
use App\Models\Order;
use App\Service\CarmisService;
use App\Service\EmailtplService;
use App\Service\GoodsService;
use App\Service\OrderProcessService;
use App\Service\TelegramOrderNotificationService;
use App\Service\SystemSettingStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class StockDepletionFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->buildTables();
        Cache::flush();
        $settings = new \ReflectionProperty(SystemSettingStore::class, 'settings');
        $settings->setAccessible(true);
        $settings->setValue(null, null);
        Cache::forever('system-setting', [
            'is_open_email_out_of_stock' => 1,
            'email_restock_recipient' => 'fxq45@qq.com',
            'driver' => 'array',
        ]);
        Queue::fake();
    }

    public function test_automatic_fulfillment_queues_alert_after_stock_reaches_zero(): void
    {
        $this->insertProduct(Order::AUTOMATIC_DELIVERY, 0);
        $this->insertCard(1, Carmis::STATUS_UNSOLD);
        $this->insertOrder('AUTO-STOCK-ORDER', Order::AUTOMATIC_DELIVERY);
        $this->bindServices();

        $completed = (new OrderProcessService())->completedOrder(
            'AUTO-STOCK-ORDER',
            1.00,
            'AUTO-TRADE-1'
        );

        $this->assertSame(Order::STATUS_COMPLETED, (int) $completed->status);
        $this->assertSame(Carmis::STATUS_SOLD, (int) DB::table('carmis')->value('status'));
        Queue::assertPushed(EmailOutOfStockNotification::class, function (EmailOutOfStockNotification $job) {
            return $job->goodsId() === 1 && $job->recipient() === 'fxq45@qq.com';
        });
    }

    public function test_fulfillment_rollback_does_not_queue_a_false_alert(): void
    {
        $this->insertProduct(Order::AUTOMATIC_DELIVERY, 0);
        $this->insertCard(1, Carmis::STATUS_UNSOLD);
        $this->insertOrder('ROLLBACK-STOCK-ORDER', Order::AUTOMATIC_DELIVERY);

        $carmis = Mockery::mock(CarmisService::class);
        $carmis->shouldReceive('withGoodsByAmountAndStatusUnsold')->once()->andReturn([[
            'id' => 1,
            'carmi' => 'CARD-1',
            'is_loop' => 0,
        ]]);
        $carmis->shouldReceive('soldByIDS')->once()->andThrow(new \RuntimeException('simulated failure'));
        $this->bindServices($carmis);

        $completed = (new OrderProcessService())->completedOrder(
            'ROLLBACK-STOCK-ORDER',
            1.00,
            'ROLLBACK-TRADE-1'
        );

        $this->assertSame(Order::STATUS_ABNORMAL, (int) $completed->status);
        $this->assertSame(Carmis::STATUS_UNSOLD, (int) DB::table('carmis')->value('status'));
        Queue::assertNotPushed(EmailOutOfStockNotification::class);
    }

    public function test_manual_product_restock_resets_the_sold_out_cycle(): void
    {
        $this->insertProduct(Order::MANUAL_PROCESSING, 0);
        $eventKey = 'out-of-stock:goods:1';
        Cache::put(EmailStockNotification::sentCacheKey($eventKey), true, now()->addDay());
        Cache::put(EmailStockNotification::queuedCacheKey($eventKey), true, now()->addDay());

        $goods = Goods::query()->findOrFail(1);
        $goods->in_stock = 5;
        $goods->save();

        $this->assertFalse(Cache::has(EmailStockNotification::sentCacheKey($eventKey)));
        $this->assertFalse(Cache::has(EmailStockNotification::queuedCacheKey($eventKey)));
    }

    private function bindServices($carmis = null): void
    {
        $goods = Mockery::mock(GoodsService::class);
        $goods->shouldReceive('salesVolumeIncr')->zeroOrMoreTimes()->andReturn(true);
        $this->app->instance('Service\\GoodsService', $goods);
        $this->app->instance('Service\\CarmisService', $carmis ?: new CarmisService());
        $this->app->instance('Service\\CouponService', Mockery::mock());
        $this->app->instance('Service\\OrderService', Mockery::mock());
        $this->app->instance('Service\\EmailtplService', Mockery::mock(EmailtplService::class));

        $telegram = Mockery::mock(TelegramOrderNotificationService::class);
        $telegram->shouldReceive('queuePaid')->once()->andReturn(false);
        $telegram->shouldReceive('queueStatus')->once()->andReturn(false);
        $this->app->instance(TelegramOrderNotificationService::class, $telegram);
    }

    private function insertProduct(int $type, int $stock): void
    {
        DB::table('goods')->insert([
            'id' => 1,
            'gd_name' => 'Stock test product',
            'actual_price' => '1.00',
            'type' => $type,
            'in_stock' => $stock,
            'sales_volume' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertCard(int $id, int $status): void
    {
        DB::table('carmis')->insert([
            'id' => $id,
            'goods_id' => 1,
            'status' => $status,
            'is_loop' => 0,
            'carmi' => 'CARD-'.$id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertOrder(string $orderSN, int $type): void
    {
        DB::table('pays')->insert([
            'id' => 1,
            'pay_check' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('orders')->insert([
            'id' => 1,
            'order_sn' => $orderSN,
            'pay_id' => 1,
            'goods_id' => 1,
            'title' => 'Stock test order',
            'type' => $type,
            'buy_amount' => 1,
            'email' => '',
            'info' => '',
            'trade_no' => '',
            'status' => Order::STATUS_WAIT_PAY,
            'actual_price' => '1.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function buildTables(): void
    {
        Schema::create('goods', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('gd_name');
            $table->decimal('actual_price', 10, 2)->default(0);
            $table->unsignedTinyInteger('type')->default(Order::AUTOMATIC_DELIVERY);
            $table->integer('in_stock')->default(0);
            $table->integer('sales_volume')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('carmis', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('goods_id');
            $table->unsignedTinyInteger('status')->default(Carmis::STATUS_UNSOLD);
            $table->boolean('is_loop')->default(false);
            $table->string('carmi');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('pays', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('pay_check');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('order_sn')->unique();
            $table->unsignedBigInteger('pay_id')->nullable();
            $table->unsignedBigInteger('goods_id')->nullable();
            $table->unsignedBigInteger('coupon_id')->nullable();
            $table->unsignedTinyInteger('coupon_ret_back')->default(0);
            $table->string('title')->default('Test order');
            $table->unsignedTinyInteger('type')->default(Order::AUTOMATIC_DELIVERY);
            $table->unsignedInteger('buy_amount')->default(1);
            $table->string('email')->nullable();
            $table->text('info')->nullable();
            $table->string('trade_no')->default('');
            $table->unsignedTinyInteger('status')->default(Order::STATUS_WAIT_PAY);
            $table->decimal('actual_price', 10, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }
}
