<?php

namespace Tests\Feature;

use App\Jobs\TelegramPrivateMessage;
use App\Jobs\TelegramShopInteraction;
use App\Models\Customer;
use App\Models\Order;
use App\Models\TelegramBinding;
use App\Service\TelegramBotClient;
use App\Service\TelegramBindingService;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Support\BuildsTelegramTables;
use Tests\TestCase;

class TelegramBindingWebhookTest extends TestCase
{
    use BuildsTelegramTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildTelegramTables();
        config(['services.telegram.webhook_secret' => 'WEBHOOK_SECRET']);
        Queue::fake();
    }

    public function test_binding_token_is_hashed_short_lived_and_single_use(): void
    {
        $customer = $this->customer('one@example.test');
        [$binding, $token] = app(TelegramBindingService::class)->issue($customer);

        $this->assertSame(43, strlen($token));
        $this->assertNotSame($token, $binding->token_hash);
        $this->assertSame(hash('sha256', $token), $binding->token_hash);
        $this->assertTrue($binding->expires_at->between(now()->addMinutes(14), now()->addMinutes(16)));

        $result = app(TelegramBindingService::class)->consume(
            $token,
            ['id' => 123456789, 'type' => 'private'],
            ['id' => 123456789, 'username' => 'customer_one', 'first_name' => 'Test']
        );
        $this->assertSame('bound', $result['status']);
        $this->assertSame('invalid', app(TelegramBindingService::class)->consume(
            $token,
            ['id' => 123456789, 'type' => 'private'],
            ['id' => 123456789]
        )['status']);
        Queue::assertPushed(TelegramPrivateMessage::class, 1);
    }

    public function test_expired_group_and_mismatched_sender_bindings_are_rejected(): void
    {
        $service = app(TelegramBindingService::class);
        [$expired, $expiredToken] = $service->issue($this->customer('expired@example.test'));
        $expired->expires_at = now()->subSecond();
        $expired->save();
        $this->assertSame('invalid', $service->consume(
            $expiredToken,
            ['id' => 1001, 'type' => 'private'],
            ['id' => 1001]
        )['status']);

        [$groupBinding, $groupToken] = $service->issue($this->customer('group@example.test'));
        $this->assertSame('private_chat_required', $service->consume(
            $groupToken,
            ['id' => -1001234567890, 'type' => 'channel'],
            ['id' => 1002]
        )['status']);
        $this->assertNotNull($groupBinding->refresh()->consumed_at);

        [, $mismatchToken] = $service->issue($this->customer('mismatch@example.test'));
        $this->assertSame('private_chat_required', $service->consume(
            $mismatchToken,
            ['id' => 1003, 'type' => 'private'],
            ['id' => 9999]
        )['status']);
    }

    public function test_rebinding_a_chat_transfers_its_unique_ownership(): void
    {
        $service = app(TelegramBindingService::class);
        $first = $this->customer('first@example.test');
        $second = $this->customer('second@example.test');
        [, $firstToken] = $service->issue($first);
        $service->consume($firstToken, ['id' => 2001, 'type' => 'private'], ['id' => 2001]);
        [, $secondToken] = $service->issue($second);
        $service->consume($secondToken, ['id' => 2001, 'type' => 'private'], ['id' => 2001]);

        $this->assertNull($first->refresh()->telegram_chat_id);
        $this->assertSame('2001', $second->refresh()->telegram_chat_id);
        $this->assertSame(1, Customer::query()->where('telegram_chat_id', '2001')->count());
    }

    public function test_binding_migrates_orders_from_a_bot_provisioned_customer(): void
    {
        $service = app(TelegramBindingService::class);
        $provisioned = $this->customer('telegram-2010@telegram.newzoe.cloud');
        $provisioned->forceFill([
            'telegram_chat_id' => '2010',
            'telegram_bound_at' => now(),
        ])->save();
        $order = new Order();
        $order->customer_id = $provisioned->getKey();
        $order->order_sn = 'BOTORDER2010';
        $order->goods_id = 1;
        $order->title = 'Bot product';
        $order->type = 1;
        $order->goods_price = 1;
        $order->buy_amount = 1;
        $order->actual_price = 1;
        $order->search_pwd = '';
        $order->email = $provisioned->email;
        $order->buy_ip = '127.0.0.1';
        $order->trade_no = '';
        $order->status = Order::STATUS_WAIT_PAY;
        $order->save();

        $target = $this->customer('web-owner@example.test');
        [, $token] = $service->issue($target);
        $result = $service->consume(
            $token,
            ['id' => 2010, 'type' => 'private'],
            ['id' => 2010, 'username' => 'web_owner']
        );

        $this->assertSame('bound', $result['status']);
        $this->assertSame($target->getKey(), $order->refresh()->customer_id);
        $this->assertNull($provisioned->refresh()->telegram_chat_id);
        $this->assertSame('2010', $target->refresh()->telegram_chat_id);
    }

    public function test_webhook_rejects_forged_secret_and_accepts_valid_private_start(): void
    {
        [, $token] = app(TelegramBindingService::class)->issue($this->customer('webhook@example.test'));
        $update = ['message' => [
            'text' => '/start bind_'.$token,
            'chat' => ['id' => 3001, 'type' => 'private'],
            'from' => ['id' => 3001, 'username' => 'webhook_customer'],
        ]];

        $this->postJson('/api/telegram/webhook', $update)->assertStatus(403);
        $this->postJson('/api/telegram/webhook', $update, [
            'X-Telegram-Bot-Api-Secret-Token' => 'WRONG_SECRET',
        ])->assertStatus(403);
        $this->postJson('/api/telegram/webhook', $update, [
            'X-Telegram-Bot-Api-Secret-Token' => 'WEBHOOK_SECRET',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame('3001', Customer::query()->where('email', 'webhook@example.test')->value('telegram_chat_id'));
    }

    public function test_valid_callback_update_is_acknowledged_and_queued_for_the_shop_bot(): void
    {
        $client = Mockery::mock(TelegramBotClient::class);
        $client->shouldReceive('answerCallbackQuery')
            ->once()
            ->with('', 'callback-123');
        $this->app->instance(TelegramBotClient::class, $client);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 9001,
            'callback_query' => [
                'id' => 'callback-123',
                'data' => 'shop:products:0',
                'message' => [
                    'message_id' => 55,
                    'chat' => ['id' => 3002, 'type' => 'private'],
                ],
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => 'WEBHOOK_SECRET',
        ])->assertOk()->assertJson(['ok' => true]);

        Queue::assertPushed(TelegramShopInteraction::class, 1);
    }

    private function customer(string $email): Customer
    {
        return Customer::query()->create(['email' => $email, 'password' => bcrypt('test-password')]);
    }
}
