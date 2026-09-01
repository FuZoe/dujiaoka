<?php

namespace Tests\Unit;

use App\Exceptions\WarzoneApiException;
use App\Jobs\WarzoneFulfillOrder;
use App\Models\Carmis;
use App\Models\Goods;
use App\Models\Order;
use App\Models\WarzoneSupplierPurchase;
use App\Models\WarzoneSupplierSetting;
use App\Service\CarmisService;
use App\Service\EmailtplService;
use App\Service\GoodsService;
use App\Service\OrderProcessService;
use App\Service\SystemSettingStore;
use App\Service\TelegramOrderNotificationService;
use App\Service\WarzoneInventoryService;
use App\Service\WarzoneShopClient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class WarzoneInventoryAndFulfillmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->buildTables();
        Cache::flush();
        Queue::fake();
        $settings = new \ReflectionProperty(SystemSettingStore::class, 'settings');
        $settings->setAccessible(true);
        $settings->setValue(null, null);
        Cache::forever('system-setting', [
            'is_open_email_out_of_stock' => 0,
            'is_open_server_jiang' => 0,
            'is_open_bark_push' => 0,
            'is_open_qywxbot_push' => 0,
        ]);
    }

    public function test_available_stock_uses_the_higher_cost_and_subtracts_pending_reservations(): void
    {
        $this->insertGoods();
        $this->insertCard('LOCAL-ONE');
        $this->insertCard('LOCAL-TWO');
        $setting = $this->readySetting('0.4000');
        WarzoneSupplierPurchase::query()->create([
            'setting_id' => $setting->id,
            'goods_id' => 16,
            'order_id' => 901,
            'order_sn' => 'RESERVED-ORDER',
            'service_id' => 'S_01',
            'quantity' => 2,
            'status' => WarzoneSupplierPurchase::STATUS_QUEUED,
            'unit_cost_usd' => '0.5000',
            'total_cost_usd' => '1.0000',
        ]);

        $client = Mockery::mock(WarzoneShopClient::class);
        $client->shouldReceive('snapshot')->once()->andReturn($this->snapshot('5.00', '0.50', 20));
        $goods = Goods::query()->withCount(['carmis' => function ($query) {
            $query->where('status', Carmis::STATUS_UNSOLD);
        }])->findOrFail(16);
        $inventory = new WarzoneInventoryService($client);

        $inventory->augment($goods);

        // (5.00 - 1.00) / max(0.40, 0.50) = 8 supplier units, plus two local cards.
        $this->assertSame(2, (int) $goods->local_in_stock);
        $this->assertSame(8, (int) $goods->supplier_in_stock);
        $this->assertSame(10, (int) $goods->in_stock);
        $this->assertSame('5', (string) $setting->fresh()->last_balance_usd);
        $this->assertSame(20, (int) $setting->fresh()->last_supplier_stock);
    }

    public function test_goods_without_an_explicit_supplier_setting_stays_local_only(): void
    {
        $this->insertGoods();
        $this->insertCard('LOCAL-ONLY');
        $client = Mockery::mock(WarzoneShopClient::class);
        $client->shouldNotReceive('snapshot');
        $goods = Goods::query()->withCount(['carmis' => function ($query) {
            $query->where('status', Carmis::STATUS_UNSOLD);
        }])->findOrFail(16);

        (new WarzoneInventoryService($client))->augment($goods);

        $this->assertSame(1, (int) $goods->in_stock);
        $this->assertSame(0, WarzoneSupplierSetting::query()->count());
    }

    public function test_paid_order_with_insufficient_local_stock_is_queued_once(): void
    {
        $this->insertGoods();
        $this->insertPay();
        $this->insertOrder(Order::STATUS_WAIT_PAY, 2);
        $this->readySetting();
        $this->bindFulfillmentServices();

        $settled = (new OrderProcessService())->completedOrder(
            'WARZONE-SHOP-ORDER',
            1.00,
            'PAYMENT-ONE'
        );

        $this->assertSame(Order::STATUS_PROCESSING, (int) $settled->status);
        $this->assertSame('PAYMENT-ONE', (string) $settled->trade_no);
        $this->assertSame(1, WarzoneSupplierPurchase::query()->count());
        $purchase = WarzoneSupplierPurchase::query()->firstOrFail();
        $this->assertSame(2, (int) $purchase->quantity);
        Queue::assertPushed(WarzoneFulfillOrder::class, function (WarzoneFulfillOrder $job) use ($purchase) {
            return $job->purchaseId() === (int) $purchase->id;
        });

        // A duplicate callback reuses the unique purchase record.
        (new OrderProcessService())->completedOrder(
            'WARZONE-SHOP-ORDER',
            1.00,
            'PAYMENT-ONE'
        );
        $this->assertSame(1, WarzoneSupplierPurchase::query()->count());
    }

    public function test_job_purchases_only_the_shortage_then_finishes_normal_local_delivery(): void
    {
        $this->insertGoods();
        $this->insertPay();
        $order = $this->insertOrder(Order::STATUS_PROCESSING, 2);
        $this->insertCard('LOCAL-CARD');
        $setting = $this->readySetting();
        $purchase = $this->insertPurchase($setting, $order, 1);
        $this->bindFulfillmentServices();

        $client = Mockery::mock(WarzoneShopClient::class);
        $client->shouldReceive('snapshot')->once()->andReturn($this->snapshot('4.00', '0.40', 7));
        $client->shouldReceive('order')->once()->with(Mockery::type(WarzoneSupplierSetting::class), 1)
            ->andReturn([
                'order_id' => 'ORD-10001-abcd',
                'service_id' => 'S_01',
                'quantity' => 1,
                'unit_price' => '0.4',
                'total_cost' => '0.4',
                'new_balance' => '3.6',
                'products' => ['REMOTE-CARD'],
            ]);

        (new WarzoneFulfillOrder((int) $purchase->id))->handle($client);

        $order = $order->fresh();
        $purchase = $purchase->fresh();
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->status);
        $this->assertSame(WarzoneSupplierPurchase::STATUS_COMPLETED, $purchase->status);
        $this->assertSame('ORD-10001-abcd', $purchase->provider_order_id);
        $this->assertSame(['REMOTE-CARD'], $purchase->getProducts());
        $this->assertStringContainsString('LOCAL-CARD', (string) $order->info);
        $this->assertStringContainsString('REMOTE-CARD', (string) $order->info);
        $this->assertSame(2, Carmis::query()->where('status', Carmis::STATUS_SOLD)->count());
        $this->assertSame('3.6', (string) $setting->fresh()->last_balance_usd);
    }

    public function test_ambiguous_post_result_is_never_posted_again(): void
    {
        $this->insertGoods();
        $this->insertPay();
        $order = $this->insertOrder(Order::STATUS_PROCESSING, 1);
        $setting = $this->readySetting();
        $purchase = $this->insertPurchase($setting, $order, 1);

        $client = Mockery::mock(WarzoneShopClient::class);
        $client->shouldReceive('snapshot')->once()->andReturn($this->snapshot('4.00', '0.40', 7));
        $client->shouldReceive('order')->once()->andThrow(new WarzoneApiException(
            'uncertain',
            500,
            true,
            false
        ));
        $job = new WarzoneFulfillOrder((int) $purchase->id);

        $job->handle($client);
        $job->handle($client);

        $this->assertSame(WarzoneSupplierPurchase::STATUS_AMBIGUOUS, $purchase->fresh()->status);
        $this->assertSame(Order::STATUS_ABNORMAL, (int) $order->fresh()->status);
        $this->assertStringContainsString('支付已确认', (string) $order->fresh()->info);
    }

    private function bindFulfillmentServices(): void
    {
        $goods = Mockery::mock(GoodsService::class);
        $goods->shouldReceive('salesVolumeIncr')->zeroOrMoreTimes()->andReturn(true);
        $this->app->instance('Service\\GoodsService', $goods);
        $this->app->instance('Service\\CarmisService', new CarmisService());
        $this->app->instance('Service\\CouponService', Mockery::mock());
        $this->app->instance('Service\\OrderService', Mockery::mock());
        $this->app->instance('Service\\EmailtplService', Mockery::mock(EmailtplService::class));
        $telegram = Mockery::mock(TelegramOrderNotificationService::class);
        $telegram->shouldReceive('queuePaid')->zeroOrMoreTimes()->andReturn(false);
        $telegram->shouldReceive('queueStatus')->zeroOrMoreTimes()->andReturn(false);
        $this->app->instance(TelegramOrderNotificationService::class, $telegram);
    }

    private function readySetting(string $cost = '0.4000'): WarzoneSupplierSetting
    {
        $setting = new WarzoneSupplierSetting([
            'goods_id' => 16,
            'service_id' => 'S_01',
            'unit_cost_usd' => $cost,
            'enabled' => true,
        ]);
        $setting->setApiKey('WAR_TEST_KEY')->markConnectionTest(true)->save();

        return $setting;
    }

    private function snapshot(string $balance, string $price, int $stock): array
    {
        return [
            'balance_usd' => $balance,
            'service' => [
                'service_id' => 'S_01',
                'name' => 'Gemini AI Pro 18M',
                'price' => $price,
                'stock' => $stock,
                'in_stock' => $stock > 0,
                'pricing' => 'tiered',
                'orderable' => $stock > 0,
                'price_tiers' => [],
            ],
        ];
    }

    private function insertGoods(): void
    {
        DB::table('goods')->insert([
            'id' => 16,
            'gd_name' => 'Supplier product',
            'actual_price' => '1.00',
            'type' => Goods::AUTOMATIC_DELIVERY,
            'in_stock' => 0,
            'sales_volume' => 0,
            'is_open' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertCard(string $value): void
    {
        DB::table('carmis')->insert([
            'goods_id' => 16,
            'status' => Carmis::STATUS_UNSOLD,
            'is_loop' => 0,
            'carmi' => $value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertPay(): void
    {
        DB::table('pays')->insert([
            'id' => 1,
            'pay_check' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertOrder(int $status, int $amount): Order
    {
        DB::table('orders')->insert([
            'order_sn' => 'WARZONE-SHOP-ORDER',
            'pay_id' => 1,
            'goods_id' => 16,
            'coupon_id' => 0,
            'coupon_ret_back' => 0,
            'title' => 'Supplier product',
            'type' => Order::AUTOMATIC_DELIVERY,
            'buy_amount' => $amount,
            'email' => '',
            'info' => '',
            'trade_no' => 'PAYMENT-ONE',
            'status' => $status,
            'actual_price' => '1.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Order::query()->where('order_sn', 'WARZONE-SHOP-ORDER')->firstOrFail();
    }

    private function insertPurchase(
        WarzoneSupplierSetting $setting,
        Order $order,
        int $quantity
    ): WarzoneSupplierPurchase {
        return WarzoneSupplierPurchase::query()->create([
            'setting_id' => $setting->id,
            'goods_id' => 16,
            'order_id' => $order->id,
            'order_sn' => $order->order_sn,
            'service_id' => 'S_01',
            'quantity' => $quantity,
            'status' => WarzoneSupplierPurchase::STATUS_QUEUED,
            'unit_cost_usd' => '0.4000',
            'total_cost_usd' => bcmul('0.4000', (string) $quantity, 4),
        ]);
    }

    private function buildTables(): void
    {
        Schema::create('goods', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('gd_name');
            $table->decimal('actual_price', 10, 2)->default(0);
            $table->unsignedTinyInteger('type')->default(Goods::AUTOMATIC_DELIVERY);
            $table->integer('in_stock')->default(0);
            $table->integer('sales_volume')->default(0);
            $table->boolean('is_open')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('carmis', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('goods_id');
            $table->unsignedTinyInteger('status')->default(Carmis::STATUS_UNSOLD);
            $table->boolean('is_loop')->default(false);
            $table->text('carmi');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('pays', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('pay_check');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('coupons', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('ret')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('order_sn')->unique();
            $table->unsignedBigInteger('pay_id')->nullable();
            $table->unsignedBigInteger('goods_id');
            $table->unsignedBigInteger('coupon_id')->default(0);
            $table->unsignedTinyInteger('coupon_ret_back')->default(0);
            $table->string('title');
            $table->unsignedTinyInteger('type');
            $table->unsignedInteger('buy_amount')->default(1);
            $table->string('email')->nullable();
            $table->text('info')->nullable();
            $table->string('trade_no')->default('');
            $table->integer('status')->default(Order::STATUS_WAIT_PAY);
            $table->decimal('actual_price', 10, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('warzone_supplier_settings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('goods_id')->unique();
            $table->string('service_id', 64);
            $table->text('api_key_encrypted')->nullable();
            $table->decimal('unit_cost_usd', 12, 4)->default(0.4);
            $table->boolean('enabled')->default(false);
            $table->boolean('connection_test_ok')->default(false);
            $table->char('tested_credentials_hash', 64)->nullable();
            $table->decimal('last_balance_usd', 14, 4)->nullable();
            $table->unsignedInteger('last_supplier_stock')->nullable();
            $table->boolean('last_supplier_orderable')->nullable();
            $table->decimal('last_product_price_usd', 12, 4)->nullable();
            $table->timestamp('last_snapshot_at')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
        Schema::create('warzone_supplier_purchases', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('setting_id');
            $table->integer('goods_id');
            $table->unsignedBigInteger('order_id')->unique();
            $table->string('order_sn', 150);
            $table->string('provider_order_id', 128)->nullable()->unique();
            $table->string('service_id', 64);
            $table->unsignedInteger('quantity')->default(1);
            $table->string('status', 20)->default('queued');
            $table->decimal('unit_cost_usd', 12, 4)->nullable();
            $table->decimal('total_cost_usd', 14, 4)->nullable();
            $table->longText('products_encrypted')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('stocked_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }
}
