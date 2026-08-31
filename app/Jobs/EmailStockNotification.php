<?php

namespace App\Jobs;

use App\Service\ConfiguredMailSender;
use App\Service\RestockNotificationService;
use App\Service\SystemSettingStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Sends one stock-related email and makes retries/idempotent duplicate jobs
 * harmless. The event key is stable for the relevant stock transition.
 */
class EmailStockNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TYPE_RESTOCK = 'restock';
    public const TYPE_OUT_OF_STOCK = 'out_of_stock';

    public $tries = 3;
    public $timeout = 30;

    /** @var string */
    protected $eventKey;

    /** @var int */
    protected $goodsId;

    /** @var string */
    protected $recipient;

    /** @var string */
    protected $title;

    /** @var string */
    protected $content;

    /** @var string */
    protected $settingKey;

    /** @var string */
    protected $type;

    public function __construct(
        string $eventKey,
        int $goodsId,
        string $recipient,
        string $title,
        string $content,
        string $settingKey,
        string $type = self::TYPE_RESTOCK
    ) {
        $this->eventKey = $eventKey;
        $this->goodsId = $goodsId;
        $this->recipient = $recipient;
        $this->title = $title;
        $this->content = $content;
        $this->settingKey = $settingKey;
        $this->type = $type;
    }

    public function eventKey(): string
    {
        return $this->eventKey;
    }

    public function goodsId(): int
    {
        return $this->goodsId;
    }

    public function recipient(): string
    {
        return $this->recipient;
    }

    public static function sentCacheKey(string $eventKey): string
    {
        return 'email-stock-notification:sent:'.sha1($eventKey);
    }

    public static function lockCacheKey(string $eventKey): string
    {
        return 'email-stock-notification:lock:'.sha1($eventKey);
    }

    public static function queuedCacheKey(string $eventKey): string
    {
        return 'email-stock-notification:queued:'.sha1($eventKey);
    }

    public function handle(): void
    {
        $settings = SystemSettingStore::refresh();
        $defaultEnabled = $this->type === self::TYPE_OUT_OF_STOCK ? 1 : 0;
        if ((int) ($settings[$this->settingKey] ?? $defaultEnabled) !== 1) {
            Log::info('Stock email notification skipped because it is disabled.', [
                'event_key' => $this->eventKey,
                'goods_id' => $this->goodsId,
                'type' => $this->type,
            ]);
            return;
        }

        if (!filter_var($this->recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Stock email notification recipient is invalid.');
        }

        $lock = Cache::lock(self::lockCacheKey($this->eventKey), 60);
        if (!$lock->get()) {
            return;
        }

        try {
            if (Cache::has(self::sentCacheKey($this->eventKey))) {
                return;
            }

            // A queued depletion alert can outlive a quick manual restock.
            // Re-check before sending so the message never reports a product
            // as sold out after it is available again.
            if ($this->type === self::TYPE_OUT_OF_STOCK && $this->goodsId > 0) {
                $goods = \App\Models\Goods::query()->find($this->goodsId);
                if (!$goods) {
                    Log::info('Stock email notification skipped because the product no longer exists.', [
                        'event_key' => $this->eventKey,
                        'goods_id' => $this->goodsId,
                    ]);
                    return;
                }
                $stock = app(RestockNotificationService::class)->availableStock($goods);
                if ($stock === null) {
                    throw new RuntimeException('Unable to verify current product stock.');
                }
                if ($stock > 0) {
                    Log::info('Out-of-stock email notification skipped because stock was restored.', [
                        'event_key' => $this->eventKey,
                        'goods_id' => $this->goodsId,
                        'stock' => $stock,
                    ]);
                    return;
                }
            }

            app(ConfiguredMailSender::class)->send(
                $this->recipient,
                $this->title,
                $this->content
            );
            Cache::put(self::sentCacheKey($this->eventKey), true, now()->addDays(30));

            Log::info('Stock email notification sent.', [
                'event_key' => $this->eventKey,
                'goods_id' => $this->goodsId,
                'type' => $this->type,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Stock email notification request failed.', [
                'event_key' => $this->eventKey,
                'goods_id' => $this->goodsId,
                'type' => $this->type,
                'exception' => get_class($exception),
            ]);

            throw new RuntimeException('Stock email notification request failed.', 0, $exception);
        } finally {
            $lock->release();
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Stock email notification exhausted all attempts.', [
            'event_key' => $this->eventKey,
            'goods_id' => $this->goodsId,
            'type' => $this->type,
            'exception' => get_class($exception),
        ]);
    }
}
