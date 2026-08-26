<?php

namespace App\Http\Controllers\Pay;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\PayController;
use App\Models\BinancePayAttempt;
use App\Models\BinancePaySetting;
use App\Models\Order;
use App\Service\BinancePayQuoteService;
use Illuminate\Http\JsonResponse;

class BinancePayController extends PayController
{
    public function gateway(
        string $payway,
        string $orderSN,
        BinancePayQuoteService $quotes
    ) {
        try {
            if ($payway !== 'binancepay') {
                throw new RuleValidationException(__('dujiaoka.prompt.pay_gateway_does_not_exist'));
            }
            $this->loadGateWay($orderSN, $payway);
            $setting = BinancePaySetting::current();
            if (!$setting->isReady()) {
                throw new RuleValidationException(__('pay.binance.checkout.not_ready'));
            }

            $attempt = $quotes->quote($this->order);

            return view('unicorn.static_pages.binancepay', [
                'order' => $this->order,
                'attempt' => $attempt,
                'config' => $setting,
                'statusUrl' => shop_global_url('/pay/binance/status/' . $this->order->order_sn),
                'returnUrl' => shop_url('/detail-order-sn/' . $this->order->order_sn),
            ])->with('page_title', __('pay.binance.checkout.page_title'));
        } catch (RuleValidationException $exception) {
            return $this->err($exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);

            return $this->err(__('pay.binance.checkout.create_failed'));
        }
    }

    public function status(string $orderSN): JsonResponse
    {
        $order = $this->orderService->detailOrderSN(strtoupper($orderSN));
        if (!$order) {
            return response()->json(['status' => 'not_found', 'paid' => false], 404);
        }

        $attempt = BinancePayAttempt::query()
            ->where('order_id', $order->id)
            ->latest('id')
            ->first();
        if (!$attempt) {
            return response()->json(['status' => 'not_found', 'paid' => false], 404);
        }

        $paid = $attempt->status === BinancePayAttempt::STATUS_PAID;

        return response()->json([
            'status' => $paid ? 'paid' : (string) $attempt->status,
            'paid' => $paid,
            'redirect_url' => $paid ? shop_url('/detail-order-sn/' . $order->order_sn) : null,
            'expires_at' => optional($attempt->expires_at)->toIso8601String(),
            'expected_usdt' => $attempt->expected_usdt,
        ]);
    }
}
