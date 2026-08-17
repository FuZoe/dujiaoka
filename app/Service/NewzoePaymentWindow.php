<?php

namespace App\Service;

use App\Models\Order;
use Carbon\Carbon;

class NewzoePaymentWindow
{
    public function paymentMinutes(): int
    {
        return max(1, (int) config('services.newzoe_pay.payment_minutes', 20));
    }

    public function settlementGraceMinutes(): int
    {
        return max(0, (int) config('services.newzoe_pay.settlement_grace_minutes', 5));
    }

    public function paymentExpiresAt(Order $order): Carbon
    {
        return Carbon::parse($order->created_at ?: Carbon::now())
            ->addMinutes($this->paymentMinutes());
    }

    public function responseExpiresAt(Order $order): Carbon
    {
        return $this->paymentExpiresAt($order)
            ->addMinutes($this->settlementGraceMinutes());
    }

    public function paymentIsOpen(Order $order, ?Carbon $at = null): bool
    {
        return ($at ?: Carbon::now())->lt($this->paymentExpiresAt($order));
    }

    public function responseIsOpen(Order $order, ?Carbon $at = null): bool
    {
        return ($at ?: Carbon::now())->lt($this->responseExpiresAt($order));
    }
}
