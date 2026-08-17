<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Service\NewzoePaymentWindow;
use Carbon\Carbon;
use Tests\TestCase;

class NewzoePaymentWindowTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_payment_window_defaults_to_twenty_minutes(): void
    {
        $window = new NewzoePaymentWindow();

        $this->assertSame(20, $window->paymentMinutes());
        $this->assertSame(5, $window->settlementGraceMinutes());
    }

    public function test_payment_and_response_deadlines_have_exact_boundaries(): void
    {
        $createdAt = Carbon::parse('2026-08-17 12:00:00');
        Carbon::setTestNow($createdAt);
        config([
            'services.newzoe_pay.payment_minutes' => 20,
            'services.newzoe_pay.settlement_grace_minutes' => 5,
        ]);

        $order = new Order();
        $order->created_at = $createdAt->copy();
        $window = new NewzoePaymentWindow();

        $this->assertTrue($window->paymentExpiresAt($order)->equalTo($createdAt->copy()->addMinutes(20)));
        $this->assertTrue($window->responseExpiresAt($order)->equalTo($createdAt->copy()->addMinutes(25)));
        $this->assertTrue($window->paymentIsOpen($order, $createdAt->copy()->addMinutes(20)->subMillisecond()));
        $this->assertFalse($window->paymentIsOpen($order, $createdAt->copy()->addMinutes(20)));
        $this->assertTrue($window->responseIsOpen($order, $createdAt->copy()->addMinutes(25)->subMillisecond()));
        $this->assertFalse($window->responseIsOpen($order, $createdAt->copy()->addMinutes(25)));
    }
}
