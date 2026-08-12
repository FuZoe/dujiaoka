<?php

namespace App\Http\Controllers\Pay;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\PayController;
use App\Models\Order;
use GuzzleHttp\Client;
use Illuminate\Http\Request;

class NewzoePayController extends PayController
{
    private function secret(): string
    {
        return (string) env('NEWZOE_PAY_SECRET', '');
    }

    private function signature(string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $this->secret());
    }

    public function gateway(string $payway, string $orderSN)
    {
        try {
            $this->loadGateWay($orderSN, $payway);
            if (strlen($this->secret()) < 32) {
                throw new RuleValidationException('NewZoe 支付密钥未配置');
            }

            $baseUrl = rtrim((string) env('NEWZOE_PAY_BASE_URL', 'https://pay.newzoe.cloud'), '/');
            $shopUrl = rtrim((string) config('app.url'), '/');
            $payload = json_encode([
                'amountFen' => (int) round(((float) $this->order->actual_price) * 100),
                'callbackUrl' => $shopUrl . '/pay/newzoe/notify_url',
                'orderId' => $this->order->order_sn,
                'returnUrl' => $shopUrl . '/detail-order-sn/' . $this->order->order_sn,
                'title' => $this->order->title,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $timestamp = (string) round(microtime(true) * 1000);

            $response = (new Client(['timeout' => 10]))->post($baseUrl . '/api/shop/orders', [
                'body' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Shop-Signature' => $this->signature($timestamp, $payload),
                    'X-Shop-Timestamp' => $timestamp,
                ],
            ]);
            $result = json_decode((string) $response->getBody(), true);
            if (empty($result['paymentUrl'])) {
                throw new RuleValidationException('支付订单登记失败');
            }
            return redirect()->away($result['paymentUrl']);
        } catch (RuleValidationException $exception) {
            return $this->err($exception->getMessage());
        } catch (\Exception $exception) {
            return $this->err('支付服务连接失败：' . $exception->getMessage());
        }
    }

    public function notifyUrl(Request $request)
    {
        $body = $request->getContent();
        $timestamp = (string) $request->header('X-Shop-Timestamp', '');
        $signature = (string) $request->header('X-Shop-Signature', '');
        if (!is_numeric($timestamp) || abs(round(microtime(true) * 1000) - (float) $timestamp) > 300000) {
            return response()->json(['error' => 'invalid_timestamp'], 401);
        }
        if (strlen($this->secret()) < 32 || !hash_equals($this->signature($timestamp, $body), $signature)) {
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $payload = json_decode($body, true);
        $orderSN = strtoupper((string) ($payload['orderId'] ?? ''));
        $amountFen = (int) ($payload['amountFen'] ?? 0);
        $order = $this->orderService->detailOrderSN($orderSN);
        if (!$order) {
            return response()->json(['error' => 'order_not_found'], 404);
        }
        if ($order->status > Order::STATUS_WAIT_PAY) {
            return response()->json(['accepted' => true, 'duplicate' => true]);
        }

        try {
            $this->orderProcessService->completedOrder(
                $orderSN,
                (float) bcdiv((string) $amountFen, '100', 2),
                (string) ($payload['transactionId'] ?? '')
            );
            return response()->json(['accepted' => true]);
        } catch (RuleValidationException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }
}
