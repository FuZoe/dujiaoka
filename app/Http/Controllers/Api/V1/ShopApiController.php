<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\Controller;
use App\Models\BaseModel;
use App\Models\BinancePaySetting;
use App\Models\Carmis;
use App\Models\Customer;
use App\Models\Goods;
use App\Models\Order;
use App\Models\Pay;
use App\Service\GoodsService;
use App\Service\BinancePayQuoteService;
use App\Service\NewzoePaymentWindow;
use App\Service\OrderProcessService;
use App\Service\OrderService;
use App\Service\PayService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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
                $this->goodsService->applyAvailableStock($good);
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
            $telegramCustomer = null;
            DB::beginTransaction();
            try {
                if ($validated['telegram_chat_id'] !== '') {
                    $telegramCustomer = $this->resolveTelegramCustomer($validated);
                    // Telegram checkout does not expose an email or lookup
                    // password. Keep the legacy order columns populated with
                    // a customer-owned identity while the chat ID remains
                    // the authoritative lookup key for the bot.
                    $validated['email'] = $telegramCustomer->email;
                    if ($validated['search_password'] === '') {
                        $validated['search_password'] = Str::random(24);
                    }
                }
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
                $processor->setCustomerId($telegramCustomer ? (int) $telegramCustomer->getKey() : null);
                $order = $processor->createOrder();
                if ($telegramCustomer && Schema::hasColumn('orders', 'telegram_chat_id')) {
                    $order->telegram_chat_id = $validated['telegram_chat_id'];
                    $order->save();
                }
                DB::commit();
            } catch (\Throwable $exception) {
                DB::rollBack();
                throw $exception;
            }

            // The shop bot already renders the creation response in the same
            // chat. Avoid sending a duplicate "order created" notification;
            // paid/status events are still queued by the normal settlement
            // flow for bound Telegram customers.
            if ($telegramCustomer === null) {
                app(\App\Service\TelegramOrderNotificationService::class)->queueCreated($order);
            }
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

    /**
     * Return only orders owned by the Telegram customer represented by chat_id.
     * This endpoint is intentionally separate from the owner API order lookup;
     * a bot user must never be able to enumerate another customer's order.
     */
    public function telegramOrders(Request $request)
    {
        $customer = $this->telegramCustomerFromRequest($request);
        if ($customer === null) {
            return $this->success(['orders' => []]);
        }

        $orders = Order::query()
            ->with(['goods', 'pay'])
            ->where(function ($query) use ($customer) {
                if (!Schema::hasColumn('orders', 'telegram_chat_id')) {
                    $query->where('customer_id', $customer->getKey());
                    return;
                }

                $query->where(function ($owned) use ($customer) {
                    $owned->where(function ($legacy) use ($customer) {
                        $legacy->where('customer_id', $customer->getKey())
                            ->whereNull('telegram_chat_id');
                    })->orWhere('telegram_chat_id', $customer->telegram_chat_id);
                });
            })
            ->latest('created_at')
            ->limit(20)
            ->get()
            ->map(function (Order $order) {
                return $this->orderPayload($order);
            })
            ->values()
            ->all();

        return $this->success(['orders' => $orders]);
    }

    public function telegramOrder(Request $request, string $orderSN)
    {
        $order = $this->telegramOrderFromRequest($request, $orderSN);
        if ($order === null) {
            return $this->failure('order_not_found', 'The order does not exist.', 404);
        }

        return $this->success(['order' => $this->orderPayload($order)]);
    }

    public function telegramPay(Request $request, string $orderSN)
    {
        if ($this->telegramOrderFromRequest($request, $orderSN) === null) {
            return $this->failure('order_not_found', 'The order does not exist.', 404);
        }

        return $this->pay($request, $orderSN);
    }

    public function telegramDelivery(Request $request, string $orderSN)
    {
        $order = $this->telegramOrderFromRequest($request, $orderSN);
        if ($order === null) {
            return $this->failure('order_not_found', 'The order does not exist.', 404);
        }

        return $this->delivery($orderSN);
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
        // A scheduled pause blocks new selections, but an order that already
        // selected this gateway must keep its checkout URL until its own
        // payment deadline. Only allow the stored gateway as the fallback.
        if (!$gateway && $order->pay_id) {
            $storedGateway = $this->payService->detailForNotification((int) $order->pay_id);
            $storedCheck = strtolower((string) optional($storedGateway)->pay_check);
            $storedId = (string) (int) optional($storedGateway)->id;
            if ($paymentMethod === '' || $paymentMethod === $storedCheck || $paymentMethod === $storedId) {
                $gateway = $storedGateway;
            }
        }
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

        try {
            $payment = $this->paymentPayload($order, true);
        } catch (RuleValidationException $exception) {
            return $this->failure('payment_error', $exception->getMessage(), 409);
        } catch (\Throwable $exception) {
            report($exception);
            return $this->failure('payment_error', 'The payment channel could not be prepared.', 409);
        }

        return $this->success([
            'payment_required' => true,
            'payment' => $payment,
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

        $fulfillmentFailed = in_array((int) $settled->status, [
            Order::STATUS_ABNORMAL,
            Order::STATUS_FAILURE,
        ], true);
        $response = [
            'accepted' => true,
            'payment_received' => true,
            'order' => $this->orderPayload($settled),
            'delivery' => [
                'available' => !$fulfillmentFailed && (int) $settled->status === Order::STATUS_COMPLETED,
                'status' => $this->statusKey((int) $settled->status),
            ],
        ];
        if ($fulfillmentFailed) {
            $response['fulfillment'] = 'failed';
            return $this->success($response, 409);
        }

        return $this->success($response);
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

        $telegramChatId = trim((string) $request->input('telegram_chat_id', ''));
        if ($telegramChatId !== '' && !preg_match('/^[1-9][0-9]{0,31}$/', $telegramChatId)) {
            return $this->failure('validation_error', 'telegram_chat_id must be a private Telegram chat id.', 422);
        }

        $email = strtolower(trim((string) $request->input('email', '')));
        $validator = Validator::make([
            'product_id' => $productId,
            'quantity' => $quantity,
            'email' => $email,
            'payment_method' => $paymentMethod,
            'search_password' => $request->input('search_password', $request->input('search_pwd', '')),
            'telegram_chat_id' => $telegramChatId,
            'coupon_code' => $request->input('coupon_code', ''),
        ], [
            'product_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1'],
            'email' => $telegramChatId !== ''
                ? ['nullable', 'email', 'max:200']
                : ['required', 'email', 'max:200'],
            'payment_method' => ['required'],
            'search_password' => ['nullable', 'string', 'max:200'],
            'telegram_chat_id' => ['nullable', 'string', 'regex:/^[1-9][0-9]{0,31}$/'],
            'coupon_code' => ['nullable', 'string', 'max:100'],
        ]);
        if ($validator->fails()) {
            return $this->failure('validation_error', $validator->errors()->first(), 422);
        }

        return [
            'product_id' => (int) $productId,
            'quantity' => (int) $quantity,
            'email' => $email,
            'payment_method' => trim((string) $paymentMethod),
            'search_password' => trim((string) $request->input('search_password', $request->input('search_pwd', ''))),
            'telegram_chat_id' => $telegramChatId,
            'coupon_code' => (string) $request->input('coupon_code', ''),
            'inputs' => $inputs,
        ];
    }

    /**
     * Resolve or provision the customer represented by a Telegram chat ID.
     * The surrounding create-order transaction serialises this operation so
     * the unique telegram_chat_id/email constraints remain authoritative.
     */
    private function resolveTelegramCustomer(array $validated): Customer
    {
        $chatId = (string) $validated['telegram_chat_id'];
        $customer = Customer::query()
            ->where('telegram_chat_id', $chatId)
            ->lockForUpdate()
            ->first();
        if ($customer) {
            return $customer;
        }

        // A deterministic synthetic address lets a later web-account binding
        // migrate these orders without exposing any customer email address.
        // Only reuse it when it was previously provisioned by this app. A
        // visitor can otherwise pre-register the predictable address before
        // the first bot checkout and capture ownership of that chat's orders.
        $baseEmail = 'telegram-'.$chatId.'@'.Customer::TELEGRAM_SYNTHETIC_DOMAIN;
        $email = $baseEmail;
        $customer = Customer::query()
            ->where('email', $email)
            ->lockForUpdate()
            ->first();
        if ($customer && !$customer->isTelegramProvisionedFor($chatId)) {
            // Leave the untrusted row untouched and use a fresh internal
            // address. The reserved-domain registration guard protects new
            // rows; this branch also handles rows created before that guard.
            $customer = null;
            do {
                $email = 'telegram-'.$chatId.'-'.Str::lower(Str::random(20))
                    .'@'.Customer::TELEGRAM_SYNTHETIC_DOMAIN;
            } while (Customer::query()->where('email', $email)->exists());
        }
        if (!$customer) {
            $customer = Customer::query()->create([
                'email' => $email,
                'password' => Hash::make(Str::random(40)),
            ]);
        }

        $customer->telegram_chat_id = $chatId;
        $customer->telegram_bound_at = now();
        $customer->save();

        return $customer;
    }

    private function telegramCustomerFromRequest(Request $request): ?Customer
    {
        $chatId = trim((string) $request->query('chat_id', $request->input('chat_id', '')));
        if (!preg_match('/^[1-9][0-9]{0,31}$/', $chatId)) {
            return null;
        }

        return Customer::query()->where('telegram_chat_id', $chatId)->first();
    }

    private function telegramOrderFromRequest(Request $request, string $orderSN): ?Order
    {
        $customer = $this->telegramCustomerFromRequest($request);
        if (!$customer) {
            return null;
        }

        $order = $this->findOrder($orderSN);
        if (!$order) {
            return null;
        }

        if (!Schema::hasColumn('orders', 'telegram_chat_id')) {
            return (int) $order->customer_id === (int) $customer->getKey() ? $order : null;
        }

        $ownedByChat = (string) $order->telegram_chat_id !== ''
            && (string) $order->telegram_chat_id === (string) $customer->telegram_chat_id;
        $legacyOwned = $order->telegram_chat_id === null
            && (int) $order->customer_id === (int) $customer->getKey();
        if (!$ownedByChat && !$legacyOwned) {
            return null;
        }

        return $order;
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

    private function paymentPayload(Order $order, bool $includeBinanceDetails = false): array
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

        $payload = [
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

        // The Telegram shop needs the exact, collision-free USDT amount and
        // the same verified receiving link as the browser checkout. Quotes are
        // created only when /pay is called so merely creating an order does not
        // consume its Binance quote window.
        if ($includeBinanceDetails
            && $payable
            && strtolower((string) $gateway->pay_check) === 'binancepay'
        ) {
            $attempt = app(BinancePayQuoteService::class)->quote($order);
            $setting = BinancePaySetting::current();
            if (!$setting->hasOfficialReceiveUrl()) {
                throw new \RuntimeException('Binance Pay receiving QR is not configured.');
            }

            $payload['qr_payload'] = trim((string) $setting->receive_qr_payload);
            $payload['expected_usdt'] = (string) $attempt->expected_usdt;
            $payload['currency'] = (string) $attempt->currency;
            $payload['quote_expires_at'] = optional($attempt->expires_at)->toIso8601String();
        }

        return $payload;
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
