<?php

namespace Tests\Unit;

use App\Service\ShopApiClient;
use App\Service\TelegramBotClient;
use App\Service\TelegramShopBotService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class TelegramShopBotServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Cache::forever('system-setting', [
            'telegram_bot_token' => 'BOT_TOKEN',
        ]);
    }

    public function test_start_sends_a_private_shop_menu_and_group_messages_are_ignored(): void
    {
        $api = Mockery::mock(ShopApiClient::class);
        $telegram = Mockery::mock(TelegramBotClient::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (string $token, string $chatId, string $text, array $payload): bool {
                return $token === 'BOT_TOKEN'
                    && $chatId === '1001'
                    && strpos($text, '欢迎来到 NewZoe 商城') !== false
                    && $payload['reply_markup']['inline_keyboard'][0][0]['callback_data'] === 'shop:products:0'
                    && $payload['reply_markup']['inline_keyboard'][0][1]['callback_data'] === 'shop:orders';
            })
            ->andReturn(10);
        $telegram->shouldNotReceive('editMessageText');

        $service = new TelegramShopBotService($api, $telegram);
        $service->handleMessage([
            'chat' => ['id' => 1001, 'type' => 'private'],
            'text' => '/start@newzoe_order_bot',
        ]);
        $service->handleMessage([
            'chat' => ['id' => -100123, 'type' => 'group'],
            'text' => '/start',
        ]);
    }

    public function test_products_callback_renders_product_buttons_with_stock_and_pagination(): void
    {
        $api = Mockery::mock(ShopApiClient::class);
        $api->shouldReceive('products')->once()->andReturn([
            'products' => [
                ['id' => 12, 'name' => '云服务套餐', 'price' => '9.90', 'stock' => 3],
                ['id' => 13, 'name' => '缺货商品', 'price' => '2.00', 'stock' => 0],
            ],
        ]);
        $telegram = Mockery::mock(TelegramBotClient::class);
        $telegram->shouldReceive('editMessageText')
            ->once()
            ->withArgs(function (
                string $token,
                string $chatId,
                int $messageId,
                string $text,
                array $payload
            ): bool {
                $keyboard = $payload['reply_markup']['inline_keyboard'];
                return $token === 'BOT_TOKEN'
                    && $chatId === '1002'
                    && $messageId === 77
                    && strpos($text, '商品列表') !== false
                    && strpos($keyboard[0][0]['text'], '云服务套餐') !== false
                    && $keyboard[0][0]['callback_data'] === 'shop:product:12'
                    && strpos($keyboard[1][0]['text'], '缺货') !== false
                    && $keyboard[1][0]['callback_data'] === 'shop:product:13';
            });

        (new TelegramShopBotService($api, $telegram))->handleCallback([
            'data' => 'shop:products:0',
            'message' => [
                'message_id' => 77,
                'chat' => ['id' => 1002, 'type' => 'private'],
            ],
        ]);
    }

    public function test_payment_retry_reuses_the_same_idempotency_key_after_a_transient_failure(): void
    {
        $api = Mockery::mock(ShopApiClient::class);
        $idempotencyKeys = [];
        $api->shouldReceive('createOrder')
            ->twice()
            ->withArgs(function (array $payload, string $idempotencyKey) use (&$idempotencyKeys): bool {
                $idempotencyKeys[] = $idempotencyKey;
                return $payload['product_id'] === 12
                    && $payload['quantity'] === 1
                    && $payload['email'] === 'buyer@example.test'
                    && $payload['payment_method'] === 'binancepay';
            })
            ->andReturnUsing(function (array $payload, string $idempotencyKey) use (&$idempotencyKeys): array {
                if (count($idempotencyKeys) === 1) {
                    throw new RuntimeException('temporary network failure');
                }
                return [
                    'order' => [
                        'id' => 'ORDER123',
                        'amount' => '9.90',
                        'expires_at' => '2026-08-24T12:20:00+08:00',
                    ],
                    'payment' => ['url' => 'https://pay.example.test/ORDER123'],
                ];
            });
        $telegram = Mockery::mock(TelegramBotClient::class);
        $telegram->shouldReceive('editMessageText')
            ->twice()
            ->withArgs(function (
                string $token,
                string $chatId,
                int $messageId,
                string $text,
                array $payload
            ): bool {
                if (strpos($text, '这次操作没有完成') !== false) {
                    return $messageId === 88;
                }

                return strpos($text, '订单已创建') !== false
                    && strpos($text, 'ORDER123') !== false
                    && $payload['reply_markup']['inline_keyboard'][0][0]['url'] === 'https://pay.example.test/ORDER123';
            });

        $service = new TelegramShopBotService($api, $telegram);
        Cache::put('telegram-shop:session:1003', [
            'step' => 'payment',
            'product' => [
                'id' => 12,
                'name' => '云服务套餐',
                'input_fields' => [],
            ],
            'quantity' => 1,
            'email' => 'buyer@example.test',
            'search_password' => 'lookup-password',
            'inputs' => [],
        ]);

        $callback = [
            'data' => 'shop:method:binancepay',
            'message' => [
                'message_id' => 88,
                'chat' => ['id' => 1003, 'type' => 'private'],
            ],
        ];
        $service->handleCallback($callback);
        $service->handleCallback($callback);

        $this->assertCount(2, $idempotencyKeys);
        $this->assertSame($idempotencyKeys[0], $idempotencyKeys[1]);
        $this->assertStringStartsWith('tg-1003-', $idempotencyKeys[0]);
    }
}
