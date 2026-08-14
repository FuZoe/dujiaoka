<?php

namespace Tests\Unit;

use App\Jobs\TelegramRestockNotification;
use App\Models\Goods;
use App\Service\RestockNotificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RestockNotificationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
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
