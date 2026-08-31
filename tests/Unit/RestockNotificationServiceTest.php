<?php

namespace Tests\Unit;

use App\Jobs\TelegramRestockNotification;
use App\Jobs\EmailRestockNotification;
use App\Jobs\EmailOutOfStockNotification;
use App\Jobs\EmailStockNotification;
use App\Models\Goods;
use App\Service\RestockNotificationService;
use App\Service\SystemSettingStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RestockNotificationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $settings = new \ReflectionProperty(SystemSettingStore::class, 'settings');
        $settings->setAccessible(true);
        $settings->setValue(null, null);
        config(['app.url' => 'https://shop.example.test']);
    }

    /**
     * @dataProvider notificationConditions
     */
    public function test_it_only_notifies_when_an_import_increases_available_stock(
        int $before,
        int $after,
        int $inserted,
        bool $expected
    ): void {
        $service = new RestockNotificationService();

        $this->assertSame($expected, $service->shouldNotify($before, $after, $inserted));
    }

    public function notificationConditions(): array
    {
        return [
            'out of stock to stocked' => [0, 3, 3, true],
            'low stock replenished' => [2, 5, 3, true],
            'empty import' => [0, 0, 0, false],
            'stock did not increase' => [2, 2, 1, false],
            'order deduction' => [5, 4, 0, false],
        ];
    }

    public function test_it_queues_one_notification_for_one_import_batch(): void
    {
        Queue::fake();
        Cache::forever('system-setting', [
            'is_open_telegram_restock' => 1,
            'telegram_bot_token' => 'TOKEN',
            'telegram_userid' => '@restock_channel',
        ]);

        $queued = (new RestockNotificationService())->dispatchForImport(
            $this->goods(),
            0,
            3,
            3,
            'batch-one'
        );

        $this->assertTrue($queued);
        Queue::assertPushed(TelegramRestockNotification::class, 1);
    }

    public function test_it_queues_an_email_without_telegram_configuration(): void
    {
        Queue::fake();
        Cache::forever('system-setting', [
            'is_open_email_restock' => 1,
            'email_restock_recipient' => 'fxq45@qq.com',
            'driver' => 'array',
        ]);

        $queued = (new RestockNotificationService())->dispatchForImport(
            $this->goods(),
            0,
            2,
            2,
            'email-only-batch'
        );

        $this->assertTrue($queued);
        Queue::assertPushed(EmailRestockNotification::class, function (EmailRestockNotification $job) {
            return $job->batchId() === 'email-only-batch'
                && $job->recipient() === 'fxq45@qq.com';
        });
        Queue::assertNotPushed(TelegramRestockNotification::class);
    }

    public function test_it_does_not_enqueue_the_same_email_batch_twice(): void
    {
        Queue::fake();
        Cache::forever('system-setting', [
            'is_open_email_restock' => 1,
            'email_restock_recipient' => 'fxq45@qq.com',
            'driver' => 'array',
        ]);
        $service = new RestockNotificationService();

        $this->assertTrue($service->dispatchForImport($this->goods(), 0, 2, 2, 'duplicate-batch'));
        $this->assertFalse($service->dispatchForImport($this->goods(), 0, 2, 2, 'duplicate-batch'));
        Queue::assertPushed(EmailRestockNotification::class, 1);
    }

    /**
     * @dataProvider outOfStockConditions
     */
    public function test_it_only_alerts_when_stock_crosses_to_zero(
        int $before,
        int $after,
        bool $expected
    ): void {
        $this->assertSame(
            $expected,
            (new RestockNotificationService())->shouldNotifyOutOfStock($before, $after)
        );
    }

    public function outOfStockConditions(): array
    {
        return [
            'last item sold' => [1, 0, true],
            'multiple items sold' => [3, 0, true],
            'still stocked' => [3, 1, false],
            'already empty' => [0, 0, false],
            'invalid negative before' => [-1, 0, false],
        ];
    }

    public function test_it_queues_one_sold_out_email_per_product_cycle(): void
    {
        Queue::fake();
        Cache::forever('system-setting', [
            'is_open_email_out_of_stock' => 1,
            'email_restock_recipient' => 'fxq45@qq.com',
            'driver' => 'array',
        ]);
        $service = new RestockNotificationService();
        $goods = $this->goods();

        $this->assertTrue($service->dispatchForOutOfStock($goods, 1, 0, 'order-one'));
        $this->assertFalse($service->dispatchForOutOfStock($goods, 1, 0, 'order-two'));
        Queue::assertPushed(EmailOutOfStockNotification::class, 1);
    }

    public function test_restock_clears_the_sold_out_marker_for_the_next_cycle(): void
    {
        Queue::fake();
        Cache::forever('system-setting', [
            'is_open_email_out_of_stock' => 1,
            'is_open_email_restock' => 0,
            'email_restock_recipient' => 'fxq45@qq.com',
            'driver' => 'array',
        ]);
        $service = new RestockNotificationService();
        $goods = $this->goods();

        $this->assertTrue($service->dispatchForOutOfStock($goods, 1, 0, 'order-one'));
        $service->clearOutOfStockNotification($goods);
        $this->assertTrue($service->dispatchForOutOfStock($goods, 1, 0, 'order-two'));
        Queue::assertPushed(EmailOutOfStockNotification::class, 2);
    }

    public function test_sold_out_email_contains_safe_product_details(): void
    {
        $goods = $this->goods();
        $goods->gd_name = '<商品> & 测试';

        [$title, $content] = (new RestockNotificationService())->buildOutOfStockEmail($goods);

        $this->assertStringContainsString('已售罄', $title);
        $this->assertStringContainsString('&lt;商品&gt; &amp; 测试', $content);
        $this->assertStringNotContainsString('<商品>', $content);
        $this->assertStringContainsString('https://shop.example.test/buy/42', $content);
    }

    public function test_email_message_escapes_product_values(): void
    {
        config(['app.url' => 'https://shop.example.test']);
        $goods = $this->goods();
        $goods->gd_name = '<商品> & 测试';

        [$title, $content] = (new RestockNotificationService())->buildEmail($goods, 4);

        $this->assertStringContainsString('库存通知', $title);
        $this->assertStringContainsString('&lt;商品&gt; &amp; 测试', $content);
        $this->assertStringNotContainsString('<商品>', $content);
        $this->assertStringContainsString('https://shop.example.test/buy/42', $content);
    }

    /** @dataProvider validTargets */
    public function test_it_accepts_public_private_channel_and_admin_chat_targets(string $target): void
    {
        Queue::fake();
        Cache::forever('system-setting', [
            'is_open_telegram_restock' => 1,
            'telegram_bot_token' => 'TOKEN',
            'telegram_userid' => $target,
        ]);

        $this->assertTrue((new RestockNotificationService())->dispatchForImport(
            $this->goods(), 0, 1, 1, 'target-'.$target
        ));
        Queue::assertPushed(TelegramRestockNotification::class, 1);
    }

    public function validTargets(): array
    {
        return [
            'public username' => ['@channel_username'],
            'private channel id' => ['-1001234567890'],
            'admin private chat id' => ['1234567890'],
        ];
    }

    public function test_it_does_not_queue_when_the_restock_switch_is_off(): void
    {
        Queue::fake();
        Cache::forever('system-setting', [
            'is_open_telegram_restock' => 0,
            'telegram_bot_token' => 'TOKEN',
            'telegram_userid' => '@restock_channel',
        ]);

        $queued = (new RestockNotificationService())->dispatchForImport(
            $this->goods(),
            0,
            3,
            3,
            'batch-disabled'
        );

        $this->assertFalse($queued);
        Queue::assertNothingPushed();
    }

    public function test_message_contains_the_sales_details_and_test_label(): void
    {
        $message = (new RestockNotificationService())->buildMessage($this->goods(), 8, true);

        $this->assertStringContainsString('补货通知测试', $message);
        $this->assertStringContainsString('测试商品', $message);
        $this->assertStringContainsString('当前库存：8', $message);
        $this->assertStringContainsString('销售价格：¥12.30', $message);
        $this->assertStringContainsString('https://shop.example.test/buy/42', $message);
    }

    /** @dataProvider telegramTargetAliases */
    public function test_it_normalizes_telegram_channel_links(string $input, string $expected): void
    {
        $this->assertSame($expected, RestockNotificationService::normalizeTarget($input));
        $this->assertTrue(RestockNotificationService::isValidTarget($input));
    }

    public function telegramTargetAliases(): array
    {
        return [
            'username' => ['@zoebuhuo', '@zoebuhuo'],
            'short link' => ['t.me/zoebuhuo', '@zoebuhuo'],
            'https link' => ['https://t.me/zoebuhuo/', '@zoebuhuo'],
        ];
    }

    private function goods(): Goods
    {
        $goods = new Goods();
        $goods->setRawAttributes([
            'id' => 42,
            'gd_name' => '测试商品',
            'actual_price' => '12.30',
            'type' => Goods::AUTOMATIC_DELIVERY,
        ]);

        return $goods;
    }
}
