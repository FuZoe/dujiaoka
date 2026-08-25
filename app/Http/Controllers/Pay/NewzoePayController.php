<?php

namespace App\Http\Controllers\Pay;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\PayController;
use App\Models\Order;
use App\Service\NewzoePaymentWindow;
use Carbon\Carbon;
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
            $paymentWindow = app(NewzoePaymentWindow::class);
            $expiresAt = $paymentWindow->paymentExpiresAt($this->order);
            if (!$paymentWindow->paymentIsOpen($this->order)) {
                throw new RuleValidationException(__('dujiaoka.prompt.order_is_expired'));
            }
            $matchExpiresAt = $paymentWindow->responseExpiresAt($this->order);

            $baseUrl = rtrim((string) env('NEWZOE_PAY_BASE_URL', 'https://pay.newzoe.cloud'), '/');
            $shopUrl = rtrim((string) config('app.url'), '/');
            $payload = json_encode([
                'amountFen' => (int) round(((float) $this->order->actual_price) * 100),
                'callbackUrl' => $shopUrl . '/pay/newzoe/notify_url',
                'expiresAt' => $expiresAt->toIso8601String(),
                'matchExpiresAt' => $matchExpiresAt->toIso8601String(),
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
        if (!is_array($payload)) {
            return response()->json(['error' => 'invalid_payload'], 400);
        }
        $orderSN = strtoupper((string) ($payload['orderId'] ?? ''));
        $rawAmountFen = $payload['amountFen'] ?? null;
        $transactionId = (string) ($payload['transactionId'] ?? '');
        $manualOverride = ($payload['manualOverride'] ?? false) === true;
        $fulfillmentRetry = ($payload['fulfillmentRetry'] ?? false) === true;
        $order = $this->orderService->detailOrderSN($orderSN);
        if (!$order) {
            return response()->json(['error' => 'order_not_found'], 404);
        }
        if ((!is_int($rawAmountFen) && !is_string($rawAmountFen))
            || !preg_match('/^[1-9][0-9]*$/', (string) $rawAmountFen)) {
            return response()->json(['error' => 'invalid_amount'], 422);
        }
        $amountFen = (int) $rawAmountFen;
        $callbackAmount = bcdiv((string) $amountFen, '100', 2);
        if ($transactionId === '') {
            return response()->json(['error' => 'transaction_id_required'], 422);
        }
        if (in_array((int) $order->status, [
            Order::STATUS_PENDING,
            Order::STATUS_PROCESSING,
            Order::STATUS_COMPLETED,
        ], true)) {
            if ((string) $order->trade_no === ''
                || !hash_equals((string) $order->trade_no, $transactionId)) {
                return response()->json(['error' => 'transaction_id_conflict'], 409);
            }
            if (bccomp((string) $order->actual_price, $callbackAmount, 2) !== 0) {
                return response()->json(['error' => 'amount_conflict'], 409);
            }

            return response()->json(['accepted' => true, 'duplicate' => true]);
        }
        if (in_array((int) $order->status, [
            Order::STATUS_ABNORMAL,
            Order::STATUS_FAILURE,
        ], true) && !$fulfillmentRetry) {
            if ((string) $order->trade_no !== ''
                && hash_equals((string) $order->trade_no, $transactionId)
                && bccomp((string) $order->actual_price, $callbackAmount, 2) === 0) {
                return response()->json([
                    'accepted' => true,
                    'paid' => true,
                    'fulfillment' => 'failed',
                    'duplicate' => true,
                ], 409);
            }
            return response()->json(['error' => 'transaction_id_conflict'], 409);
        }
        $paymentWindow = app(NewzoePaymentWindow::class);
        $matchedAt = null;
        try {
            $matchedAt = isset($payload['matchedAt']) ? Carbon::parse($payload['matchedAt']) : null;
        } catch (\Throwable $exception) {
            $matchedAt = null;
        }
        $settledWithinResponseWindow = $matchedAt
            && $matchedAt->gte(Carbon::parse($order->created_at))
            && $matchedAt->lt($paymentWindow->responseExpiresAt($order));
        $manualCompletion = $manualOverride && in_array((int) $order->status, [
            Order::STATUS_WAIT_PAY,
            Order::STATUS_EXPIRED,
        ], true);
        $allowExpiredCompletion = $manualCompletion || $settledWithinResponseWindow;
        if ($fulfillmentRetry && in_array((int) $order->status, [
            Order::STATUS_ABNORMAL,
            Order::STATUS_FAILURE,
        ], true)) {
            $allowExpiredCompletion = true;
        }
        if (!$allowExpiredCompletion && (
            (int) $order->status !== Order::STATUS_WAIT_PAY
            || !$paymentWindow->responseIsOpen($order)
        )) {
            return response()->json(['error' => 'order_expired'], 410);
        }

        try {
            if ($fulfillmentRetry) {
                $completedOrder = $this->orderProcessService->completedOrder(
                    $orderSN,
                    (float) $callbackAmount,
                    $transactionId,
                    $allowExpiredCompletion,
                    true
                );
            } else {
                $completedOrder = $this->orderProcessService->completedOrder(
                    $orderSN,
                    (float) $callbackAmount,
                    $transactionId,
                    $allowExpiredCompletion
                );
            }
            if (in_array((int) $completedOrder->status, [
                Order::STATUS_ABNORMAL,
                Order::STATUS_FAILURE,
            ], true)) {
                return response()->json([
                    'accepted' => true,
                    'paid' => true,
                    'fulfillment' => 'failed',
                    'orderStatus' => (int) $completedOrder->status,
                ], 409);
            }
            return response()->json(['accepted' => true]);
        } catch (RuleValidationException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
    }
}
