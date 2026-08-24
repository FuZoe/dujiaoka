<?php

namespace App\Jobs;

use App\Service\TelegramShopBotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramShopInteraction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 30;

    private $update;

    public function __construct(array $update)
    {
        $this->update = $update;
    }

    public function handle(TelegramShopBotService $shop): void
    {
        $shop->handleUpdate($this->update);
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Telegram shop interaction failed.', [
            'exception' => get_class($exception),
        ]);
    }
}
