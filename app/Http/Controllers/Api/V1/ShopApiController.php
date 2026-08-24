<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\Controller;
use App\Models\BaseModel;
use App\Models\Carmis;
use App\Models\Goods;
use App\Models\Order;
use App\Models\Pay;
use App\Service\GoodsService;
use App\Service\NewzoePaymentWindow;
use App\Service\OrderProcessService;
use App\Service\OrderService;
use App\Service\PayService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Owner-facing, versioned shop API.
 *
 * The controller deliberately delegates pricing, stock reservation, payment
 * settlement and fulfilment to the same services used by the web checkout.
 */
class ShopApiController extends Controller
{
    private $goodsService;
    private $orderService;
    private $payService;

    public function __construct()
    {
        $this->goodsService = app('Service\\GoodsService');
        $this->orderService = app('Service\\OrderService');
        $this->payService = app('Service\\PayService');
    }

    public function products()
    {
        $goods = Goods::query()
            ->withCount(['carmis' => function ($query) {
                $query->where('status', Carmis::STATUS_UNSOLD);
            }])
            ->where('is_open', Goods::STATUS_OPEN)
            ->whereHas('group', function ($query) {
                $query->where('is_open', BaseModel::STATUS_OPEN);
            })
            ->orderByDesc('ord')
            ->get()
            ->map(function (Goods $good) {
                return $this->productPayload($good);
            })
            ->values()
            ->all();

        return $this->success([
            'products' => $goods,
            'payment_methods' => $this->paymentMethodPayload(),
        ]);
    }

    public function paymentMethods()
    {
        return $this->success(['payment_methods' => $this->paymentMethodPayload()]);
    }

    public function createOrder(Request $request)
    {
        $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
        if (!preg_match('/^[A-Za-z0-9._~-]{8,128}$/', $idempotencyKey)) {
            return $this->failure(
                'idempotency_key_required',
                'Idempotency-Key must be 8 to 128 safe characters.',
                422
            );
        }

        $fingerprint = hash('sha256', $request->getContent());
        $scope = (string) $request->attributes->get('shop_api_key', 'owner');
        $cacheKey = 'shop-api:idempotency:'.sha1($scope.'|orders|'.$idempotencyKey);
        $stored = Cache::get($cacheKey);
        if (is_array($stored)) {
            if (!hash_equals((string) ($stored['fingerprint'] ?? ''), $fingerprint)) {
                return $this->failure(
                    'idempotency_conflict',
                    'The idempotency key was already used with a different request.',
                    409
                );
            }

            $order = $this->findOrder((string) ($stored['order_sn'] ?? ''));
            if ($order) {
                return $this->success([
                    'order' => $this->orderPayload($order),
                    'payment' => $this->paymentPayload($order),
                    'replayed' => true,
                ]);
            }
        }

        $lockKey = $cacheKey.':lock';
        if (!Cache::add($lockKey, $fingerprint, 60)) {
            return $this->failure(
                'idempotency_in_progress',
                'The same order request is already being processed.',
                409
            );
        }

        try {
            $validated = $this->validateCreatePayload($request);
            if ($validated instanceof \Illuminate\Http\JsonResponse) {
                return $validated;
            }

            $gateway = $this->resolveGateway($validated['payment_method']);
            if (!$gateway || !$this->payService->isAvailable($gateway)) {
                return $this->failure(
                    'payment_method_unavailable',
                    'The selected payment method is not available.',
                    409
                );
            }

            $inputs = $validated['inputs'];
            $internalData = [
                'gid' => $validated['product_id'],
                'email' => $validated['email'],
                'payway' => $gateway->id,
                'by_amount' => $validated['quantity'],
                'search_pwd' => $validated['search_password'],
                'coupon_code' => $validated['coupon_code'],
            ];
            foreach ($inputs as $field => $value) {
                $internalData[$field] = $value;
            }

            $internalRequest = Request::create(
                '/create-order',
                'POST',
                $internalData,
                [],
                [],
                ['REMOTE_ADDR' => $request->getClientIp() ?: '127.0.0.1']
            );

            DB::beginTransaction();
            try {
                $this->orderService->validatorCreateOrder($internalRequest, true);
                $this->orderService->validatorPayway($internalRequest);
                $goods = $this->orderService->validatorGoods($internalRequest);
                $this->orderService->validatorLoopCarmis($internalRequest);
                $coupon = $this->orderService->validatorCoupon($internalRequest);
                $otherInput = $this->orderService->validatorChargeInput($goods, $internalRequest);

                // A fresh processor keeps this stateful legacy service isolated
                // from the singleton used by browser requests.
                $processor = new OrderProcessService();
                $processor->setGoods($goods);
                $processor->setCoupon($coupon);
                $processor->setOtherIpt($otherInput);
                $processor->setBuyAmount($validated['quantity']);
                $processor->setPayID((int) $gateway->id);
                $processor->setEmail($validated['email']);
                $processor->setBuyIP($request->getClientIp() ?: '127.0.0.1');
                $processor->setSearchPwd($validated['search_password']);
                $processor->setCustomerId(null);
                $order = $processor->createOrder();
                DB::commit();
            } catch (\Throwable $exception) {
                DB::rollBack();
                throw $exception;
            }

            app(\App\Service\TelegramOrderNotificationService::class)->queueCreated($order);
            Cache::put($cacheKey, [
                'fingerprint' => $fingerprint,
                'order_sn' => $order->order_sn,
            ], max(60, (int) config('services.shop_api.idempotency_ttl', 86400)));

            return $this->success([
                'order' => $this->orderPayload($order),
                'payment' => $this->paymentPayload($order),
                'replayed' => false,
            ], 201);
        } catch (RuleValidationException $exception) {
            return $this->failure('validation_error', $exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            report($exception);
            return $this->failure('internal_error', 'The order could not be created.', 500);
        } finally {
            Cache::forget($lockKey);
        }
    }

    public function order(string $orderSN)
    {
        $order = $this->findOrder($orderSN);
        if (!$order) {
            return $this->failure('order_not_found', 'The order does not exist.', 404);
        }

        return $this->success(['order' => $this->orderPayload($order)]);
    }

    public function pay(Request $request, string $orderSN)
    {
        $order = $this->findOrder($orderSN);
        if (!$order) {
            return $this->failure('order_not_found', 'The order does not exist.', 404);
        }

        if ((int) $order->status === Order::STATUS_EXPIRED || $this->isPaymentExpired($order)) {
            return $this->failure('order_expired', 'The payment window for this order has ended.', 410);
        }

        if ((int) $order->status !== Order::STATUS_WAIT_PAY) {
            return $this->failure('order_already_paid', 'The order has already entered fulfilment.', 409);
        }

        $paymentMethod = trim((string) $request->input(
            'payment_method',
            optional($order->pay)->pay_check
        ));
        $gateway = $this->resolveGateway($paymentMethod);
        if (!$gateway || (!$this->payService->isAvailable($gateway)
                && (int) $gateway->id !== (int) $order->pay_id)) {
            return $this->failure(
                'payment_method_unavailable',
                'The selected payment method is not available.',
                409
            );
        }

        $order->pay_id = $gateway->id;
        $order->setRelation('pay', $gateway);
        $order->save();

        if (bccomp((string) $order->actual_price, '0.00', 2) === 0) {
            try {
                $processor = new OrderProcessService();
                $order = $processor->completedOrder($order->order_sn, 0.00, 'api-free-'.$order->order_sn);
            } catch (RuleValidationException $exception) {
                return $this->failure('payment_error', $exception->getMessage(), 409);
            }

            return $this->success([
                'payment_required' => false,
                'order' => $this->orderPayload($order),
            ]);
        }

        return $this->success([
            'payment_required' => true,
            'payment' => $this->paymentPayload($order),
            'order' => $this->orderPayload($order),
        ]);
    }

    public function delivery(string $orderSN)
    {
        $order = $this->findOrder($orderSN);
        if (!$order) {
            return $this->failure('order_not_found', 'The order does not exist.', 404);
        }

        if ((int) $order->status === Order::STATUS_WAIT_PAY) {
            return $this->success([
                'delivery' => [
                    'available' => false,
                    'status' => 'not_paid',
                    'items' => [],
                ],
            ]);
        }

        if ((int) $order->status === Order::STATUS_EXPIRED) {
            return $this->failure('order_expired', 'The order has expired.', 410);
        }

        if ((int) $order->status !== Order::STATUS_COMPLETED) {
            return $this->success([
                'delivery' => [
                    'available' => false,
                    'status' => $this->statusKey((int) $order->status),
                    'items' => [],
                ],
            ]);
        }

        $items = preg_split('/\r\n|\r|\n/', (string) $order->info);
        $items = array_values(array_filter($items, function ($item) {
            return $item !== '';
        }));

        return $this->success([
            'delivery' => [
                'available' => true,
                'status' => 'completed',
                'type' => (int) $order->type === Goods::AUTOMATIC_DELIVERY ? 'automatic' : 'manual',
                'items' => $items,
                'content' => (string) $order->info,
            ],
        ]);
    }

    /**
     * Settle an order from a trusted payment integration. The exact amount and
     * a non-empty provider transaction id are mandatory; completedOrder then
     * performs the normal inventory claim and notification flow.
     */
    public function deliver(Request $request, string $orderSN)
    {
        $order = $this->findOrder($orderSN);
        if (!$order) {
            return $this->failure('order_not_found', 'The order does not exist.', 404);
        }

        $amount = $this->normaliseAmount($request->input('amount'), $request->input('amount_fen'));
        $transactionId = trim((string) $request->input('transaction_id', ''));
        if ($amount === null || $transactionId === '' || strlen($transactionId) > 200) {
            return $this->failure(
                'validation_error',
                'amount (or amount_fen) and transaction_id are required.',
                422
            );
        }

        if ((int) $order->status === Order::STATUS_EXPIRED) {
            return $this->failure('order_expired', 'The order has expired.', 410);
        }

        if (bccomp((string) $order->actual_price, $amount, 2) !== 0) {
            return $this->failure('amount_mismatch', 'The settled amount does not match the order.', 422);
        }

        try {
            $processor = new OrderProcessService();
            $settled = $processor->completedOrder($order->order_sn, (float) $amount, $transactionId);
        } catch (RuleValidationException $exception) {
            return $this->failure('settlement_rejected', $exception->getMessage(), 409);
        } catch (\Throwable $exception) {
            report($exception);
            return $this->failure('internal_error', 'The order could not be settled.', 500);
        }

        return $this->success([
            'accepted' => true,
            'order' => $this->orderPayload($settled),
            'delivery' => [
                'available' => (int) $settled->status === Order::STATUS_COMPLETED,
                'status' => $this->statusKey((int) $settled->status),
            ],
        ]);
    }

    private function validateCreatePayload(Request $request)
    {
        $productId = $request->input('product_id', $request->input('gid'));
        $quantity = $request->input('quantity', $request->input('by_amount'));
        $paymentMethod = $request->input('payment_method', $request->input('payway'));
        $inputs = $request->input('inputs', []);

        if ($inputs === null) {
            $inputs = [];
        }
        if (!is_array($inputs)) {
            return $this->failure('validation_error', 'inputs must be an object.', 422);
        }
        if (!is_scalar($paymentMethod)) {
            return $this->failure('validation_error', 'payment_method must be a code or numeric id.', 422);
        }
        foreach ($inputs as $field => $value) {
            if (!is_string($field) || !preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,99}$/', $field)
                || !is_scalar($value)
            ) {
                return $this->failure('validation_error', 'inputs contains an invalid field.', 422);
            }
            $inputs[$field] = (string) $value;
        }

        $validator = Validator::make([
            'product_id' => $productId,
            'quantity' => $quantity,
            'email' => strtolower(trim((string) $request->input('email', ''))),
            'payment_method' => $paymentMethod,
            'search_password' => $request->input('search_password', $request->input('search_pwd', '')),
            'coupon_code' => $request->input('coupon_code', ''),
        ], [
            'product_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1'],
            'email' => ['required', 'email', 'max:200'],
            'payment_method' => ['required'],
            'search_password' => ['nullable', 'string', 'max:200'],
            'coupon_code' => ['nullable', 'string', 'max:100'],
        ]);
        if ($validator->fails()) {
            return $this->failure('validation_error', $validator->errors()->first(), 422);
        }

        return [
            'product_id' => (int) $productId,
            'quantity' => (int) $quantity,
            'email' => strtolower(trim((string) $request->input('email'))),
            'payment_method' => trim((string) $paymentMethod),
            'search_password' => (string) $request->input('search_password', $request->input('search_pwd', '')),
            'coupon_code' => (string) $request->input('coupon_code', ''),
            'inputs' => $inputs,
        ];
    }

    private function resolveGateway($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^[0-9]+$/', $value)) {
            return $this->payService->detail((int) $value);
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,50}$/', $value)) {
            return null;
        }

        return $this->payService->detailByCheck(strtolower($value));
    }

    private function paymentMethodPayload(): array
    {
        $methods = $this->payService->pays(Pay::PAY_CLIENT_PC) ?: [];
        return array_values(array_map(function (array $method) {
            return [
                'id' => (int) $method['id'],
                'code' => (string) $method['pay_check'],
                'name' => (string) $method['pay_name'],
                'method' => (int) $method['pay_method'],
                'client' => (int) $method['pay_client'],
            ];
        }, $methods));
    }

    private function productPayload(Goods $goods): array
    {
        $wholesale = [];
        if (is_string($goods->wholesale_price_cnf) && $goods->wholesale_price_cnf !== '') {
            $wholesale = format_wholesale_price($goods->wholesale_price_cnf) ?: [];
        }

        $inputFields = [];
        if (is_string($goods->other_ipu_cnf) && $goods->other_ipu_cnf !== '') {
            $inputFields = format_charge_input($goods->other_ipu_cnf) ?: [];
        }

        return [
            'id' => (int) $goods->id,
            'name' => (string) $goods->gd_name,
            'description' => (string) $goods->gd_description,
            'price' => number_format((float) $goods->actual_price, 2, '.', ''),
            'stock' => (int) $goods->in_stock,
            'type' => (int) $goods->type === Goods::AUTOMATIC_DELIVERY ? 'automatic' : 'manual',
            'max_quantity' => (int) $goods->buy_limit_num > 0 ? (int) $goods->buy_limit_num : null,
            'wholesale_prices' => array_values(array_map(function (array $item) {
                return [
                    'quantity' => (int) $item['number'],
                    'unit_price' => number_format((float) $item['price'], 2, '.', ''),
                ];
            }, $wholesale)),
            'input_fields' => array_values(array_map(function (array $item) {
                return [
                    'field' => (string) $item['field'],
                    'label' => (string) $item['desc'],
                    'required' => (bool) $item['rule'],
                    'placeholder' => (string) $item['placeholder'],
                ];
            }, $inputFields)),
        ];
    }

    private function paymentPayload(Order $order): array
    {
        $gateway = $order->pay ?: ($order->pay_id ? $this->payService->detailForNotification((int) $order->pay_id) : null);
        $payable = (int) $order->status === Order::STATUS_WAIT_PAY && !$this->isPaymentExpired($order);
        if (!$gateway) {
            return [
                'required' => $payable && bccomp((string) $order->actual_price, '0.00', 2) !== 0,
                'url' => null,
                'method' => null,
                'expires_at' => $this->expiresAt($order)->toIso8601String(),
            ];
        }

        return [
            'required' => $payable && bccomp((string) $order->actual_price, '0.00', 2) !== 0,
            'url' => $payable ? url('pay-gateway', [
                'handle' => urlencode((string) $gateway->pay_handleroute),
                'payway' => $gateway->pay_check,
                'orderSN' => $order->order_sn,
            ]) : null,
            'method' => (string) $gateway->pay_check,
            'name' => (string) $gateway->pay_name,
            'expires_at' => $this->expiresAt($order)->toIso8601String(),
        ];
    }

    private function orderPayload(Order $order): array
    {
        $gateway = $order->pay;
        $expiresAt = $this->expiresAt($order);
        $status = (int) $order->status;

        return [
            'id' => (string) $order->order_sn,
            'status' => $this->statusKey($status),
            'status_code' => $status,
            'payment_received' => in_array($status, [
                Order::STATUS_PENDING,
                Order::STATUS_PROCESSING,
                Order::STATUS_COMPLETED,
            ], true) || ($status > Order::STATUS_WAIT_PAY
                && $status !== Order::STATUS_EXPIRED
                && trim((string) $order->trade_no) !== ''),
            'fulfilled' => $status === Order::STATUS_COMPLETED,
            'product' => [
                'id' => (int) $order->goods_id,
                'name' => (string) optional($order->goods)->gd_name,
            ],
            'quantity' => (int) $order->buy_amount,
            'amount' => number_format((float) $order->actual_price, 2, '.', ''),
            'currency' => 'CNY',
            'payment_method' => optional($gateway)->pay_check,
            'payment_name' => optional($gateway)->pay_name,
            'transaction_id' => $order->trade_no !== '' ? (string) $order->trade_no : null,
            'created_at' => optional($order->created_at)->toIso8601String(),
            'updated_at' => optional($order->updated_at)->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    private function findOrder(string $orderSN): ?Order
    {
        $orderSN = strtoupper(trim($orderSN));
        if ($orderSN === '' || !preg_match('/^[A-Z0-9]{1,150}$/', $orderSN)) {
            return null;
        }

        return $this->orderService->detailOrderSN($orderSN);
    }

    private function expiresAt(Order $order): Carbon
    {
        if (optional($order->pay)->pay_check === 'newzoe-wechat') {
            return app(NewzoePaymentWindow::class)->paymentExpiresAt($order);
        }

        return Carbon::parse($order->created_at ?: Carbon::now())
            ->addMinutes(max(1, (int) dujiaoka_config_get('order_expire_time', 20)));
    }

    private function isPaymentExpired(Order $order): bool
    {
        return (int) $order->status === Order::STATUS_WAIT_PAY && Carbon::now()->gte($this->expiresAt($order));
    }

    private function statusKey(int $status): string
    {
        return [
            Order::STATUS_WAIT_PAY => 'wait_pay',
            Order::STATUS_PENDING => 'pending',
            Order::STATUS_PROCESSING => 'processing',
            Order::STATUS_COMPLETED => 'completed',
            Order::STATUS_FAILURE => 'failure',
            Order::STATUS_EXPIRED => 'expired',
            Order::STATUS_ABNORMAL => 'abnormal',
        ][$status] ?? 'unknown';
    }

    private function normaliseAmount($amount, $amountFen): ?string
    {
        if ($amountFen !== null && $amountFen !== '') {
            if (!is_scalar($amountFen)) {
                return null;
            }
            $amountFen = (string) $amountFen;
            if (!preg_match('/^(?:0|[1-9][0-9]*)$/', $amountFen)) {
                return null;
            }
            return bcdiv($amountFen, '100', 2);
        }

        if (!is_scalar($amount)) {
            return null;
        }
        $amount = trim((string) $amount);
        if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/', $amount)) {
            return null;
        }

        $parts = explode('.', $amount, 2);
        $whole = $parts[0];
        $decimals = isset($parts[1]) ? str_pad($parts[1], 2, '0') : '00';

        return $whole.'.'.substr($decimals, 0, 2);
    }

    private function success(array $data, int $status = 200)
    {
        return response()->json(['ok' => true, 'data' => $data], $status);
    }

    private function failure(string $code, string $message, int $status, array $details = [])
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details) {
            $error['details'] = $details;
        }

        return response()->json(['ok' => false, 'error' => $error], $status);
    }
}
