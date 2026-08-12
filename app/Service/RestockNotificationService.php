<?php

namespace App\Service;

use App\Jobs\TelegramRestockNotification;
use App\Models\Carmis;
use App\Models\Goods;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RestockNotificationService
{
    public function dispatchForImport(
        Goods $goods,
        int $stockBefore,
        int $stockAfter,
        int $insertedCount,
        string $batchId
    ): bool {
        if (!$this->shouldNotify($stockBefore, $stockAfter, $insertedCount)) {
            return false;
        }

        if ((int) dujiaoka_config_get('is_open_telegram_restock', Goods::STATUS_CLOSE) !== Goods::STATUS_OPEN) {
            return false;
        }

        return $this->dispatch($goods, $stockAfter, $batchId, false);
    }

    public function dispatchTest(Goods $goods): ?string
    {
        $batchId = (string) Str::uuid();
        $stock = (int) $goods->carmis()
            ->where('status', Carmis::STATUS_UNSOLD)
            ->count();

        return $this->dispatch($goods, $stock, $batchId, true) ? $batchId : null;
    }

    public function shouldNotify(int $stockBefore, int $stockAfter, int $insertedCount): bool
    {
        return $insertedCount > 0 && $stockAfter > 0 && $stockAfter > $stockBefore;
    }

    public function buildMessage(Goods $goods, int $stock, bool $isTest): string
    {
        $title = $isTest ? '补货通知测试' : '商品补货通知';
        $price = number_format((float) $goods->actual_price, 2, '.', '');
        $url = rtrim((string) config('app.url'), '/').'/buy/'.$goods->getKey();

        return "【{$title}】\n"
            ."{$goods->gd_name} 已补货，欢迎选购！\n\n"
            ."当前库存：{$stock}\n"
            ."销售价格：¥{$price}\n"
            ."购买链接：{$url}";
    }

    private function dispatch(Goods $goods, int $stock, string $batchId, bool $isTest): bool
    {
        if (!$this->hasTelegramConfiguration()) {
            Log::warning('Telegram restock notification skipped: configuration is incomplete.', [
                'batch_id' => $batchId,
                'goods_id' => $goods->getKey(),
            ]);
            return false;
        }

        try {
            TelegramRestockNotification::dispatch(
                $batchId,
                (int) $goods->getKey(),
                $this->buildMessage($goods, $stock, $isTest),
                $isTest
            );
        } catch (Throwable $exception) {
            Log::warning('Telegram restock notification could not be queued.', [
                'batch_id' => $batchId,
                'goods_id' => $goods->getKey(),
                'exception' => get_class($exception),
            ]);
            return false;
        }

        return true;
    }

    private function hasTelegramConfiguration(): bool
    {
        return trim((string) dujiaoka_config_get('telegram_bot_token')) !== ''
            && trim((string) dujiaoka_config_get('telegram_userid')) !== '';
    }
}
