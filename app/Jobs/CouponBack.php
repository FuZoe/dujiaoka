<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class CouponBack implements ShouldQueue
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

    private $order;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        DB::transaction(function () {
            $order = Order::query()->lockForUpdate()->find($this->order->id);
            if (!$order
                || (int) $order->status !== Order::STATUS_EXPIRED
                || !(int) $order->coupon_id
                || (int) $order->coupon_ret_back === Order::COUPON_BACK_OK) {
                return;
            }

            app('Service\CouponService')->retIncrByID($order->coupon_id);
            Order::query()->whereKey($order->id)->update([
                'coupon_ret_back' => Order::COUPON_BACK_OK,
            ]);
        });
    }
}
