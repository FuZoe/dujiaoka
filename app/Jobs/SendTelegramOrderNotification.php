<?php

namespace App\Jobs;

use App\Models\TelegramOrderNotification;
use App\Service\TelegramBotClient;
use App\Service\TelegramOrderNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SendTelegramOrderNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 25;

    private $notificationId;

    public function __construct(int $notificationId)
    {
        $this->notificationId = $notificationId;
    }

    public function handle(
        TelegramBotClient $client,
        TelegramOrderNotificationService $messages
    ): void {
        $notification = TelegramOrderNotification::query()
            ->with(['order.customer'])
            ->find($this->notificationId);

        if (!$notification || $notification->status === 'sent') {
            return;
        }

        $order = $notification->order;
        $customer = $order ? $order->customer : null;
        if (!$customer || !$customer->isTelegramBound()) {
            $notification->status = 'skipped';
            $notification->last_error = 'private_binding_missing';
            $notification->save();
            return;
        }

        $parts = $messages->buildParts($order, $notification->event_key);
        try {
            for ($index = (int) $notification->next_part; $index < count($parts); $index++) {
                $options = [];
                if ($index === count($parts) - 1) {
                    $options['reply_markup'] = $messages->buttons($order, $notification->event_key);
                }
                $client->sendMessage(
                    (string) dujiaoka_config_get('telegram_bot_token'),
                    (string) $customer->telegram_chat_id,
                    $parts[$index],
                    $options
                );
                $notification->next_part = $index + 1;
                $notification->status = 'sending';
                $notification->last_error = null;
                $notification->save();
            }

            $notification->status = 'sent';
            $notification->sent_at = now();
            $notification->save();
            Log::info('Telegram private order notification sent.', [
                'notification_id' => $notification->getKey(),
                'order_id' => $order->getKey(),
                'event_key' => $notification->event_key,
                'parts' => count($parts),
            ]);
        } catch (Throwable $exception) {
            $notification->status = 'retrying';
            $notification->last_error = get_class($exception);
            $notification->save();
            throw new RuntimeException('Telegram private order notification request failed.');
        }
    }

    public function failed(Throwable $exception): void
    {
        TelegramOrderNotification::query()
            ->whereKey($this->notificationId)
            ->update(['status' => 'failed', 'last_error' => get_class($exception)]);
        Log::error('Telegram private order notification exhausted all attempts.', [
            'notification_id' => $this->notificationId,
        ]);
    }
}
