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
            'telegram_userid' => 'CHANNEL_ID',
        ]);
    }

    public function test_the_same_batch_is_only_sent_once(): void
    {
        $client = Mockery::mock(TelegramBotClient::class);
        $client->shouldReceive('sendMessage')
            ->once()
            ->with('TOKEN', 'CHANNEL_ID', 'message')
            ->andReturn(101);

        $job = new TelegramRestockNotification('stable-batch-id', 42, 'message');
        $job->handle($client);
        $job->handle($client);

        $this->assertTrue(Cache::has('telegram-restock:sent:stable-batch-id'));
    }
}
