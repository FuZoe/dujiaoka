<?php

namespace Tests\Unit;

use App\Jobs\EmailOutOfStockNotification;
use App\Jobs\EmailStockNotification;
use App\Service\ConfiguredMailSender;
use App\Service\SystemSettingStore;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class EmailOutOfStockNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $settings = new \ReflectionProperty(SystemSettingStore::class, 'settings');
        $settings->setAccessible(true);
        $settings->setValue(null, null);
        Cache::forever('system-setting', [
            'is_open_email_out_of_stock' => 1,
            'email_restock_recipient' => 'fxq45@qq.com',
            'driver' => 'array',
        ]);
    }

    public function test_sold_out_email_is_sent_once(): void
    {
        $sender = Mockery::mock(ConfiguredMailSender::class);
        $sender->shouldReceive('send')
            ->once()
            ->with('fxq45@qq.com', 'subject', 'content');
        $this->app->instance(ConfiguredMailSender::class, $sender);

        $job = new EmailOutOfStockNotification(0, 'fxq45@qq.com', 'subject', 'content');
        $job->handle();
        $job->handle();

        $eventKey = 'out-of-stock:goods:0';
        $this->assertTrue(Cache::has(EmailStockNotification::sentCacheKey($eventKey)));
    }

    public function test_disabled_switch_does_not_send(): void
    {
        Cache::forever('system-setting', ['is_open_email_out_of_stock' => 0]);
        $sender = Mockery::mock(ConfiguredMailSender::class);
        $sender->shouldNotReceive('send');
        $this->app->instance(ConfiguredMailSender::class, $sender);

        (new EmailOutOfStockNotification(0, 'fxq45@qq.com', 'subject', 'content'))->handle();

        $this->assertFalse(Cache::has(EmailStockNotification::sentCacheKey('out-of-stock:goods:0')));
    }

    public function test_missing_switch_defaults_to_enabled_for_existing_installations(): void
    {
        Cache::forever('system-setting', [
            'email_restock_recipient' => 'fxq45@qq.com',
            'driver' => 'array',
        ]);
        $sender = Mockery::mock(ConfiguredMailSender::class);
        $sender->shouldReceive('send')
            ->once()
            ->with('fxq45@qq.com', 'subject', 'content');
        $this->app->instance(ConfiguredMailSender::class, $sender);

        (new EmailOutOfStockNotification(0, 'fxq45@qq.com', 'subject', 'content'))->handle();

        $this->assertTrue(Cache::has(
            EmailStockNotification::sentCacheKey('out-of-stock:goods:0')
        ));
    }
}
