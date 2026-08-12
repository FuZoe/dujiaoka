<?php

namespace App\Jobs;

use App\Service\TelegramBotClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class TelegramPrivateMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 25;

    private $chatId;
    private $message;
    private $options;

    public function __construct(string $chatId, string $message, array $options = [])
    {
        $this->chatId = $chatId;
        $this->message = $message;
        $this->options = $options;
    }

    public function handle(TelegramBotClient $client): void
    {
        if (!preg_match('/^[1-9][0-9]*$/', $this->chatId)) {
            throw new RuntimeException('Telegram private message target is invalid.');
        }

        $client->sendMessage(
            (string) dujiaoka_config_get('telegram_bot_token'),
            $this->chatId,
            $this->message,
            $this->options
        );
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Telegram private message exhausted all attempts.', [
            'target_type' => 'private',
            'exception' => get_class($exception),
        ]);
    }
}
