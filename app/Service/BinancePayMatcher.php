<?php

namespace App\Service;

use App\Models\BinancePayAttempt;
use App\Models\BinancePaySetting;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class BinancePayMatcher
{
    /** @var BinancePayClient */
    private $client;

    /** @var OrderProcessService */
    private $orderProcessService;

    public function __construct(BinancePayClient $client, OrderProcessService $orderProcessService)
    {
        $this->client = $client;
        $this->orderProcessService = $orderProcessService;
    }

    public function poll(): array
    {
        $setting = BinancePaySetting::current();
        if (!$setting->isReady()) {
            return ['checked' => 0, 'matched' => 0, 'expired' => 0, 'manual_review' => 0, 'skipped' => true];
        }

        $now = Carbon::now();
        $settlementGraceSeconds = max(60, (int) config('services.binance_pay.settlement_grace_seconds', 300));
        $settlementGraceCutoff = $now->copy()->subSeconds($settlementGraceSeconds);
        $this->recoverInterruptedAttempts($now);

        // Stop polling an expired quote after its settlement grace period. Keeping
        // historical expired attempts here would make every poll re-read an ever
        // growing Binance history window and eventually exhaust the API quota.
        $expired = BinancePayAttempt::query()
            ->where('status', BinancePayAttempt::STATUS_PENDING)
            ->where('expires_at', '<', $now)
            ->update(['status' => BinancePayAttempt::STATUS_EXPIRED, 'updated_at' => $now]);
        $unsettled = BinancePayAttempt::query()
            ->whereIn('status', [
                BinancePayAttempt::STATUS_PENDING,
                BinancePayAttempt::STATUS_EXPIRED,
            ])
            ->where('expires_at', '>=', $settlementGraceCutoff)
            ->oldest('activated_at')
            ->get();
        if ($unsettled->isEmpty()) {
            $this->recordPollSuccess($setting);
            return ['checked' => 0, 'matched' => 0, 'expired' => $expired, 'manual_review' => 0];
        }

        $skewSeconds = max(0, (int) config('services.binance_pay.match_time_skew_seconds', 5));
        try {
            $oldestAttempt = $unsettled->first();
            $windowStart = $oldestAttempt->activated_at->copy()->subSeconds($skewSeconds);
            $transactions = $this->transactionsInWindow(
                $setting,
                $this->timestampMilliseconds($windowStart),
                $this->timestampMilliseconds($now)
            );
            usort($transactions, function (array $left, array $right) {
                return ((int) ($left['transactionTime'] ?? 0)) <=> ((int) ($right['transactionTime'] ?? 0));
            });
            $matched = 0;
            $manualReview = 0;
            foreach ($transactions as $transaction) {
                if (!$this->isIncomingTransaction($transaction, $setting)) {
                    continue;
                }
                $attempt = $this->findAttempt($transaction, $skewSeconds);
                if ($attempt) {
                    $result = $this->settle($attempt, $transaction);
                    $matched += $result === 'paid' ? 1 : 0;
                    $manualReview += $result === 'manual_review' ? 1 : 0;
                }
            }
            $this->recordPollSuccess($setting);

            return [
                'checked' => count($transactions),
                'matched' => $matched,
                'expired' => $expired,
                'manual_review' => $manualReview,
            ];
        } catch (Throwable $exception) {
            $setting->last_error = substr($exception->getMessage(), 0, 1000);
            $setting->save();
            throw $exception;
        }
    }

    private function isIncomingTransaction(array $transaction, BinancePaySetting $setting): bool
    {
        if (!isset(
            $transaction['orderType'],
            $transaction['transactionId'],
            $transaction['transactionTime'],
            $transaction['amount'],
            $transaction['currency']
        )) {
            return false;
        }
        $amount = (string) $transaction['amount'];
        if (!preg_match('/^\d+(?:\.\d{1,8})?$/', $amount) || bccomp($amount, '0', 8) <= 0) {
            return false;
        }
        if (strtoupper((string) $transaction['currency']) !== strtoupper((string) config('services.binance_pay.currency', 'USDT'))) {
            return false;
        }
        $acceptedTypes = (array) config('services.binance_pay.accepted_order_types', ['C2C', 'PAY']);
        if (!in_array(strtoupper((string) ($transaction['orderType'] ?? '')), $acceptedTypes, true)) {
            return false;
        }
        $receiverId = trim((string) $setting->receiver_binance_id);
        $actualReceiverId = (string) ($transaction['receiverInfo']['binanceId'] ?? '');
        if ($receiverId === '' || $actualReceiverId === '' || !hash_equals($receiverId, $actualReceiverId)) {
            return false;
        }

        return !BinancePayAttempt::query()
            ->where('transaction_id', (string) $transaction['transactionId'])
            ->exists();
    }

    private function transactionsInWindow(
        BinancePaySetting $setting,
        int $startTime,
        int $endTime
    ): array {
        $limit = 100;
        $maxRequests = min(50, max(1, (int) config('services.binance_pay.max_requests_per_poll', 40)));
        $ranges = [[$startTime, max($startTime, $endTime)]];
        $requests = 0;
        $transactions = [];

        while ($ranges) {
            if ($requests >= $maxRequests) {
                throw new RuntimeException('Binance Pay transaction window exceeded the per-poll request limit.');
            }

            [$rangeStart, $rangeEnd] = array_pop($ranges);
            $batch = $this->client->transactions($setting, $rangeStart, $rangeEnd, $limit);
            $requests++;

            // Binance returns at most 100 rows in ascending time order. A full
            // page may hide newer rows, so split that time range before matching.
            if (count($batch) >= $limit) {
                if ($rangeStart >= $rangeEnd) {
                    throw new RuntimeException('Binance Pay returned a full page for an indivisible transaction timestamp.');
                }
                $midpoint = intdiv($rangeStart + $rangeEnd, 2);
                $ranges[] = [$midpoint + 1, $rangeEnd];
                $ranges[] = [$rangeStart, $midpoint];
                continue;
            }

            foreach ($batch as $transaction) {
                $transactionId = (string) ($transaction['transactionId'] ?? '');
                $key = $transactionId !== ''
                    ? 'id:'.$transactionId
                    : 'row:'.hash('sha256', json_encode($transaction));
                $transactions[$key] = $transaction;
            }
        }

        return array_values($transactions);
    }

    private function timestampMilliseconds(Carbon $time): int
    {
        return ($time->getTimestamp() * 1000) + (int) floor(((int) $time->format('u')) / 1000);
    }

    private function findAttempt(array $transaction, int $skewSeconds)
    {
        $currency = strtoupper((string) $transaction['currency']);
        $amount = bcadd((string) $transaction['amount'], '0', 8);
        $paidAt = Carbon::createFromTimestampMs((int) $transaction['transactionTime']);
        $graceSeconds = max(60, (int) config('services.binance_pay.settlement_grace_seconds', 300));
        $oldestEligibleExpiry = $paidAt->copy()->subSeconds($graceSeconds);

        return BinancePayAttempt::query()
            ->whereIn('status', [BinancePayAttempt::STATUS_PENDING, BinancePayAttempt::STATUS_EXPIRED])
            ->where('currency', $currency)
            ->where('quoted_amount', $amount)
            ->where('activated_at', '<=', $paidAt->copy()->addSeconds($skewSeconds))
            ->where('expires_at', '>=', $oldestEligibleExpiry)
            ->latest('activated_at')
            ->first();
    }

    private function settle(BinancePayAttempt $candidate, array $transaction): string
    {
        $transactionId = (string) $transaction['transactionId'];
        try {
            $claim = DB::transaction(function () use ($candidate, $transaction, $transactionId) {
                $attempt = BinancePayAttempt::query()->lockForUpdate()->find($candidate->id);
                if (!$attempt || !in_array($attempt->status, [
                    BinancePayAttempt::STATUS_PENDING,
                    BinancePayAttempt::STATUS_EXPIRED,
                ], true)) {
                    return null;
                }
                if (BinancePayAttempt::query()->where('transaction_id', $transactionId)->exists()) {
                    return null;
                }
                $paidAt = Carbon::createFromTimestampMs((int) $transaction['transactionTime']);
                $order = Order::query()->lockForUpdate()->find($attempt->order_id);
                $onTime = $paidAt->lte($attempt->expires_at);

                $attempt->status = BinancePayAttempt::STATUS_PROCESSING;
                $attempt->transaction_id = $transactionId;
                $attempt->transaction_time = $paidAt;
                $attempt->raw_transaction = $this->sanitizedTransaction($transaction);
                $attempt->last_error = null;
                $attempt->save();

                if (!$order || (int) $order->status !== Order::STATUS_WAIT_PAY || !$onTime) {
                    $attempt->status = BinancePayAttempt::STATUS_MANUAL_REVIEW;
                    $attempt->matched_at = Carbon::now();
                    $attempt->last_error = !$onTime
                        ? 'Payment arrived after the Binance Pay quote expired.'
                        : 'The shop order was no longer awaiting payment.';
                    $attempt->save();

                    return ['result' => 'manual_review'];
                }

                $order->status = Order::STATUS_PROCESSING;
                $order->save();

                return [
                    'result' => 'claimed',
                    'attempt_id' => $attempt->id,
                    'order_id' => $order->id,
                    'order_sn' => $order->order_sn,
                    'cny_amount' => (float) $order->actual_price,
                ];
            }, 3);
        } catch (QueryException $exception) {
            return 'ignored';
        }
        if (!$claim) {
            return 'ignored';
        }
        if ($claim['result'] === 'manual_review') {
            return 'manual_review';
        }

        try {
            $this->orderProcessService->completedOrder(
                $claim['order_sn'],
                $claim['cny_amount'],
                $transactionId
            );

            BinancePayAttempt::query()->whereKey($claim['attempt_id'])->update([
                'status' => BinancePayAttempt::STATUS_PAID,
                'matched_at' => Carbon::now(),
                'last_error' => null,
                'updated_at' => Carbon::now(),
            ]);

            return 'paid';
        } catch (Throwable $exception) {
            return DB::transaction(function () use ($claim, $exception) {
                $attempt = BinancePayAttempt::query()->lockForUpdate()->find($claim['attempt_id']);
                $order = Order::query()->lockForUpdate()->find($claim['order_id']);
                if (!$attempt) {
                    return 'ignored';
                }
                if ($order && !in_array((int) $order->status, [
                    Order::STATUS_WAIT_PAY,
                    Order::STATUS_PROCESSING,
                ], true)) {
                    $attempt->status = BinancePayAttempt::STATUS_PAID;
                    $attempt->matched_at = Carbon::now();
                    $attempt->last_error = null;
                    $attempt->save();

                    return 'paid';
                }
                if ($order && (int) $order->status === Order::STATUS_PROCESSING) {
                    $order->status = Order::STATUS_WAIT_PAY;
                    $order->save();
                }
                $attempt->status = BinancePayAttempt::STATUS_PENDING;
                $attempt->transaction_id = null;
                $attempt->transaction_time = null;
                $attempt->raw_transaction = null;
                $attempt->last_error = mb_substr($exception->getMessage(), 0, 1000);
                $attempt->save();

                return 'ignored';
            }, 3);
        }
    }

    private function recoverInterruptedAttempts(Carbon $now): void
    {
        $attempts = BinancePayAttempt::query()
            ->where('status', BinancePayAttempt::STATUS_PROCESSING)
            ->where('updated_at', '<=', $now->copy()->subMinutes(5))
            ->get();
        foreach ($attempts as $attempt) {
            $order = Order::query()->find($attempt->order_id);
            if ($order && !in_array((int) $order->status, [
                Order::STATUS_WAIT_PAY,
                Order::STATUS_PROCESSING,
            ], true)) {
                $attempt->status = BinancePayAttempt::STATUS_PAID;
                $attempt->matched_at = $attempt->matched_at ?: $now;
            } else {
                if ($order && (int) $order->status === Order::STATUS_PROCESSING) {
                    $order->status = Order::STATUS_WAIT_PAY;
                    $order->save();
                }
                $attempt->status = BinancePayAttempt::STATUS_PENDING;
                $attempt->transaction_id = null;
                $attempt->transaction_time = null;
                $attempt->raw_transaction = null;
            }
            $attempt->save();
        }
    }

    private function sanitizedTransaction(array $transaction): array
    {
        return [
            'orderType' => $transaction['orderType'] ?? null,
            'transactionId' => (string) $transaction['transactionId'],
            'transactionTime' => (int) $transaction['transactionTime'],
            'amount' => (string) $transaction['amount'],
            'currency' => strtoupper((string) $transaction['currency']),
            'receiverInfo' => [
                'type' => $transaction['receiverInfo']['type'] ?? null,
                'binanceId' => $transaction['receiverInfo']['binanceId'] ?? null,
            ],
        ];
    }

    private function recordPollSuccess(BinancePaySetting $setting): void
    {
        $setting->last_polled_at = Carbon::now();
        $setting->last_error = null;
        $setting->save();
    }
}
