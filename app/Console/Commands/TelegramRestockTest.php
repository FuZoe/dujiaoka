<?php

namespace App\Console\Commands;

use App\Models\Goods;
use App\Service\RestockNotificationService;
use Illuminate\Console\Command;

class TelegramRestockTest extends Command
{
    protected $signature = 'telegram:restock-test {goods_id? : Product ID used in the test message}';
    protected $description = 'Queue a clearly labelled Telegram restock test notification';

    public function handle(RestockNotificationService $notifications): int
    {
        $query = Goods::query()->where('type', Goods::AUTOMATIC_DELIVERY);
        $goods = $this->argument('goods_id')
            ? $query->whereKey((int) $this->argument('goods_id'))->first()
            : $query->where('is_open', Goods::STATUS_OPEN)->orderBy('id')->first();

        if (!$goods) {
            $this->error('No matching automatic-delivery product was found.');
            return 1;
        }

        $batchId = $notifications->dispatchTest($goods);
        if (!$batchId) {
            $this->error('The test notification was not queued. Check the application log.');
            return 1;
        }

        $this->info('Queued Telegram restock test batch '.$batchId.'.');
        return 0;
    }
}
