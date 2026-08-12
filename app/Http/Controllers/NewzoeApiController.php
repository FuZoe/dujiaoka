<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;

class NewzoeApiController extends Controller
{
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
                return [
                    'amountFen' => (int) round(((float) $order->actual_price) * 100),
                    'createdAt' => optional($order->created_at)->toIso8601String(),
                    'email' => $order->email,
                    'id' => $order->order_sn,
                    'paymentName' => optional($order->pay)->pay_name,
                    'source' => 'dujiaoka',
                    'status' => $order->status,
                    'title' => $order->title,
                    'updatedAt' => optional($order->updated_at)->toIso8601String(),
                ];
            });

        return response()->json(['orders' => $orders]);
    }
}
