<?php

namespace App\Models;

class BinancePayAttempt extends BaseModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PAID = 'paid';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_MANUAL_REVIEW = 'manual_review';
    public const STATUS_FAILED = 'failed';

    protected $table = 'binance_pay_attempts';

    protected $fillable = [
        'order_id',
        'order_sn',
        'status',
        'currency',
        'quoted_amount',
        'cny_amount',
        'rate',
        'transaction_id',
        'transaction_time',
        'activated_at',
        'expires_at',
        'matched_at',
        'raw_transaction',
        'last_error',
    ];

    protected $casts = [
        'transaction_time' => 'datetime',
        'activated_at' => 'datetime',
        'expires_at' => 'datetime',
        'matched_at' => 'datetime',
        'raw_transaction' => 'array',
    ];

    protected $appends = ['expected_usdt'];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function getExpectedUsdtAttribute(): string
    {
        $amount = bcadd((string) $this->quoted_amount, '0', 8);

        // New quotes use whole USDT cents and must retain both decimals in the
        // checkout/API. Existing sub-cent quotes keep their original precision.
        $centAmount = bcadd($amount, '0', 2);
        if (bccomp($amount, $centAmount, 8) === 0) {
            return $centAmount;
        }

        $amount = rtrim(rtrim($amount, '0'), '.');

        return $amount === '' ? '0' : $amount;
    }
}
