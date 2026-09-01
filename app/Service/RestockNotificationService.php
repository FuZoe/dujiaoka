<?php

namespace App\Service;

use App\Jobs\EmailOutOfStockNotification;
use App\Jobs\EmailRestockNotification;
use App\Jobs\EmailStockNotification;
use App\Jobs\TelegramRestockNotification;
use App\Models\Carmis;
use App\Models\Goods;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RestockNotificationService
{
    private const DEFAULT_EMAIL_RECIPIENT = 'fxq45@qq.com';

    public static function normalizeTarget(string $target): string
    {
        $target = trim($target);
        if (preg_match('#^(?:https?://)?t\.me/([A-Za-z][A-Za-z0-9_]{4,31})/?$#i', $target, $matches)) {
            return '@'.$matches[1];
        }

        return $target;
    }

    public static function isValidTarget(string $target): bool
    {
        return preg_match(
            '/^(?:@[A-Za-z][A-Za-z0-9_]{4,31}|-100[0-9]{6,}|[1-9][0-9]{0,14})$/',
            self::normalizeTarget($target)
        ) === 1;
    }

    /**
     * Queue notifications after an admin card import increases stock.
     * Telegram remains controlled by its original switch; email restock
     * notices are independent and optional.
     */
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

        // A successful restock starts a new sold-out cycle for this product.
        // Clear only the sent marker; an already running job still uses its
        // own lock and will re-check the state before sending.
        $this->clearOutOfStockNotification($goods);

        $queued = false;
        if ((int) dujiaoka_config_get('is_open_telegram_restock', Goods::STATUS_CLOSE) === Goods::STATUS_OPEN) {
            $queued = $this->dispatchTelegram($goods, $stockAfter, $batchId, false) || $queued;
        }

        if ((int) dujiaoka_config_get('is_open_email_restock', Goods::STATUS_CLOSE) === Goods::STATUS_OPEN) {
            $queued = $this->dispatchRestockEmail($goods, $stockAfter, $batchId) || $queued;
        }

        return $queued;
    }

    /**
     * Queue an email when available stock crosses from positive to zero.
     * This method is called only after the payment/delivery transaction has
     * committed, so a rolled-back fulfillment cannot create a false alert.
     */
    public function dispatchForOutOfStock(
        Goods $goods,
        int $stockBefore,
        int $stockAfter,
        string $eventId = ''
    ): bool {
        try {
            if (!$this->shouldNotifyOutOfStock($stockBefore, $stockAfter)) {
                return false;
            }

            // Existing installations do not have this key until the first
            // settings save/bootstrap after deployment. Sold-out alerts are
            // intentionally opt-out, so an older settings payload must still
            // enable them.
            if ((int) dujiaoka_config_get('is_open_email_out_of_stock', Goods::STATUS_OPEN) !== Goods::STATUS_OPEN) {
                return false;
            }

            $recipient = $this->emailRecipient();
            if (!$this->hasEmailConfiguration($recipient)) {
                Log::warning('Out-of-stock email notification skipped: configuration is incomplete.', [
                    'goods_id' => $goods->getKey(),
                    'event_id' => $eventId,
                ]);
                return false;
            }

            [$title, $content] = $this->buildOutOfStockEmail($goods);
            $eventKey = 'out-of-stock:goods:'.(int) $goods->getKey();
            $queuedKey = EmailStockNotification::queuedCacheKey($eventKey);
            $enqueueLock = Cache::lock(EmailStockNotification::lockCacheKey($eventKey).':enqueue', 60);
            if (!$enqueueLock->get()) {
                return false;
            }
            try {
                if (Cache::has($queuedKey) || Cache::has(EmailStockNotification::sentCacheKey($eventKey))) {
                    return false;
                }
                // Record the enqueue only after dispatch succeeds. If the
                // process dies between these operations, a duplicate job is
                // preferable to silently losing the alert; the job's sent
                // marker makes that duplicate harmless.
                EmailOutOfStockNotification::dispatch(
                    (int) $goods->getKey(),
                    $recipient,
                    $title,
                    $content
                );
                Cache::put($queuedKey, true, now()->addDays(30));
            } finally {
                $enqueueLock->release();
            }
        } catch (Throwable $exception) {
            Log::warning('Out-of-stock email notification could not be queued.', [
                'goods_id' => $goods->getKey(),
                'event_id' => $eventId,
                'exception' => get_class($exception),
            ]);
            return false;
        }

        return true;
    }

    public function isOutOfStockEmailEnabled(): bool
    {
        try {
            $settings = SystemSettingStore::refresh();

            return (int) ($settings['is_open_email_out_of_stock'] ?? Goods::STATUS_OPEN) === Goods::STATUS_OPEN;
        } catch (Throwable $exception) {
            Log::warning('Unable to read out-of-stock email setting.', [
                'exception' => get_class($exception),
            ]);

            return false;
        }
    }

    public function dispatchTest(Goods $goods): ?string
    {
        $batchId = (string) Str::uuid();
        $stock = (int) $goods->carmis()
            ->where('status', Carmis::STATUS_UNSOLD)
            ->count();

        return $this->dispatchTelegram($goods, $stock, $batchId, true) ? $batchId : null;
    }

    public function shouldNotify(int $stockBefore, int $stockAfter, int $insertedCount): bool
    {
        return $insertedCount > 0 && $stockAfter > 0 && $stockAfter > $stockBefore;
    }

    public function shouldNotifyOutOfStock(int $stockBefore, int $stockAfter): bool
    {
        return $stockBefore > 0 && $stockAfter <= 0;
    }

    /**
     * Return the authoritative available stock for either product type.
     * A null result means the database could not be read and should not be
     * interpreted as an empty inventory.
     */
    public function availableStock(Goods $goods): ?int
    {
        $goodsId = (int) $goods->getKey();
        if ($goodsId <= 0) {
            return null;
        }

        try {
            $type = (int) $goods->getAttribute('type');
            if ($type === 0) {
                $type = (int) Goods::query()->whereKey($goodsId)->value('type');
            }

            // Do not call the supplier over the network from an order
            // settlement transaction. The inventory service uses the last
            // persisted snapshot while still subtracting queued purchases.
            if ($type === Goods::AUTOMATIC_DELIVERY) {
                return app(WarzoneInventoryService::class)->availableStock($goods, false);
            }

            $stock = Goods::query()->whereKey($goodsId)->value('in_stock');

            return max(0, (int) $stock);
        } catch (Throwable $exception) {
            Log::warning('Unable to read product stock for notification.', [
                'goods_id' => $goodsId,
                'exception' => get_class($exception),
            ]);

            return null;
        }
    }

    /**
     * Forget the current sold-out marker when stock is replenished.
     */
    public function clearOutOfStockNotification(Goods $goods): void
    {
        try {
            $eventKey = $this->outOfStockEventKey((int) $goods->getKey());
            Cache::forget(EmailStockNotification::sentCacheKey($eventKey));
            Cache::forget(EmailStockNotification::queuedCacheKey($eventKey));
        } catch (Throwable $exception) {
            // A cache outage should not turn a successful card import into a
            // failed request. The next cycle will retry the marker cleanup.
            Log::warning('Unable to reset out-of-stock notification marker.', [
                'goods_id' => $goods->getKey(),
                'exception' => get_class($exception),
            ]);
        }
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

    /**
     * Build an HTML restock email. Kept public for the notification preview
     * and for unit tests.
     *
     * @return array{0:string,1:string}
     */
    public function buildEmail(Goods $goods, int $stock): array
    {
        return $this->buildRestockEmail($goods, $stock);
    }

    public function buildRestockEmail(Goods $goods, int $stock): array
    {
        $rawName = trim((string) $goods->gd_name);
        $subjectName = preg_replace('/[\r\n]+/', ' ', $rawName);
        $subjectName = $subjectName === null ? $rawName : $subjectName;
        $name = htmlspecialchars($rawName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $url = rtrim((string) config('app.url'), '/').'/buy/'.rawurlencode((string) $goods->getKey());
        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $price = number_format((float) $goods->actual_price, 2, '.', '');
        $title = '【库存通知】'.$subjectName.' 已补货';
        $content = '<p><strong>'.$name.'</strong> 已补货，可以继续销售。</p>'
            .'<p>当前库存：'.$stock.'<br/>'
            .'销售价格：¥'.$price.'<br/>'
            .'时间：'.htmlspecialchars(now()->toDateTimeString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>'
            .'<p><a href="'.$safeUrl.'">打开商品页面</a></p>';

        return [$title, $content];
    }

    /**
     * Build the requested sold-out alert email.
     *
     * @return array{0:string,1:string}
     */
    public function buildOutOfStockEmail(Goods $goods): array
    {
        $rawName = trim((string) $goods->gd_name);
        $subjectName = preg_replace('/[\r\n]+/', ' ', $rawName);
        $subjectName = $subjectName === null ? $rawName : $subjectName;
        $name = htmlspecialchars($rawName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $url = rtrim((string) config('app.url'), '/').'/buy/'.rawurlencode((string) $goods->getKey());
        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = '【库存告警】'.$subjectName.' 已售罄';
        $content = '<p><strong>'.$name.'</strong> 当前已售罄。</p>'
            .'<p>请及时补充库存。<br/>'
            .'时间：'.htmlspecialchars(now()->toDateTimeString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>'
            .'<p><a href="'.$safeUrl.'">打开商品后台/页面</a></p>';

        return [$title, $content];
    }

    private function dispatchTelegram(Goods $goods, int $stock, string $batchId, bool $isTest): bool
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

    private function dispatchRestockEmail(Goods $goods, int $stock, string $batchId): bool
    {
        $recipient = $this->emailRecipient();
        if (!$this->hasEmailConfiguration($recipient)) {
            Log::warning('Email restock notification skipped: configuration is incomplete.', [
                'batch_id' => $batchId,
                'goods_id' => $goods->getKey(),
            ]);
            return false;
        }

        [$title, $content] = $this->buildRestockEmail($goods, $stock);
        $eventKey = 'restock:'.$batchId;
        $queuedKey = EmailStockNotification::queuedCacheKey($eventKey);
        $enqueueLock = Cache::lock(EmailStockNotification::lockCacheKey($eventKey).':enqueue', 60);
        if (!$enqueueLock->get()) {
            return false;
        }
        try {
            if (Cache::has($queuedKey) || Cache::has(EmailStockNotification::sentCacheKey($eventKey))) {
                return false;
            }
            EmailRestockNotification::dispatch(
                $batchId,
                (int) $goods->getKey(),
                $recipient,
                $title,
                $content
            );
            Cache::put($queuedKey, true, now()->addDays(30));
        } catch (Throwable $exception) {
            Log::warning('Email restock notification could not be queued.', [
                'batch_id' => $batchId,
                'goods_id' => $goods->getKey(),
                'exception' => get_class($exception),
            ]);
            return false;
        } finally {
            $enqueueLock->release();
        }

        return true;
    }

    private function emailRecipient(): string
    {
        $recipient = dujiaoka_config_get('email_restock_recipient', null);
        if ($recipient === null || trim((string) $recipient) === '') {
            $recipient = dujiaoka_config_get('stock_alert_recipient', null);
        }
        if ($recipient === null || trim((string) $recipient) === '') {
            $recipient = self::DEFAULT_EMAIL_RECIPIENT;
        }

        return trim((string) $recipient);
    }

    private function hasEmailConfiguration(string $recipient): bool
    {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $driver = strtolower(trim((string) dujiaoka_config_get('driver', config('mail.driver', 'smtp'))));
        if ($driver === '') {
            return false;
        }

        if ($driver === 'smtp') {
            $host = trim((string) dujiaoka_config_get('host', config('mail.host', '')));
            $from = trim((string) dujiaoka_config_get(
                'from_address',
                config('mail.from.address', '')
            ));

            return $host !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) !== false;
        }

        return true;
    }

    private function outOfStockEventKey(int $goodsId): string
    {
        return 'out-of-stock:goods:'.$goodsId;
    }

    private function hasTelegramConfiguration(): bool
    {
        return trim((string) dujiaoka_config_get('telegram_bot_token')) !== ''
            && self::isValidTarget((string) dujiaoka_config_get('telegram_userid'));
    }
}
