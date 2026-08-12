<?php

namespace App\Jobs;

use App\Service\TelegramBotClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class TelegramRestockNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 25;

    /**
     * @var string
     */
    private $batchId;

    /**
     * @var int
     */
    private $goodsId;

    /**
     * @var string
     */
    private $message;

    /**
     * @var bool
     */
    private $isTest;

    public function __construct(string $batchId, int $goodsId, string $message, bool $isTest = false)
    {
        $this->batchId = $batchId;
        $this->goodsId = $goodsId;
        $this->message = $message;
        $this->isTest = $isTest;
    }

    public function handle(TelegramBotClient $client): void
    {
        $channel = trim((string) dujiaoka_config_get('telegram_userid'));
        if (preg_match('/^(?:@[A-Za-z][A-Za-z0-9_]{4,31}|-100[0-9]{6,})$/', $channel) !== 1) {
            throw new RuntimeException('Telegram restock target is not a channel.');
        }

        $sentKey = 'telegram-restock:sent:'.$this->batchId;
        $lock = Cache::lock('telegram-restock:lock:'.$this->batchId, 60);

        if (!$lock->get()) {
            return;
        }

        try {
            if (Cache::has($sentKey)) {
                return;
            }

            if (!$this->isTest
                && (int) dujiaoka_config_get('is_open_telegram_restock', 0) !== 1
            ) {
                Log::info('Telegram restock notification skipped because it is disabled.', [
                    'batch_id' => $this->batchId,
                    'goods_id' => $this->goodsId,
                ]);
                return;
            }

            $messageId = $client->sendMessage(
                (string) dujiaoka_config_get('telegram_bot_token'),
                $channel,
                $this->message
            );
            Cache::put($sentKey, true, now()->addDays(7));

            Log::info('Telegram restock notification sent.', [
                'batch_id' => $this->batchId,
                'goods_id' => $this->goodsId,
                'message_id' => $messageId,
                'test' => $this->isTest,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Telegram restock notification request failed.', [
                'batch_id' => $this->batchId,
                'goods_id' => $this->goodsId,
                'exception' => get_class($exception),
            ]);

            throw new RuntimeException('Telegram restock notification request failed.');
        } finally {
            $lock->release();
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Telegram restock notification exhausted all attempts.', [
            'batch_id' => $this->batchId,
            'goods_id' => $this->goodsId,
        ]);
    }
}
