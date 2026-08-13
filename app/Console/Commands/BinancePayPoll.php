<?php

namespace App\Console\Commands;

use App\Service\BinancePayMatcher;
use Illuminate\Console\Command;
use Throwable;

class BinancePayPoll extends Command
{
    protected $signature = 'binance-pay:poll
        {--once : Poll once and exit}
        {--daemon : Keep polling}
        {--sleep= : Seconds between polls in daemon mode}';

    protected $description = 'Poll read-only Binance Pay transactions and match pending shop orders';

    public function handle(BinancePayMatcher $matcher): int
    {
        $daemon = (bool) $this->option('daemon') && !(bool) $this->option('once');
        $configured = max(60, (int) config('services.binance_pay.poll_interval_seconds', 60));
        $requested = $this->option('sleep') === null ? 0 : (int) $this->option('sleep');
        $sleep = max($configured, $requested);

        do {
            try {
                $result = $matcher->poll();
                $this->info(sprintf(
                    'Binance Pay poll complete: checked=%d matched=%d expired=%d',
                    $result['checked'],
                    $result['matched'],
                    $result['expired']
                ));
            } catch (Throwable $exception) {
                $this->error('Binance Pay poll failed: '.$exception->getMessage());
                if (!$daemon) {
                    return 1;
                }
            }

            if ($daemon) {
                sleep($sleep);
            }
        } while ($daemon);

        return 0;
    }
}
