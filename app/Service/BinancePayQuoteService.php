<?php

namespace App\Service;

use App\Models\BinancePayAttempt;
use App\Models\BinancePaySetting;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BinancePayQuoteService
{
    private const QUOTE_DECIMALS = 2;

    public function quote(Order $order): BinancePayAttempt
    {
        BinancePaySetting::current();

        return DB::transaction(function () use ($order) {
            // Serialize allocation before reading any candidate amount. A
            // no-op write also provides a real mutex under SQLite tests.
            DB::table('binance_pay_settings')
                ->where('id', 1)
                ->update(['id' => DB::raw('id')]);
            $setting = BinancePaySetting::query()->lockForUpdate()->findOrFail(1);
            $now = Carbon::now();
            if (!$setting->isReady()) {
                throw new RuntimeException('Binance Pay is not configured and tested.');
            }
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ((int) $lockedOrder->status !== Order::STATUS_WAIT_PAY) {
                throw new RuntimeException('The order is not awaiting payment.');
            }

            $existing = BinancePayAttempt::query()->where('order_id', $lockedOrder->id)->first();
            if ($existing) {
                if ($existing->status === BinancePayAttempt::STATUS_PENDING && $existing->expires_at->gt($now)) {
                    return $existing;
                }
                throw new RuntimeException('The Binance Pay quote has expired.');
            }

            $rate = (string) $setting->cny_per_usdt;
            $currency = strtoupper((string) config('services.binance_pay.currency', 'USDT'));
            // Binance checkout amounts are deliberately quoted in whole cents.
            // The smallest unit for collision avoidance is therefore 0.01 USDT.
            $precision = self::QUOTE_DECIMALS;
            $candidateUnits = $this->ceilQuoteUnits((string) $lockedOrder->actual_price, $rate, $precision);
            $quotedAmount = $this->formatUnits($candidateUnits, $precision);
            $attempts = 0;
            while ($this->amountIsReserved($currency, $quotedAmount, $now)) {
                $candidateUnits = bcadd($candidateUnits, '1', 0);
                $quotedAmount = $this->formatUnits($candidateUnits, $precision);
                if (++$attempts > 100000) {
                    throw new RuntimeException('Binance Pay could not allocate a unique amount.');
                }
            }

            $shopExpiry = ($lockedOrder->created_at ?: $now)->copy()
                ->addMinutes(max(5, (int) dujiaoka_config_get('order_expire_time', 20)));
            $configuredExpiry = $now->copy()
                ->addMinutes(max(5, (int) config('services.binance_pay.quote_ttl_minutes', 15)));
            // Settlement grace is applied after this deadline by the matcher. It
            // must not consume the customer's active checkout window.
            $expiresAt = $configuredExpiry->lt($shopExpiry) ? $configuredExpiry : $shopExpiry;
            if ($expiresAt->lte($now->copy()->addMinute())) {
                throw new RuntimeException('The order is too close to expiry for Binance Pay.');
            }

            return BinancePayAttempt::query()->create([
                'order_id' => $lockedOrder->id,
                'order_sn' => $lockedOrder->order_sn,
                'status' => BinancePayAttempt::STATUS_PENDING,
                'currency' => $currency,
                'quoted_amount' => $quotedAmount,
                'cny_amount' => (string) $lockedOrder->actual_price,
                'rate' => $rate,
                'activated_at' => $now,
                'expires_at' => $expiresAt,
            ]);
        }, 3);
    }

    private function amountIsReserved(string $currency, string $quotedAmount, Carbon $now): bool
    {
        $graceSeconds = max(60, (int) config('services.binance_pay.settlement_grace_seconds', 300));
        $reuseCutoff = $now->copy()->subSeconds($graceSeconds);

        return BinancePayAttempt::query()
            ->where('currency', $currency)
            ->where('quoted_amount', $quotedAmount)
            ->where('expires_at', '>=', $reuseCutoff)
            ->exists();
    }

    private function ceilQuoteUnits(string $amount, string $rate, int $precision): string
    {
        $scale = bcpow('10', (string) $precision, 0);
        $scaledAmount = bcmul($amount, $scale, 8);
        $units = bcdiv($scaledAmount, $rate, 0);
        $coveredAmount = bcmul($units, $rate, 8);
        if (bccomp($coveredAmount, $scaledAmount, 8) < 0) {
            $units = bcadd($units, '1', 0);
        }

        return bccomp($units, '1', 0) < 0 ? '1' : $units;
    }

    private function formatUnits(string $units, int $precision): string
    {
        return bcdiv($units, bcpow('10', (string) $precision, 0), $precision);
    }
}
