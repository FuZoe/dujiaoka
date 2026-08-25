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
                $buttons = [];
                foreach (($payload['reply_markup']['inline_keyboard'] ?? []) as $row) {
                    $buttons = array_merge($buttons, (array) $row);
                }
                $callbacks = array_column($buttons, 'callback_data');

                return $token === 'BOT_TOKEN'
                    && $chatId === '1001'
                    && strpos($text, '欢迎来到 NewZoe 商城') !== false
                    && in_array('shop:products:0', $callbacks, true)
                    && in_array('shop:orders', $callbacks, true);
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
        $api->shouldReceive('pay')
            ->once()
            ->with('ORDER123', 'binancepay')
            ->andReturn([
                'payment_required' => true,
                'payment' => [
                    'method' => 'binancepay',
                    'qr_payload' => 'https://app.binance.com/uni-qr/Sg9jgWUd',
                    'expected_usdt' => '1.38',
                    'currency' => 'USDT',
                    'quote_expires_at' => '2026-08-24T12:15:00+08:00',
                ],
            ]);
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
                    && strpos($text, '币安二维码已发送') !== false
                    && !isset($payload['reply_markup']['inline_keyboard'][0][0]['url']);
            });
        $telegram->shouldReceive('sendPhoto')
            ->once()
            ->withArgs(function (
                string $token,
                string $chatId,
                string $photo,
                string $caption,
                array $payload
            ): bool {
                return $token === 'BOT_TOKEN'
                    && $chatId === '1003'
                    && $photo !== ''
                    && strpos($caption, '应付：1.38 USDT') !== false
                    && strpos($caption, 'https://app.binance.com/uni-qr/Sg9jgWUd') === false
                    && !isset($payload['reply_markup']['inline_keyboard'][0][0]['url'])
                    && $payload['reply_markup']['inline_keyboard'][0][0]['callback_data'] === 'shop:order:ORDER123';
            })
            ->andReturn(126);

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

    public function test_non_binance_payment_keeps_the_external_checkout_button(): void
    {
        $api = Mockery::mock(ShopApiClient::class);
        $api->shouldReceive('createOrder')->once()->andReturn([
            'order' => [
                'id' => 'ALIPAY123',
                'amount' => '9.90',
                'expires_at' => '2026-08-24T12:20:00+08:00',
            ],
            'payment' => [
                'method' => 'alipay',
                'url' => 'https://shop.example.test/pay/ALIPAY123',
            ],
        ]);
        $api->shouldNotReceive('pay');
        $telegram = Mockery::mock(TelegramBotClient::class);
        $telegram->shouldReceive('editMessageText')
            ->once()
            ->withArgs(function (string $token, string $chatId, int $messageId, string $text, array $payload): bool {
                return $token === 'BOT_TOKEN'
                    && $chatId === '1004'
                    && $messageId === 90
                    && strpos($text, 'ALIPAY123') !== false
                    && $payload['reply_markup']['inline_keyboard'][0][0]['url'] === 'https://shop.example.test/pay/ALIPAY123';
            });
        $telegram->shouldNotReceive('sendPhoto');

        Cache::put('telegram-shop:session:1004', [
            'step' => 'payment',
            'product' => ['id' => 12, 'name' => '云服务套餐', 'input_fields' => []],
            'quantity' => 1,
            'email' => 'buyer@example.test',
            'search_password' => 'lookup-password',
            'inputs' => [],
        ]);

        (new TelegramShopBotService($api, $telegram))->handleCallback([
            'data' => 'shop:method:alipay',
            'message' => [
                'message_id' => 90,
                'chat' => ['id' => 1004, 'type' => 'private'],
            ],
        ]);
    }

    public function test_start_uses_vietnamese_telegram_locale_and_exposes_language_switcher(): void
    {
        $api = Mockery::mock(ShopApiClient::class);
        $telegram = Mockery::mock(TelegramBotClient::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (string $token, string $chatId, string $text, array $payload): bool {
                $buttons = [];
                foreach (($payload['reply_markup']['inline_keyboard'] ?? []) as $row) {
                    $buttons = array_merge($buttons, (array) $row);
                }
                $buttonCallbacks = array_column($buttons, 'callback_data');
                $buttonLabels = array_column($buttons, 'text');

                return $token === 'BOT_TOKEN'
                    && $chatId === '2001'
                    && strpos($text, 'Chào mừng bạn đến NewZoe Shop') !== false
                    && strpos($text, '欢迎来到 NewZoe 商城') === false
                    && in_array('shop:languages', $buttonCallbacks, true)
                    && (bool) array_filter($buttonLabels, function (string $label): bool {
                        return strpos($label, 'Ngôn ngữ') !== false;
                    });
            });

        (new TelegramShopBotService($api, $telegram))->handleMessage([
            'chat' => ['id' => 2001, 'type' => 'private'],
            'from' => ['language_code' => 'vi-VN'],
            'text' => '/start',
        ]);
    }

    public function test_switching_to_english_translates_product_ui_but_keeps_product_name(): void
    {
        $api = Mockery::mock(ShopApiClient::class);
        $api->shouldReceive('products')->once()->andReturn([
            'products' => [
                [
                    'id' => 42,
                    'name' => '云服务套餐',
                    'price' => '9.90',
                    'stock' => 3,
                ],
            ],
        ]);
        $telegram = Mockery::mock(TelegramBotClient::class);
        $telegram->shouldReceive('answerCallbackQuery')->zeroOrMoreTimes();
        $telegram->shouldReceive('editMessageText')
            ->once()
            ->ordered()
            ->withArgs(function (string $token, string $chatId, int $messageId, string $text, array $payload): bool {
                return $token === 'BOT_TOKEN'
                    && $chatId === '2002'
                    && $messageId === 101
                    && (strpos($text, 'Welcome to NewZoe Shop') !== false
                        || strpos($text, 'Language changed to: English') !== false)
                    && strpos($text, '欢迎来到 NewZoe 商城') === false;
            });
        $telegram->shouldReceive('editMessageText')
            ->once()
            ->ordered()
            ->withArgs(function (string $token, string $chatId, int $messageId, string $text, array $payload): bool {
                $keyboard = $payload['reply_markup']['inline_keyboard'] ?? [];
                $productButton = $keyboard[0][0] ?? [];

                return $token === 'BOT_TOKEN'
                    && $chatId === '2002'
                    && $messageId === 102
                    && preg_match('/Products|product list/i', $text) === 1
                    && strpos($text, '商品列表') === false
                    && strpos((string) ($productButton['text'] ?? ''), '云服务套餐') !== false
                    && strpos((string) ($productButton['text'] ?? ''), '9.90') !== false
                    && ($productButton['callback_data'] ?? '') === 'shop:product:42';
            });

        $service = new TelegramShopBotService($api, $telegram);
        $service->handleCallback([
            'data' => 'shop:lang:en',
            'message' => [
                'message_id' => 101,
                'chat' => ['id' => 2002, 'type' => 'private'],
            ],
        ]);
        $service->handleCallback([
            'data' => 'shop:products:0',
            'message' => [
                'message_id' => 102,
                'chat' => ['id' => 2002, 'type' => 'private'],
            ],
        ]);
    }

    public function test_language_selection_callback_renders_all_three_supported_languages(): void
    {
        $api = Mockery::mock(ShopApiClient::class);
        $telegram = Mockery::mock(TelegramBotClient::class);
        $telegram->shouldReceive('editMessageText')
            ->once()
            ->withArgs(function (string $token, string $chatId, int $messageId, string $text, array $payload): bool {
                $keyboard = $payload['reply_markup']['inline_keyboard'] ?? [];
                $buttons = [];
                foreach ($keyboard as $row) {
                    $buttons = array_merge($buttons, (array) $row);
                }
                $callbacks = array_column($buttons, 'callback_data');
                $labels = implode("\n", array_column($buttons, 'text'));

                return $token === 'BOT_TOKEN'
                    && $chatId === '2003'
                    && $messageId === 103
                    && (bool) preg_match('/language|语言|ngôn ngữ/i', $text)
                    && in_array('shop:lang:zh', $callbacks, true)
                    && in_array('shop:lang:en', $callbacks, true)
                    && in_array('shop:lang:vi', $callbacks, true)
                    && strpos($labels, '中文') !== false
                    && strpos($labels, 'English') !== false
                    && strpos($labels, 'Tiếng Việt') !== false;
            });

        (new TelegramShopBotService($api, $telegram))->handleCallback([
            'data' => 'shop:languages',
            'message' => [
                'message_id' => 103,
                'chat' => ['id' => 2003, 'type' => 'private'],
            ],
        ]);
    }
}
