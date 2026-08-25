<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NewzoeApiController extends Controller
{
    public function manualSuppress(Request $request)
    {
        $body = (string) $request->getContent();
        $secret = (string) env('NEWZOE_PAY_SECRET', '');
        $timestamp = (string) $request->header('X-Shop-Timestamp', '');
        $signature = (string) $request->header('X-Shop-Signature', '');
        if (!preg_match('/^[0-9]{10,16}$/', $timestamp)
            || abs((int) round(microtime(true) * 1000) - (int) $timestamp) > 300000
            || strlen($secret) < 32
            || !hash_equals(hash_hmac('sha256', $timestamp . '.' . $body, $secret), $signature)
        ) {
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $payload = json_decode($body, true);
        $orderSN = strtoupper(trim((string) (is_array($payload) ? ($payload['orderId'] ?? '') : '')));
        $actor = trim((string) (is_array($payload) ? ($payload['manualFulfilledBy'] ?? 'pay-admin') : 'pay-admin'));
        if (!is_array($payload)
            || ($payload['manualFulfilled'] ?? false) !== true
            || !preg_match('/^[A-Z0-9_-]{8,64}$/', $orderSN)
            || $actor === ''
            || strlen($actor) > 64
            || !preg_match('/^[A-Za-z0-9._:@-]+$/', $actor)
        ) {
            return response()->json(['error' => 'invalid_payload'], 422);
        }

        $result = DB::transaction(function () use ($orderSN, $actor) {
            $order = Order::query()
                ->with('pay')
                ->where('order_sn', $orderSN)
                ->lockForUpdate()
                ->first();
            if (!$order) {
                return ['status' => 404, 'body' => ['error' => 'order_not_found']];
            }

            $payCheck = strtolower(trim((string) optional($order->pay)->pay_check));
            $isAlipay = in_array($payCheck, ['aliweb', 'alipayscan', 'aliwap', 'zfbf2f', 'alipay'], true)
                || strpos($payCheck, 'alipay') !== false
                || strpos($payCheck, 'zfb') !== false;
            if (!$isAlipay) {
                return ['status' => 409, 'body' => ['error' => 'payment_method_conflict']];
            }

            if ($order->manual_fulfilled_at) {
                return [
                    'status' => 200,
                    'body' => [
                        'accepted' => true,
                        'duplicate' => true,
                        'orderId' => $orderSN,
                    ],
                ];
            }

            $order->manual_fulfilled_at = Carbon::now();
            $order->manual_fulfilled_by = $actor;
            $order->save();

            return [
                'status' => 200,
                'body' => [
                    'accepted' => true,
                    'duplicate' => false,
                    'orderId' => $orderSN,
                ],
            ];
        });

        return response()->json($result['body'], $result['status']);
    }

    public function orders(Request $request)
    {
        $secret = (string) env('NEWZOE_PAY_SECRET', '');
        $provided = (string) $request->header('X-Newzoe-Key', '');
        if (strlen($secret) < 32 || !hash_equals($secret, $provided)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $orders = Order::query()
            ->with('pay')
            ->orderByDesc('id')
            ->limit(1000)
            ->get()
            ->map(function (Order $order) {
                $gateway = $order->pay;
                $paymentMethod = strtolower(trim((string) optional($gateway)->pay_check));
                $createdAt = $order->created_at ? Carbon::parse($order->created_at) : Carbon::now();
                $expiresAt = $createdAt->copy()->addMinutes(max(1, (int) dujiaoka_config_get('order_expire_time', 20)));
                $paymentUrl = $gateway
                    ? url('pay-gateway', [
                        'handle' => urlencode((string) $gateway->pay_handleroute),
                        'payway' => $gateway->pay_check,
                        'orderSN' => $order->order_sn,
                    ])
                    : null;
                return [
                    'amountFen' => (int) round(((float) $order->actual_price) * 100),
                    'createdAt' => optional($order->created_at)->toIso8601String(),
                    'email' => $order->email,
                    'id' => $order->order_sn,
                    'expiresAt' => $expiresAt->toIso8601String(),
                    'callbackUrl' => url('/pay/newzoe/notify_url'),
                    'manualSuppressUrl' => url('/api/newzoe/manual-suppress'),
                    'matchExpiresAt' => $expiresAt->copy()->addMinutes(5)->toIso8601String(),
                    'paymentMethod' => $paymentMethod,
                    'paymentName' => optional($gateway)->pay_name,
                    'paymentUrl' => $paymentUrl,
                    'returnUrl' => url('detail-order-sn', ['orderSN' => $order->order_sn]),
                    'source' => 'dujiaoka',
                    'status' => $order->status,
                    'paymentReceived' => in_array((int) $order->status, [
                        Order::STATUS_PENDING,
                        Order::STATUS_PROCESSING,
                        Order::STATUS_COMPLETED,
                    ], true) || ((int) $order->status > Order::STATUS_WAIT_PAY
                        && (int) $order->status !== Order::STATUS_EXPIRED
                        && trim((string) $order->trade_no) !== ''),
                    'fulfilled' => (int) $order->status === Order::STATUS_COMPLETED,
                    'transactionId' => trim((string) $order->trade_no) !== ''
                        ? (string) $order->trade_no
                        : null,
                    'title' => $order->title,
                    'updatedAt' => optional($order->updated_at)->toIso8601String(),
                ];
            });

        return response()->json(['orders' => $orders]);
    }
}
