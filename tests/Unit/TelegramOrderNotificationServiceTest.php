<?php

namespace Tests\Unit;

use App\Jobs\SendTelegramOrderNotification;
use App\Jobs\TelegramPrivateMessage;
use App\Jobs\TelegramRestockNotification;
use App\Models\Customer;
use App\Models\Order;
use App\Models\TelegramOrderNotification;
use App\Service\TelegramBotClient;
use App\Service\TelegramOrderNotificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\Support\BuildsTelegramTables;
use Tests\TestCase;

class TelegramOrderNotificationServiceTest extends TestCase
{
    use BuildsTelegramTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildTelegramTables();
        config(['app.url' => 'https://shop.example.test']);
        Cache::forever('system-setting', [
            'is_open_telegram_customer_order' => 1,
            'telegram_send_order_cards' => 1,
            'telegram_bot_token' => 'TOKEN',
            'telegram_userid' => '@restock_channel',
        ]);
        Queue::fake();
    }

    public function test_guest_order_remains_unowned_and_does_not_queue_private_notification(): void
    {
        $order = $this->order(null);

        $this->assertNull($order->customer_id);
        $this->assertFalse(app(TelegramOrderNotificationService::class)->queueCreated($order));
        $this->assertSame(0, TelegramOrderNotification::query()->count());
        Queue::assertNotPushed(SendTelegramOrderNotification::class);
    }

    public function test_order_events_are_owned_and_idempotent(): void
    {
        $customer = $this->customer('4001');
        $order = $this->order($customer);
        $service = app(TelegramOrderNotificationService::class);

        $this->assertTrue($service->queueCreated($order));
        $this->assertFalse($service->queueCreated($order));
        $this->assertTrue($service->queuePaid($order));
        $this->assertTrue($service->queueStatus($order));

        $this->assertSame($customer->getKey(), $order->customer_id);
        $this->assertSame(3, TelegramOrderNotification::query()->count());
        Queue::assertPushed(SendTelegramOrderNotification::class, 3);
    }

    public function test_message_is_plain_text_has_buttons_and_card_switch(): void
    {
        $order = $this->order($this->customer('4002'), str_repeat('CARD<>&_', 600));
        $service = app(TelegramOrderNotificationService::class);
        $parts = $service->buildParts($order, 'status:'.Order::STATUS_COMPLETED);
        $buttons = $service->buttons($order, 'created');

        $this->assertGreaterThan(1, count($parts));
        $this->assertStringContainsString('CARD<>&_', implode('', $parts));
        $this->assertLessThanOrEqual(3510, mb_strlen($parts[0], 'UTF-8'));
        $this->assertSame('查看订单', $buttons['inline_keyboard'][0][0]['text']);
        $this->assertSame('前往支付', $buttons['inline_keyboard'][0][1]['text']);

        Cache::forever('system-setting', [
            'is_open_telegram_customer_order' => 1,
            'telegram_send_order_cards' => 0,
        ]);
        $this->assertStringNotContainsString('CARD<>&_', implode('', $service->buildParts(
            $order,
            'status:'.Order::STATUS_COMPLETED
        )));
    }

    public function test_private_order_job_sends_only_to_the_bound_positive_chat(): void
    {
        $order = $this->order($this->customer('4003'));
        $notification = TelegramOrderNotification::query()->create([
            'order_id' => $order->getKey(),
            'event_key' => 'created',
            'status' => 'queued',
            'next_part' => 0,
        ]);
        $client = Mockery::mock(TelegramBotClient::class);
        $client->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (string $token, string $chatId, string $message, array $options) {
                return $token === 'TOKEN'
                    && $chatId === '4003'
                    && strpos($message, '订单已创建') !== false
                    && !isset($options['parse_mode']);
            })
            ->andReturn(501);

        (new SendTelegramOrderNotification($notification->getKey()))->handle(
            $client,
            app(TelegramOrderNotificationService::class)
        );

        $this->assertSame('sent', $notification->refresh()->status);
    }

    public function test_bot_order_notification_opens_the_order_inside_telegram(): void
    {
        $order = $this->order($this->customer('4005'));
        $order->telegram_chat_id = '4005';
        $order->save();

        $buttons = app(TelegramOrderNotificationService::class)->buttons($order, 'paid');
        $button = $buttons['inline_keyboard'][0][0];

        $this->assertSame('shop:order:'.$order->order_sn, $button['callback_data']);
        $this->assertArrayNotHasKey('url', $button);
    }

    public function test_restock_and_private_job_targets_are_strictly_separated(): void
    {
        Cache::forever('system-setting', [
            'is_open_telegram_restock' => 1,
            'telegram_bot_token' => 'TOKEN',
            'telegram_userid' => 'chat:4004',
        ]);
        $client = Mockery::mock(TelegramBotClient::class);
        $client->shouldNotReceive('sendMessage');

        try {
            (new TelegramRestockNotification('bad-target', 1, 'restock'))->handle($client);
            $this->fail('An invalid restock target must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Telegram restock target is invalid.', $exception->getMessage());
        }

        try {
            (new TelegramPrivateMessage('-1001234567890', 'private'))->handle($client);
            $this->fail('A channel target must be rejected for private messages.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Telegram private message target is invalid.', $exception->getMessage());
        }
    }

    private function customer(string $chatId): Customer
    {
        $customer = Customer::query()->create([
            'email' => $chatId.'@example.test',
            'password' => bcrypt('test-password'),
        ]);
        $customer->forceFill([
            'telegram_chat_id' => $chatId,
            'telegram_bound_at' => now(),
        ])->save();
        return $customer;
    }

    private function order(?Customer $customer, string $info = 'CARD-CONTENT'): Order
    {
        $order = new Order();
        $order->forceFill([
            'customer_id' => $customer ? $customer->getKey() : null,
            'order_sn' => 'ORDER'.str_pad((string) (Order::query()->count() + 1), 6, '0', STR_PAD_LEFT),
            'goods_id' => 1,
            'title' => '<b>测试商品</b>',
            'type' => Order::AUTOMATIC_DELIVERY,
            'goods_price' => 12.30,
            'buy_amount' => 2,
            'actual_price' => 24.60,
            'search_pwd' => 'SEARCH_PASSWORD',
            'email' => $customer ? $customer->email : 'guest@example.test',
            'info' => $info,
            'status' => Order::STATUS_COMPLETED,
        ])->save();
        return $order;
    }
}
