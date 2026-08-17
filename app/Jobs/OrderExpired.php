<?php

namespace App\Jobs;

use App\Models\BinancePayAttempt;
use App\Models\Order;
use App\Service\NewzoePaymentWindow;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class OrderExpired implements ShouldQueue
{

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 任务最大尝试次数。
     *
     * @var int
     */
    public $tries = 3;

    /**
     * 任务可以执行的最大秒数 (超时时间)。
     *
     * @var int
     */
    public $timeout = 20;

    /**
     * 订单号
     * @var string
     */
    private $orderSN;


    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(string $orderSN)
    {
        $this->orderSN = $orderSN;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // 如果x分钟后还没支付就算过期
        $order = Order::query()->where('order_sn', $this->orderSN)->first();
        if (!$order || $this->deferForBinanceSettlement($order) || $this->deferForNewzoeSettlement($order)) {
            return;
        }
        if ((int) $order->status !== Order::STATUS_WAIT_PAY) {
            return;
        }
        if (app('Service\OrderService')->expiredOrderSN($this->orderSN)) {
            // 回退优惠券
            CouponBack::dispatch($order);
        }
    }

    private function deferForNewzoeSettlement(Order $order): bool
    {
        if ((int) $order->status !== Order::STATUS_WAIT_PAY
            || optional($order->pay)->pay_check !== 'newzoe-wechat') {
            return false;
        }

        $deadline = app(NewzoePaymentWindow::class)->responseExpiresAt($order);
        if ($deadline->lte(Carbon::now())) {
            return false;
        }

        self::dispatch($this->orderSN)->delay($deadline);

        return true;
    }

    private function deferForBinanceSettlement(Order $order): bool
    {
        $attempt = BinancePayAttempt::query()
            ->where('order_id', $order->id)
            ->whereIn('status', [
                BinancePayAttempt::STATUS_PENDING,
                BinancePayAttempt::STATUS_PROCESSING,
                BinancePayAttempt::STATUS_EXPIRED,
            ])
            ->first();
        if (!$attempt || !$attempt->expires_at) {
            return false;
        }

        $graceSeconds = max(60, (int) config('services.binance_pay.settlement_grace_seconds', 300));
        $pollBufferSeconds = max(4, (int) config('services.binance_pay.poll_interval_seconds', 60));
        $settlementDeadline = $attempt->expires_at->copy()
            ->addSeconds($graceSeconds + $pollBufferSeconds);
        if ($settlementDeadline->lte(Carbon::now())) {
            // A failed fulfilment can leave the order PROCESSING while the
            // matcher rolls the attempt back. Keep one recovery check queued.
            if ((int) $order->status === Order::STATUS_PROCESSING) {
                self::dispatch($this->orderSN)
                    ->delay(Carbon::now()->addSeconds($pollBufferSeconds));

                return true;
            }

            return false;
        }

        self::dispatch($this->orderSN)->delay($settlementDeadline);

        return true;
    }
}
