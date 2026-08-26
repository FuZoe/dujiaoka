<?php

namespace App\Service;

use App\Jobs\SendTelegramOrderNotification;
use App\Models\Order;
use App\Models\TelegramOrderNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramOrderNotificationService
{
    public function queueCreated(Order $order): bool
    {
        return $this->queue($order, 'created');
    }

    public function queuePaid(Order $order): bool
    {
        return $this->queue($order, 'paid');
    }

    public function queueStatus(Order $order): bool
    {
        return $this->queue($order, 'status:'.$order->status);
    }

    public function queue(Order $order, string $eventKey): bool
    {
        if ((int) dujiaoka_config_get('is_open_telegram_customer_order', 0) !== 1) {
            return false;
        }

        $customer = $order->customer ?: $order->customer()->first();
        if (!$customer || !$customer->isTelegramBound()) {
            return false;
        }

        try {
            $notification = TelegramOrderNotification::query()->firstOrCreate(
                ['order_id' => $order->getKey(), 'event_key' => $eventKey],
                ['status' => 'queued', 'next_part' => 0]
            );

            if (!$notification->wasRecentlyCreated) {
                return false;
            }

            SendTelegramOrderNotification::dispatch($notification->getKey())
                ->delay(now()->addSeconds(2));
        } catch (Throwable $exception) {
            Log::warning('Telegram order notification could not be queued.', [
                'order_id' => $order->getKey(),
                'event_key' => $eventKey,
                'exception' => get_class($exception),
            ]);
            return false;
        }

        return true;
    }

    public function buildParts(Order $order, string $eventKey): array
    {
        $status = $this->statusLabel((int) $order->status);
        $heading = $this->eventHeading($eventKey, $status);
        $message = $heading."\n\n"
            .'商品：'.$this->clean((string) $order->title)."\n"
            .'数量：'.(int) $order->buy_amount."\n"
            .'金额：¥'.number_format((float) $order->actual_price, 2, '.', '')."\n"
            .'订单号：'.$this->clean((string) $order->order_sn)."\n"
            .'状态：'.$status;

        if ($eventKey === 'created') {
            $message .= "\n\n请在订单页面完成支付。";
        }

        if ($eventKey === 'status:'.Order::STATUS_COMPLETED
            && (int) dujiaoka_config_get('telegram_send_order_cards', 1) === 1
            && trim((string) $order->info) !== ''
        ) {
            $message .= "\n\n发货内容：\n".$this->clean((string) $order->info, false);
        }

        return $this->split($message, 3500);
    }

    public function buttons(Order $order, string $eventKey): array
    {
        $orderCallback = 'shop:order:'.strtoupper(trim((string) $order->order_sn));
        if (trim((string) $order->getAttribute('telegram_chat_id')) !== ''
            && strlen($orderCallback) <= 64
        ) {
            // Bot-created customers do not have web-login credentials. Keep
            // their status and delivery flow inside the private bot chat.
            $buttons = [[
                'text' => '查看订单',
                'callback_data' => $orderCallback,
            ]];
        } else {
            $buttons = [[
                'text' => '查看订单',
                'url' => url('/account/orders/'.$order->getKey()),
            ]];
        }

        if ($eventKey === 'created') {
            $buttons[] = [
                'text' => '前往支付',
                'url' => url('/bill/'.$order->order_sn),
            ];
        }

        return ['inline_keyboard' => [$buttons]];
    }

    private function eventHeading(string $eventKey, string $status): string
    {
        if ($eventKey === 'created') {
            return '订单已创建';
        }
        if ($eventKey === 'paid') {
            return '支付成功';
        }
        return '订单状态更新：'.$status;
    }

    private function statusLabel(int $status): string
    {
        return [
            Order::STATUS_WAIT_PAY => '待支付',
            Order::STATUS_PENDING => '待处理',
            Order::STATUS_PROCESSING => '处理中',
            Order::STATUS_COMPLETED => '已完成',
            Order::STATUS_FAILURE => '处理失败',
            Order::STATUS_EXPIRED => '已过期',
            Order::STATUS_ABNORMAL => '异常',
        ][$status] ?? '未知';
    }

    private function clean(string $value, bool $stripMarkup = true): string
    {
        if ($stripMarkup) {
            $value = strip_tags($value);
        }
        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value));
    }

    private function split(string $message, int $limit): array
    {
        if (mb_strlen($message, 'UTF-8') <= $limit) {
            return [$message];
        }

        $parts = [];
        while (mb_strlen($message, 'UTF-8') > $limit) {
            $cut = mb_substr($message, 0, $limit, 'UTF-8');
            $breakAt = mb_strrpos($cut, "\n", 0, 'UTF-8');
            if ($breakAt === false || $breakAt < (int) ($limit * 0.6)) {
                $breakAt = $limit;
            }
            $parts[] = rtrim(mb_substr($message, 0, $breakAt, 'UTF-8'));
            $message = ltrim(mb_substr($message, $breakAt, null, 'UTF-8'));
        }
        if ($message !== '') {
            $parts[] = $message;
        }

        $total = count($parts);
        return array_map(function (string $part, int $index) use ($total) {
            return '['.($index + 1).'/'.$total."]\n".$part;
        }, $parts, array_keys($parts));
    }
}
