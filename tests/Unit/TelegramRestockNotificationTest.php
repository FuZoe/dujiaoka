<?php

namespace Tests\Unit;

use App\Jobs\TelegramRestockNotification;
use App\Service\TelegramBotClient;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class TelegramRestockNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Cache::forever('system-setting', [
            'is_open_telegram_restock' => 1,
            'telegram_bot_token' => 'TOKEN',
            'telegram_userid' => '@restock_channel',
        ]);
    }

    public function test_the_same_batch_is_only_sent_once(): void
    {
        $client = Mockery::mock(TelegramBotClient::class);
        $client->shouldReceive('sendMessage')
            ->once()
            ->with('TOKEN', '@restock_channel', 'message')
            ->andReturn(101);

        $job = new TelegramRestockNotification('stable-batch-id', 42, 'message');
        $job->handle($client);
        $job->handle($client);

        $this->assertTrue(Cache::has('telegram-restock:sent:stable-batch-id'));
    }

    public function test_admin_private_chat_target_is_sent(): void
    {
        Cache::forever('system-setting', [
            'is_open_telegram_restock' => 1,
            'telegram_bot_token' => 'TOKEN',
            'telegram_userid' => '1234567890',
        ]);
        $client = Mockery::mock(TelegramBotClient::class);
        $client->shouldReceive('sendMessage')
            ->once()
            ->with('TOKEN', '1234567890', 'message')
            ->andReturn(102);

        (new TelegramRestockNotification('private-target-batch', 42, 'message'))->handle($client);
    }

    public function test_channel_link_target_is_normalized_before_sending(): void
    {
        Cache::forever('system-setting', [
            'is_open_telegram_restock' => 1,
            'telegram_bot_token' => 'TOKEN',
            'telegram_userid' => 't.me/zoebuhuo',
        ]);
        $client = Mockery::mock(TelegramBotClient::class);
        $client->shouldReceive('sendMessage')
            ->once()
            ->with('TOKEN', '@zoebuhuo', 'message')
            ->andReturn(103);

        (new TelegramRestockNotification('channel-link-batch', 42, 'message'))->handle($client);
    }
}
