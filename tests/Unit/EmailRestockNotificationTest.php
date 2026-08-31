<?php

namespace Tests\Unit;

use App\Jobs\EmailRestockNotification;
use App\Jobs\EmailStockNotification;
use App\Service\ConfiguredMailSender;
use App\Service\SystemSettingStore;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class EmailRestockNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $settings = new \ReflectionProperty(SystemSettingStore::class, 'settings');
        $settings->setAccessible(true);
        $settings->setValue(null, null);
        Cache::forever('system-setting', [
            'is_open_email_restock' => 1,
            'email_restock_recipient' => 'fxq45@qq.com',
            'driver' => 'array',
        ]);
    }

    public function test_the_same_batch_is_sent_only_once(): void
    {
        $sender = Mockery::mock(ConfiguredMailSender::class);
        $sender->shouldReceive('send')
            ->once()
            ->with('fxq45@qq.com', 'subject', 'content');
        $this->app->instance(ConfiguredMailSender::class, $sender);

        $job = new EmailRestockNotification(
            'stable-email-batch',
            42,
            'fxq45@qq.com',
            'subject',
            'content'
        );
        $job->handle();
        $job->handle();

        $this->assertTrue(Cache::has(EmailStockNotification::sentCacheKey('restock:stable-email-batch')));
    }

    public function test_disabled_switch_skips_sending(): void
    {
        Cache::forever('system-setting', [
            'is_open_email_restock' => 0,
            'driver' => 'array',
        ]);
        $sender = Mockery::mock(ConfiguredMailSender::class);
        $sender->shouldNotReceive('send');
        $this->app->instance(ConfiguredMailSender::class, $sender);

        (new EmailRestockNotification(
            'disabled-email-batch',
            42,
            'fxq45@qq.com',
            'subject',
            'content'
        ))->handle();

        $this->assertFalse(Cache::has(EmailStockNotification::sentCacheKey('restock:disabled-email-batch')));
    }

    public function test_invalid_recipient_is_rejected_before_sending(): void
    {
        $sender = Mockery::mock(ConfiguredMailSender::class);
        $sender->shouldNotReceive('send');
        $this->app->instance(ConfiguredMailSender::class, $sender);

        $this->expectException(\RuntimeException::class);
        (new EmailRestockNotification(
            'invalid-email-batch',
            42,
            'not-an-email',
            'subject',
            'content'
        ))->handle();
    }
}
