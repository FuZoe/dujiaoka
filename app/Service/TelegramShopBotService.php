<?php

namespace App\Service;

use App\Service\ShopApiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Throwable;

class TelegramShopBotService
{
    private const SESSION_MINUTES = 30;
    private const ORDER_MINUTES = 2880;
    private const LANGUAGE_TTL_DAYS = 30;
    private const DEFAULT_LANGUAGE = 'zh';

    private $api;
    private $telegram;

    public function __construct(ShopApiClient $api, TelegramBotClient $telegram)
    {
        $this->api = $api;
        $this->telegram = $telegram;
    }

    public function handleUpdate(array $update): void
    {
        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
            return;
        }

        if (isset($update['message']) && is_array($update['message'])) {
            $this->handleMessage($update['message']);
        }
    }

    public function handleMessage(array $message): void
    {
        $chatId = $this->privateChatId($message['chat'] ?? []);
        if ($chatId === null) {
            return;
        }

        $this->language($chatId, $message['from']['language_code'] ?? null);

        $text = trim((string) ($message['text'] ?? ''));
        if ($text === '') {
            return;
        }

        if (preg_match('/^\/(?:start|shop|menu)(?:@[A-Za-z0-9_]+)?$/i', $text)) {
            $this->showHome($chatId);
            return;
        }
        if (preg_match('/^\/(?:products|buy)(?:@[A-Za-z0-9_]+)?$/i', $text)) {
            $this->showProducts($chatId, 0);
            return;
        }
        if (preg_match('/^\/orders(?:@[A-Za-z0-9_]+)?$/i', $text)) {
            $this->showOrders($chatId);
            return;
        }
        if (preg_match('/^\/cancel(?:@[A-Za-z0-9_]+)?$/i', $text)) {
            Cache::forget($this->sessionKey($chatId));
            $this->send($chatId, $this->t($chatId, 'cancelled'), $this->homeKeyboard($chatId));
            return;
        }

        $state = $this->getState($chatId);
        if (!$state) {
            $this->send($chatId, $this->t($chatId, 'choose_action'), $this->homeKeyboard($chatId));
            return;
        }

        try {
            switch ($state['step'] ?? '') {
                case 'email':
                    $this->acceptEmail($chatId, $text, $state);
                    return;
                case 'password':
                    $this->acceptPassword($chatId, $text, $state);
                    return;
                case 'input':
                    $this->acceptInput($chatId, $text, $state);
                    return;
                default:
                    $this->send($chatId, $this->t($chatId, 'continue_hint'), $this->homeKeyboard($chatId));
            }
        } catch (Throwable $exception) {
            $this->reportApiFailure($exception);
            $this->send($chatId, $this->t($chatId, 'generic_error'), $this->homeKeyboard($chatId));
        }
    }

    public function handleCallback(array $query): void
    {
        $chatId = $this->privateChatId(
            $query['message']['chat'] ?? []
        );
        if ($chatId === null) {
            return;
        }

        $this->language($chatId, $query['from']['language_code'] ?? null);

        $data = trim((string) ($query['data'] ?? ''));
        $origin = $query['message'] ?? [];
        if ($data === '') {
            return;
        }

        try {
            $parts = explode(':', $data);
            if (($parts[0] ?? '') !== 'shop') {
                return;
            }

            switch ($parts[1] ?? '') {
                case 'home':
                    $this->respond($chatId, $this->t($chatId, 'choose_action'), $this->homeKeyboard($chatId), $origin);
                    return;
                case 'help':
                    $this->respond($chatId, $this->helpText($chatId), $this->homeKeyboard($chatId), $origin);
                    return;
                case 'languages':
                    $this->showLanguages($chatId, $origin);
                    return;
                case 'lang':
                    $this->selectLanguage($chatId, (string) ($parts[2] ?? ''), $origin);
                    return;
                case 'products':
                    $this->showProducts($chatId, max(0, (int) ($parts[2] ?? 0)), $origin);
                    return;
                case 'product':
                    $this->showProduct($chatId, (int) ($parts[2] ?? 0), $origin);
                    return;
                case 'qty':
                    $this->chooseQuantity(
                        $chatId,
                        (int) ($parts[2] ?? 0),
                        (int) ($parts[3] ?? 0),
                        $origin
                    );
                    return;
                case 'pwd':
                    $this->choosePassword($chatId, (string) ($parts[2] ?? ''), $origin);
                    return;
                case 'method':
                    $this->choosePaymentMethod($chatId, (string) ($parts[2] ?? ''), $origin);
                    return;
                case 'orders':
                    $this->showOrders($chatId, $origin);
                    return;
                case 'order':
                    $this->showOrder($chatId, (string) ($parts[2] ?? ''), $origin);
                    return;
                case 'delivery':
                    $this->showDelivery($chatId, (string) ($parts[2] ?? ''), $origin);
                    return;
                case 'cancel':
                    Cache::forget($this->sessionKey($chatId));
                    $this->respond($chatId, $this->t($chatId, 'cancelled'), $this->homeKeyboard($chatId), $origin);
                    return;
            }
        } catch (Throwable $exception) {
            $this->reportApiFailure($exception);
            $this->respond($chatId, $this->t($chatId, 'generic_error'), $this->homeKeyboard($chatId), $origin);
        }
    }

    private function showHome(string $chatId): void
    {
        $this->send(
            $chatId,
            $this->t($chatId, 'home_text'),
            $this->homeKeyboard($chatId)
        );
    }

    private function showProducts(string $chatId, int $page, array $origin = []): void
    {
        $data = $this->api->products();
        $products = array_values($data['products'] ?? []);
        $pageSize = 6;
        $pages = max(1, (int) ceil(count($products) / $pageSize));
        $page = min($page, $pages - 1);
        $slice = array_slice($products, $page * $pageSize, $pageSize);

        $text = $this->t($chatId, 'products_title');
        if (!$slice) {
            $text .= $this->t($chatId, 'no_products');
        } else {
            $text .= $this->t($chatId, 'products_hint');
        }

        $keyboard = [];
        foreach ($slice as $product) {
            $stock = (int) ($product['stock'] ?? 0);
            $label = '🛒 '.$this->shortText((string) ($product['name'] ?? $this->t($chatId, 'product_default')), 28)
                .' · ¥'.number_format((float) ($product['price'] ?? 0), 2);
            if ($stock < 1) {
                $label .= ' · '.$this->t($chatId, 'out_of_stock');
            }
            $keyboard[] = [[
                'text' => $label,
                'callback_data' => 'shop:product:'.(int) ($product['id'] ?? 0),
            ]];
        }

        $pager = [];
        if ($page > 0) {
            $pager[] = ['text' => $this->t($chatId, 'previous'), 'callback_data' => 'shop:products:'.($page - 1)];
        }
        if ($page < $pages - 1) {
            $pager[] = ['text' => $this->t($chatId, 'next'), 'callback_data' => 'shop:products:'.($page + 1)];
        }
        if ($pager) {
            $keyboard[] = $pager;
        }
        $keyboard[] = [
            ['text' => $this->t($chatId, 'my_orders'), 'callback_data' => 'shop:orders'],
            ['text' => $this->t($chatId, 'home'), 'callback_data' => 'shop:home'],
        ];

        $this->respond($chatId, $text, ['inline_keyboard' => $keyboard], $origin);
    }

    private function showProduct(string $chatId, int $productId, array $origin = []): void
    {
        $product = $this->product($productId);
        if (!$product) {
            $this->respond($chatId, $this->t($chatId, 'product_missing'), $this->homeKeyboard($chatId), $origin);
            return;
        }

        $stock = (int) ($product['stock'] ?? 0);
        $text = (string) ($product['name'] ?? $this->t($chatId, 'product_default'))."\n\n"
            .$this->t($chatId, 'price', ['amount' => number_format((float) ($product['price'] ?? 0), 2)])."\n"
            .$this->t($chatId, 'stock', ['stock' => $stock > 0 ? (string) $stock : $this->t($chatId, 'out_of_stock')])."\n";
        if (!empty($product['description'])) {
            $description = trim(strip_tags((string) $product['description']));
            $text .= "\n".$this->shortText($description, 2600)."\n";
        }
        if (!empty($product['input_fields'])) {
            $text .= "\n".$this->t($chatId, 'fields_intro');
            foreach ($product['input_fields'] as $field) {
                $text .= "\n· ".(string) ($field['label'] ?? $field['field'] ?? $this->t($chatId, 'field_default'));
            }
            $text .= "\n";
        }

        $keyboard = [];
        if ($stock > 0) {
            $limit = (int) ($product['max_quantity'] ?? 0);
            $limit = $limit > 0 ? min($limit, $stock) : $stock;
            // Offer every small quantity so low-stock products remain fully usable;
            // larger inventories still get the common quick-pick values.
            $quantities = $limit <= 10 ? range(1, $limit) : [1, 2, 5, 10];
            foreach ($quantities as $quantity) {
                $keyboard[] = [[
                    'text' => $this->t($chatId, 'buy_quantity', ['quantity' => $quantity]),
                    'callback_data' => 'shop:qty:'.$productId.':'.$quantity,
                ]];
            }
        }
        $keyboard[] = [
            ['text' => $this->t($chatId, 'back_products'), 'callback_data' => 'shop:products:0'],
            ['text' => $this->t($chatId, 'home'), 'callback_data' => 'shop:home'],
        ];
        $this->respond($chatId, $text, ['inline_keyboard' => $keyboard], $origin);
    }

    private function chooseQuantity(
        string $chatId,
        int $productId,
        int $quantity,
        array $origin = []
    ): void {
        $product = $this->product($productId);
        if (!$product) {
            $this->respond($chatId, $this->t($chatId, 'product_missing_retry'), $this->homeKeyboard($chatId), $origin);
            return;
        }
        $stock = (int) ($product['stock'] ?? 0);
        $limit = (int) ($product['max_quantity'] ?? 0);
        $limit = $limit > 0 ? min($limit, $stock) : $stock;
        if ($quantity < 1 || $quantity > $limit) {
            $this->respond($chatId, $this->t($chatId, 'quantity_changed'), $this->homeKeyboard($chatId), $origin);
            return;
        }

        $fields = array_values($product['input_fields'] ?? []);
        $state = [
            'step' => $fields ? 'input' : 'payment',
            'product' => $product,
            'quantity' => $quantity,
            'inputs' => [],
            'input_index' => 0,
        ];
        $this->putState($chatId, $state);
        if ($fields) {
            $this->promptInput($chatId, $state, $origin);
            return;
        }
        $this->showPaymentMethods($chatId, $origin);
    }

    private function acceptEmail(string $chatId, string $text, array $state): void
    {
        $email = strtolower(trim($text));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 200) {
            $this->send($chatId, $this->t($chatId, 'email_invalid'), $this->cancelKeyboard($chatId));
            return;
        }
        $state['email'] = $email;
        $state['step'] = 'password';
        $this->putState($chatId, $state);
        $this->send(
            $chatId,
            $this->t($chatId, 'email_recorded'),
            $this->passwordKeyboard($chatId)
        );
    }

    private function choosePassword(string $chatId, string $choice, array $origin = []): void
    {
        $state = $this->getState($chatId);
        if (!$state || ($state['step'] ?? '') !== 'password') {
            $this->respond($chatId, $this->t($chatId, 'password_wait_missing'), $this->homeKeyboard($chatId), $origin);
            return;
        }
        $state['search_password'] = $choice === 'auto' ? Str::random(12) : '';
        $this->afterPassword($chatId, $state, $origin);
    }

    private function acceptPassword(string $chatId, string $text, array $state): void
    {
        $password = trim($text);
        if ($password === '-') {
            $password = Str::random(12);
        }
        if (strlen($password) > 200) {
            $this->send($chatId, $this->t($chatId, 'password_too_long'), $this->passwordKeyboard($chatId));
            return;
        }
        $state['search_password'] = $password;
        $this->afterPassword($chatId, $state);
    }

    private function afterPassword(string $chatId, array $state, array $origin = []): void
    {
        $fields = array_values($state['product']['input_fields'] ?? []);
        if ($fields) {
            $state['step'] = 'input';
            $state['input_index'] = 0;
            $this->putState($chatId, $state);
            $this->promptInput($chatId, $state, $origin);
            return;
        }
        $state['step'] = 'payment';
        $this->putState($chatId, $state);
        $this->showPaymentMethods($chatId, $origin);
    }

    private function promptInput(string $chatId, array $state, array $origin = []): void
    {
        $fields = array_values($state['product']['input_fields'] ?? []);
        $index = (int) ($state['input_index'] ?? 0);
        $field = $fields[$index] ?? null;
        if (!$field) {
            $state['step'] = 'payment';
            $this->putState($chatId, $state);
            $this->showPaymentMethods($chatId, $origin);
            return;
        }

        $label = (string) ($field['label'] ?? $field['field'] ?? $this->t($chatId, 'field_default'));
        $required = !empty($field['required']);
        $text = $this->t($chatId, $required ? 'input_required' : 'input_optional', ['label' => $label]);
        $this->respond($chatId, $text, $this->cancelKeyboard($chatId), $origin);
    }

    private function acceptInput(string $chatId, string $text, array $state): void
    {
        $fields = array_values($state['product']['input_fields'] ?? []);
        $index = (int) ($state['input_index'] ?? 0);
        $field = $fields[$index] ?? null;
        if (!$field) {
            $state['step'] = 'payment';
            $this->putState($chatId, $state);
            $this->showPaymentMethods($chatId);
            return;
        }

        $value = trim($text);
        if ($value === '-' && !empty($field['required'])) {
            $this->send($chatId, $this->t($chatId, 'input_required_error'), $this->cancelKeyboard($chatId));
            return;
        }
        $state['inputs'][(string) $field['field']] = $value === '-' ? '' : $value;
        $state['input_index'] = $index + 1;
        $this->putState($chatId, $state);
        $this->promptInput($chatId, $state);
    }

    private function showPaymentMethods(string $chatId, array $origin = []): void
    {
        $state = $this->getState($chatId);
        if (!$state || ($state['step'] ?? '') !== 'payment') {
            $this->respond($chatId, $this->t($chatId, 'payment_wait_missing'), $this->homeKeyboard($chatId), $origin);
            return;
        }

        $data = $this->api->paymentMethods();
        $methods = array_values($data['payment_methods'] ?? []);
        if (!$methods) {
            $this->respond($chatId, $this->t($chatId, 'no_payment_methods'), $this->homeKeyboard($chatId), $origin);
            return;
        }

        $text = $this->t($chatId, 'selected_order', [
            'product' => (string) ($state['product']['name'] ?? $this->t($chatId, 'product_default')),
            'quantity' => (int) ($state['quantity'] ?? 1),
        ]);
        $keyboard = [];
        foreach ($methods as $method) {
            $code = (string) ($method['code'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_-]{1,50}$/', $code)) {
                continue;
            }
            $keyboard[] = [[
                'text' => '💳 '.$this->paymentMethodLabel(
                    $chatId,
                    $code,
                    (string) ($method['name'] ?? $code)
                ),
                'callback_data' => 'shop:method:'.$code,
            ]];
        }
        if (!$keyboard) {
            $this->respond($chatId, $this->t($chatId, 'no_payment_methods'), $this->homeKeyboard($chatId), $origin);
            return;
        }
        $keyboard[] = [['text' => $this->t($chatId, 'cancel_order'), 'callback_data' => 'shop:cancel']];
        $this->respond($chatId, $text, ['inline_keyboard' => $keyboard], $origin);
    }

    private function choosePaymentMethod(string $chatId, string $method, array $origin = []): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,50}$/', $method)) {
            return;
        }
        $state = $this->getState($chatId);
        if (!$state || ($state['step'] ?? '') !== 'payment') {
            $this->respond($chatId, $this->t($chatId, 'session_expired'), $this->homeKeyboard($chatId), $origin);
            return;
        }

        $payload = [
            'product_id' => (int) $state['product']['id'],
            'quantity' => (int) $state['quantity'],
            'payment_method' => $method,
            'telegram_chat_id' => $chatId,
            'inputs' => (array) ($state['inputs'] ?? []),
        ];
        // Keep accepting an in-flight legacy session created before the
        // Telegram checkout changed to chat ownership.
        if (!empty($state['email'])) {
            $payload['email'] = (string) $state['email'];
        }
        if (!empty($state['search_password'])) {
            $payload['search_password'] = (string) $state['search_password'];
        }
        // Persist the key before the network call. Telegram can deliver the same
        // callback more than once, and a retry must replay the original order.
        $idempotencyKey = (string) ($state['idempotency_key'] ?? '');
        $idempotencyMethod = (string) ($state['idempotency_method'] ?? '');
        if ($idempotencyKey === '' || $idempotencyMethod !== $method) {
            $idempotencyKey = 'tg-'.$chatId.'-'.Str::random(20);
            $state['idempotency_key'] = $idempotencyKey;
            $state['idempotency_method'] = $method;
            $this->putState($chatId, $state);
        }
        $result = $this->api->createOrder($payload, $idempotencyKey);
        $order = (array) ($result['order'] ?? []);
        $payment = (array) ($result['payment'] ?? []);
        $orderSN = trim((string) ($order['id'] ?? ''));
        if ($orderSN === '') {
            throw new RuntimeException($this->t($chatId, 'order_id_missing'));
        }

        $this->rememberOrder($chatId, $orderSN);
        Cache::forget($this->sessionKey($chatId));
        $text = $this->t($chatId, 'order_created')."\n\n"
            .$this->t($chatId, 'product_label').' '.(string) ($state['product']['name'] ?? $this->t($chatId, 'product_default'))."\n"
            .$this->t($chatId, 'quantity_label').' '.(int) ($state['quantity'] ?? 1)."\n"
            .$this->t($chatId, 'amount_label').' ¥'.(string) ($order['amount'] ?? '0.00')."\n"
            .$this->t($chatId, 'order_number_label').' '.$orderSN."\n";
        if (!empty($state['search_password'])) {
            $text .= $this->t($chatId, 'lookup_password_label').' '.$state['search_password']."\n";
        }
        if (empty($order['expires_at'])) {
            $text .= "\n".$this->t($chatId, 'deadline_generic');
        } else {
            $text .= "\n".$this->t($chatId, 'deadline', ['deadline' => $order['expires_at']]);
        }

        if ($this->isBinancePayment($method)) {
            // The Binance checkout page is still available on the website, but
            // Telegram users receive the exact quote and QR code in-chat.
            $paymentData = $this->api->telegramPay($orderSN, $chatId, $method);
            if (array_key_exists('payment_required', $paymentData)
                && !$paymentData['payment_required']
            ) {
                $this->showOrder($chatId, $orderSN, $origin);
                return;
            }
            $payment = (array) ($paymentData['payment'] ?? []);
            if (!empty($payment['qr_payload']) && !empty($payment['expected_usdt'])) {
                $this->sendBinancePayment($chatId, $text, $payment, $orderSN, $origin);
                return;
            }
        }

        $keyboard = $this->orderKeyboard($chatId, $orderSN, $payment);
        $this->respond($chatId, $text, ['inline_keyboard' => $keyboard], $origin);
    }

    private function showOrders(string $chatId, array $origin = []): void
    {
        $orders = $this->ownedOrders($chatId);
        if (!$orders) {
            $this->respond($chatId, $this->t($chatId, 'no_orders'), $this->homeKeyboard($chatId), $origin);
            return;
        }

        $text = $this->t($chatId, 'orders_title');
        $keyboard = [];
        foreach ($orders as $orderSN) {
            $keyboard[] = [[
                'text' => $this->t($chatId, 'order_button', ['order' => $orderSN]),
                'callback_data' => 'shop:order:'.$orderSN,
            ]];
        }
        $keyboard[] = [['text' => $this->t($chatId, 'continue_shopping'), 'callback_data' => 'shop:products:0']];
        $this->respond($chatId, $text, ['inline_keyboard' => $keyboard], $origin);
    }

    private function showOrder(string $chatId, string $orderSN, array $origin = []): void
    {
        if (!$this->ownsOrder($chatId, $orderSN)) {
            $this->respond($chatId, $this->t($chatId, 'wrong_order'), $this->homeKeyboard($chatId), $origin);
            return;
        }

        $data = $this->api->telegramOrder($orderSN, $chatId);
        $order = (array) ($data['order'] ?? []);
        $status = (string) ($order['status'] ?? 'unknown');
        $text = $this->t($chatId, 'order_button', ['order' => $orderSN])."\n\n"
            .$this->t($chatId, 'status_label').' '.$this->statusLabel($status, $chatId)."\n"
            .$this->t($chatId, 'amount_label').' ¥'.(string) ($order['amount'] ?? '0.00')."\n"
            .$this->t($chatId, 'quantity_label').' '.(int) ($order['quantity'] ?? 0)."\n"
            .$this->t($chatId, 'product_label').' '.(string) (($order['product']['name'] ?? '') ?: $this->t($chatId, 'product_default'))."\n";
        if (!empty($order['expires_at'])) {
            $text .= $this->t($chatId, 'deadline_label').' '.(string) $order['expires_at']."\n";
        }

        $binancePayment = null;
        $keyboard = [];
        if ($status === 'wait_pay') {
            try {
                $paymentMethod = (string) ($order['payment_method'] ?? '');
                $paymentData = $this->api->telegramPay(
                    $orderSN,
                    $chatId,
                    $paymentMethod
                );
                if (array_key_exists('payment_required', $paymentData)
                    && !$paymentData['payment_required']
                ) {
                    $this->showOrder($chatId, $orderSN, $origin);
                    return;
                }
                $payment = (array) ($paymentData['payment'] ?? []);
                if ($this->isBinancePayment($paymentMethod)) {
                    $binancePayment = $payment;
                } else {
                    if (!empty($payment['url'])) {
                        $keyboard[] = [[
                            'text' => $this->t($chatId, 'go_pay'),
                            'url' => (string) $payment['url'],
                        ]];
                    }
                }
            } catch (Throwable $exception) {
                $this->reportApiFailure($exception);
            }
        }
        if ($status === 'completed') {
            $keyboard[] = [[
                'text' => $this->t($chatId, 'view_delivery'),
                'callback_data' => 'shop:delivery:'.$orderSN,
            ]];
        }
        $keyboard[] = [
            ['text' => $this->t($chatId, 'refresh'), 'callback_data' => 'shop:order:'.$orderSN],
            ['text' => $this->t($chatId, 'shopping'), 'callback_data' => 'shop:products:0'],
        ];
        if ($binancePayment !== null
            && !empty($binancePayment['qr_payload'])
            && !empty($binancePayment['expected_usdt'])
        ) {
            $this->sendBinancePayment($chatId, $text, $binancePayment, $orderSN, $origin);
            return;
        }
        $this->respond($chatId, $text, ['inline_keyboard' => $keyboard], $origin);
    }

    private function isBinancePayment(string $method): bool
    {
        return strtolower(trim($method)) === 'binancepay';
    }

    private function orderKeyboard(string $chatId, string $orderSN, array $payment = []): array
    {
        $keyboard = [];
        if (!$this->isBinancePayment((string) ($payment['method'] ?? ''))
            && !empty($payment['url'])
        ) {
            $keyboard[] = [[
                'text' => $this->t($chatId, 'go_pay'),
                'url' => (string) $payment['url'],
            ]];
        }
        $keyboard[] = [
            ['text' => $this->t($chatId, 'refresh_status'), 'callback_data' => 'shop:order:'.$orderSN],
            ['text' => $this->t($chatId, 'my_orders'), 'callback_data' => 'shop:orders'],
        ];

        return $keyboard;
    }

    private function sendBinancePayment(
        string $chatId,
        string $orderText,
        array $payment,
        string $orderSN,
        array $origin = []
    ): void {
        $qrPayload = trim((string) ($payment['qr_payload'] ?? ''));
        $expected = trim((string) ($payment['expected_usdt'] ?? ''));
        if ($qrPayload === '' || $expected === '') {
            throw new RuntimeException($this->t($chatId, 'qr_missing'));
        }

        $currency = strtoupper(trim((string) ($payment['currency'] ?? 'USDT')));
        $caption = $orderText
            ."\n\n".$this->t($chatId, 'binance_method')."\n"
            .$this->t($chatId, 'amount_due', [
                'amount' => $expected,
                'currency' => $currency,
            ])."\n";
        if (!empty($payment['quote_expires_at'])) {
            $caption .= $this->t($chatId, 'quote_expiry').' '.(string) $payment['quote_expires_at']."\n";
        }
        $caption .= "\n".$this->t($chatId, 'binance_instruction');
        // Telegram limits photo captions to 1024 characters. Bound only the
        // untrusted order summary so amount, expiry, and payment instructions
        // remain visible even when a product name is unusually long.
        $orderTextLength = mb_strlen($orderText, 'UTF-8');
        $captionLength = mb_strlen($caption, 'UTF-8');
        $fixedCaption = mb_substr($caption, $orderTextLength, $captionLength, 'UTF-8');
        $summaryLimit = max(0, 1024 - mb_strlen($fixedCaption, 'UTF-8'));
        $summary = mb_substr($orderText, 0, $summaryLimit, 'UTF-8');
        $caption = $summary.$fixedCaption;
        $keyboard = ['reply_markup' => ['inline_keyboard' => $this->orderKeyboard($chatId, $orderSN, $payment)]];

        // A callback message cannot be converted into a photo with
        // editMessageText. Update it when possible, then send the QR as the
        // next message so the customer always gets an image.
        if (!empty($origin['message_id'])) {
            try {
                $this->telegram->editMessageText(
                    $this->token(),
                    $chatId,
                    (int) $origin['message_id'],
                    $orderText."\n\n".$this->t($chatId, 'qr_sent'),
                    $keyboard
                );
            } catch (Throwable $exception) {
                // The callback origin may already have been edited; the photo
                // message remains the source of truth.
            }
        }

        $png = (string) QrCode::format('png')
            ->size(420)
            ->margin(1)
            ->generate($qrPayload);
        $this->telegram->sendPhoto(
            $this->token(),
            $chatId,
            $png,
            $caption,
            $keyboard
        );
    }

    private function showDelivery(string $chatId, string $orderSN, array $origin = []): void
    {
        if (!$this->ownsOrder($chatId, $orderSN)) {
            $this->respond($chatId, $this->t($chatId, 'wrong_order_short'), $this->homeKeyboard($chatId), $origin);
            return;
        }

        $data = $this->api->telegramDelivery($orderSN, $chatId);
        $delivery = (array) ($data['delivery'] ?? []);
        if (empty($delivery['available'])) {
            $this->respond(
                $chatId,
                $this->t($chatId, 'delivery_unavailable', [
                    'status' => $this->statusLabel((string) ($delivery['status'] ?? 'unknown'), $chatId),
                ]),
                ['inline_keyboard' => [[[
                    'text' => $this->t($chatId, 'refresh_order'),
                    'callback_data' => 'shop:order:'.$orderSN,
                ]]]],
                $origin
            );
            return;
        }

        $content = trim((string) ($delivery['content'] ?? ''));
        $parts = $this->splitText(
            $this->t($chatId, 'delivery_heading', ['order' => $orderSN])."\n\n".$content
        );
        foreach ($parts as $index => $part) {
            $keyboard = $index === count($parts) - 1 ? $this->homeKeyboard($chatId) : [];
            if ($index === 0 && $origin && isset($origin['message_id']) && count($parts) === 1) {
                $this->respond($chatId, $part, $keyboard, $origin);
            } else {
                $this->send($chatId, $part, $keyboard);
            }
        }
    }

    private function product(int $productId): ?array
    {
        $data = $this->api->products();
        foreach ((array) ($data['products'] ?? []) as $product) {
            if ((int) ($product['id'] ?? 0) === $productId) {
                return (array) $product;
            }
        }
        return null;
    }

    private function privateChatId(array $chat): ?string
    {
        if (($chat['type'] ?? '') !== 'private') {
            return null;
        }
        $chatId = (string) ($chat['id'] ?? '');
        return preg_match('/^[1-9][0-9]*$/', $chatId) ? $chatId : null;
    }

    private function getState(string $chatId): ?array
    {
        $state = Cache::get($this->sessionKey($chatId));
        return is_array($state) ? $state : null;
    }

    private function putState(string $chatId, array $state): void
    {
        Cache::put(
            $this->sessionKey($chatId),
            $state,
            now()->addMinutes(self::SESSION_MINUTES)
        );
    }

    private function sessionKey(string $chatId): string
    {
        return 'telegram-shop:session:'.$chatId;
    }

    private function rememberOrder(string $chatId, string $orderSN): void
    {
        // Do not make a second network call while handling the create-order
        // response; the just-created order is already authoritative.
        $orders = $this->ownedOrders($chatId, false);
        array_unshift($orders, strtoupper($orderSN));
        $orders = array_values(array_unique(array_slice($orders, 0, 10)));
        Cache::put(
            $this->ordersKey($chatId),
            $orders,
            now()->addMinutes(self::ORDER_MINUTES)
        );
    }

    private function ownedOrders(string $chatId, bool $refreshRemote = true): array
    {
        $cached = (array) Cache::get($this->ordersKey($chatId), []);
        $remote = [];
        if ($refreshRemote) {
            try {
                $data = $this->api->telegramOrders($chatId);
                foreach ((array) ($data['orders'] ?? []) as $order) {
                    $orderSN = strtoupper(trim((string) ($order['id'] ?? '')));
                    if ($orderSN !== '') {
                        $remote[] = $orderSN;
                    }
                }
            } catch (Throwable $exception) {
                // A temporary API failure should not hide orders already
                // cached for this chat. The next /orders refresh retries.
            }
        }

        $orders = array_merge($remote, $cached);
        $orders = array_values(array_unique(array_filter($orders, function ($orderSN) {
            return is_string($orderSN)
                && preg_match('/^[A-Z0-9]{1,150}$/', $orderSN) === 1;
        })));
        Cache::put(
            $this->ordersKey($chatId),
            array_slice($orders, 0, 20),
            now()->addMinutes(self::ORDER_MINUTES)
        );
        return array_slice($orders, 0, 20);
    }

    private function ownsOrder(string $chatId, string $orderSN): bool
    {
        $orderSN = strtoupper(trim($orderSN));
        // The local order list is only a rendering cache. A chat can be
        // rebound while that cache is still alive, so never use it as an
        // authorization decision. The signed API performs the authoritative
        // customer_id/chat_id check on every order action.
        try {
            $data = $this->api->telegramOrder($orderSN, $chatId);
            return strtoupper(trim((string) ($data['order']['id'] ?? ''))) === $orderSN;
        } catch (Throwable $exception) {
            return false;
        }
    }

    private function ordersKey(string $chatId): string
    {
        return 'telegram-shop:orders:'.$chatId;
    }

    private function respond(
        string $chatId,
        string $text,
        array $keyboard,
        array $origin = []
    ): void {
        $payload = $keyboard ? ['reply_markup' => $keyboard] : [];
        if (!empty($origin['message_id'])) {
            try {
                $this->telegram->editMessageText(
                    $this->token(),
                    $chatId,
                    (int) $origin['message_id'],
                    $text,
                    $payload
                );
                return;
            } catch (Throwable $exception) {
                // The original message may already have been edited or expired.
            }
        }
        $this->send($chatId, $text, $keyboard);
    }

    private function send(string $chatId, string $text, array $keyboard = []): void
    {
        $this->telegram->sendMessage(
            $this->token(),
            $chatId,
            $text,
            $keyboard ? ['reply_markup' => $keyboard] : []
        );
    }

    private function token(): string
    {
        $token = trim((string) dujiaoka_config_get('telegram_bot_token'));
        if ($token === '') {
            throw new RuntimeException('Telegram bot token is not configured.');
        }
        return $token;
    }

    private function homeKeyboard(string $chatId): array
    {
        return ['inline_keyboard' => [
            [
                ['text' => $this->t($chatId, 'browse_products'), 'callback_data' => 'shop:products:0'],
                ['text' => $this->t($chatId, 'my_orders'), 'callback_data' => 'shop:orders'],
            ],
            [['text' => $this->t($chatId, 'help_button'), 'callback_data' => 'shop:help']],
            [['text' => $this->t($chatId, 'language_button'), 'callback_data' => 'shop:languages']],
        ]];
    }

    private function cancelKeyboard(string $chatId): array
    {
        return ['inline_keyboard' => [
            [['text' => $this->t($chatId, 'cancel_order'), 'callback_data' => 'shop:cancel']],
        ]];
    }

    private function passwordKeyboard(string $chatId): array
    {
        return ['inline_keyboard' => [
            [['text' => $this->t($chatId, 'auto_password'), 'callback_data' => 'shop:pwd:auto']],
            [['text' => $this->t($chatId, 'cancel_order'), 'callback_data' => 'shop:cancel']],
        ]];
    }

    private function helpText(string $chatId): string
    {
        return $this->t($chatId, 'help_text');
    }

    private function statusLabel(string $status, string $chatId = ''): string
    {
        $status = strtolower(trim($status));
        if (!in_array($status, [
            'wait_pay',
            'pending',
            'processing',
            'completed',
            'failure',
            'expired',
            'abnormal',
        ], true)) {
            $status = 'unknown';
        }
        return $this->t($chatId, 'status_'.$status);
    }

    private function paymentMethodLabel(string $chatId, string $code, string $name): string
    {
        $normalized = strtolower(trim($code));
        if (strpos($normalized, 'binance') !== false) {
            return $this->t($chatId, 'method_binance');
        }
        if (strpos($normalized, 'wechat') !== false
            || strpos($normalized, 'weixin') !== false
            || strpos($normalized, 'wxpay') !== false
        ) {
            return $this->t($chatId, 'method_wechat');
        }
        if (strpos($normalized, 'alipay') !== false
            || strpos($normalized, 'aliweb') !== false
            || strpos($normalized, 'zfb') !== false
        ) {
            return $this->t($chatId, 'method_alipay');
        }
        if (strpos($normalized, 'usdt') !== false
            || strpos($normalized, 'epusdt') !== false
        ) {
            return $this->t($chatId, 'method_usdt');
        }

        return $this->t($chatId, 'payment_method_default', [
            'code' => $code !== '' ? $code : $name,
        ]);
    }

    private function showLanguages(string $chatId, array $origin = []): void
    {
        $this->respond(
            $chatId,
            $this->t($chatId, 'language_title'),
            ['inline_keyboard' => $this->languageKeyboard($chatId)],
            $origin
        );
    }

    private function selectLanguage(string $chatId, string $language, array $origin = []): void
    {
        $language = strtolower(trim($language));
        if (!in_array($language, $this->supportedLanguages(), true)) {
            $this->showLanguages($chatId, $origin);
            return;
        }

        $this->setLanguage($chatId, $language);
        $this->respond(
            $chatId,
            $this->t($chatId, 'language_changed', [
                'language' => $this->languageName($language),
            ])."\n\n".$this->t($chatId, 'home_text'),
            $this->homeKeyboard($chatId),
            $origin
        );
    }

    private function languageKeyboard(string $chatId): array
    {
        $current = $this->language($chatId);
        $languages = [];
        foreach ($this->supportedLanguages() as $language) {
            $prefix = $language === $current ? '✓ ' : '';
            $languages[] = [[
                'text' => $prefix.$this->languageName($language),
                'callback_data' => 'shop:lang:'.$language,
            ]];
        }
        $languages[] = [[
            'text' => $this->t($chatId, 'home'),
            'callback_data' => 'shop:home',
        ]];
        return $languages;
    }

    private function languageName(string $language): string
    {
        return [
            'zh' => '中文',
            'en' => 'English',
            'vi' => 'Tiếng Việt',
        ][$language] ?? '中文';
    }

    private function supportedLanguages(): array
    {
        return ['zh', 'en', 'vi'];
    }

    private function language(string $chatId, ?string $hint = null): string
    {
        $stored = strtolower(trim((string) Cache::get($this->languageKey($chatId), '')));
        if (in_array($stored, $this->supportedLanguages(), true)) {
            return $stored;
        }

        $hint = strtolower(trim((string) $hint));
        if (strpos($hint, 'en') === 0) {
            $stored = 'en';
        } elseif (strpos($hint, 'vi') === 0) {
            $stored = 'vi';
        } elseif (strpos($hint, 'zh') === 0) {
            $stored = 'zh';
        } else {
            $stored = self::DEFAULT_LANGUAGE;
        }
        $this->setLanguage($chatId, $stored);
        return $stored;
    }

    private function setLanguage(string $chatId, string $language): void
    {
        Cache::put(
            $this->languageKey($chatId),
            $language,
            now()->addDays(self::LANGUAGE_TTL_DAYS)
        );
    }

    private function languageKey(string $chatId): string
    {
        return 'telegram-shop:language:'.$chatId;
    }

    private function t(string $chatId, string $key, array $replace = []): string
    {
        $language = $this->language($chatId);
        $catalog = $this->translations();
        $text = $catalog[$language][$key]
            ?? $catalog[self::DEFAULT_LANGUAGE][$key]
            ?? $key;
        foreach ($replace as $name => $value) {
            $text = str_replace('{'.$name.'}', (string) $value, $text);
        }
        return $text;
    }

    private function translations(): array
    {
        static $catalog;
        if ($catalog !== null) {
            return $catalog;
        }

        $catalog = [
            'zh' => [
                'choose_action' => '请选择一个操作：',
                'cancelled' => '当前下单流程已取消。',
                'continue_hint' => '请使用下方按钮继续，或发送 /cancel 取消。',
                'generic_error' => '这次操作没有完成，请稍后重试。',
                'home_text' => "欢迎来到 NewZoe 商城\n\n可以直接浏览商品、创建订单并跳转支付。",
                'browse_products' => '🛍️ 浏览商品',
                'my_orders' => '📦 我的订单',
                'help_button' => 'ℹ️ 使用说明',
                'language_button' => '🌐 语言',
                'language_title' => '选择语言：',
                'language_changed' => '语言已切换为：{language}',
                'products_title' => "商品列表\n\n",
                'no_products' => '当前没有可售商品。',
                'products_hint' => '点击商品查看详情：',
                'product_default' => '商品',
                'out_of_stock' => '缺货',
                'previous' => '‹ 上一页',
                'next' => '下一页 ›',
                'home' => '返回首页',
                'product_missing' => '商品不存在或已经下架。',
                'product_missing_retry' => '商品不存在或已经下架，请重新选择商品。',
                'price' => '价格：¥{amount}',
                'stock' => '库存：{stock}',
                'fields_intro' => '下单时还需要填写：',
                'field_default' => '信息',
                'buy_quantity' => '购买 {quantity} 件',
                'back_products' => '‹ 返回商品列表',
                'quantity_changed' => '购买数量已经变化，请重新选择商品。',
                'selected_order' => "已选择：{product} × {quantity}\n\n请选择支付方式：",
                'selected_email' => "已选择：{product} × {quantity}\n\n请输入接收商品的邮箱：",
                'cancel_order' => '取消下单',
                'email_invalid' => '邮箱格式不正确，请重新输入：',
                'email_recorded' => "邮箱已记录。\n\n请输入查单密码；发送 - 可由系统自动生成一个随机密码：",
                'password_wait_missing' => '当前没有等待查单密码的订单。',
                'password_too_long' => '查单密码过长，请重新输入：',
                'auto_password' => '🔐 自动生成查单密码',
                'input_required' => '请输入{label}（必填）：',
                'input_optional' => '请输入{label}（可发送 - 跳过）：',
                'input_required_error' => '这一项是必填的，请重新输入：',
                'payment_wait_missing' => '当前没有等待支付方式的订单。',
                'no_payment_methods' => '当前没有可用支付方式，请稍后再试。',
                'payment_select' => "信息已填写完成\n\n请选择支付方式：",
                'session_expired' => '当前下单流程已经过期，请重新开始。',
                'order_id_missing' => '订单号缺失。',
                'order_created' => '订单已创建',
                'product_label' => '商品：',
                'quantity_label' => '数量：',
                'amount_label' => '金额：',
                'order_number_label' => '订单号：',
                'lookup_password_label' => '查单密码：',
                'deadline_generic' => '请在支付页面显示的截止时间前完成支付。',
                'deadline' => '请在 {deadline} 前完成支付。',
                'no_orders' => '这里还没有通过机器人创建的订单。',
                'orders_title' => "我的订单\n\n点击订单查看最新状态：",
                'order_button' => '订单 {order}',
                'continue_shopping' => '🛍️ 继续购物',
                'wrong_order' => '这个订单不属于当前聊天，或订单记录已经过期。',
                'status_label' => '状态：',
                'deadline_label' => '支付截止：',
                'go_pay' => '🚀 前往支付',
                'view_delivery' => '📦 查看卡密',
                'refresh' => '🔄 刷新',
                'shopping' => '🛍️ 购物',
                'refresh_status' => '🔄 刷新状态',
                'qr_missing' => '币安支付二维码或应付金额缺失。',
                'binance_method' => '支付方式：币安支付',
                'amount_due' => '应付：{amount} {currency}',
                'quote_expiry' => '报价有效至：',
                'binance_instruction' => '请使用币安 App 扫描下方二维码，支付准确金额。',
                'qr_sent' => '币安二维码已发送，请扫描下一条消息。',
                'wrong_order_short' => '这个订单不属于当前聊天。',
                'delivery_unavailable' => '订单当前还不能发货，状态：{status}',
                'refresh_order' => '🔄 刷新订单',
                'delivery_heading' => '订单 {order} 发货内容：',
                'method_binance' => '币安支付',
                'method_wechat' => '微信支付',
                'method_alipay' => '支付宝',
                'method_usdt' => 'USDT',
                'payment_method_default' => '支付方式 {code}',
                'help_text' => "使用说明\n\n1. 浏览商品并选择购买数量\n2. 按提示填写商品所需信息（如有）\n3. 选择支付方式，点击支付按钮完成付款\n4. 在“我的订单”里刷新状态，支付完成后查看发货内容\n\n订单会自动绑定当前 Telegram 私聊，可直接在机器人查询。订单有效期以订单页面显示为准。",
                'status_wait_pay' => '待支付',
                'status_pending' => '待处理',
                'status_processing' => '处理中',
                'status_completed' => '已完成',
                'status_failure' => '处理失败',
                'status_expired' => '已过期',
                'status_abnormal' => '异常',
                'status_unknown' => '未知',
            ],
            'en' => [
                'choose_action' => 'Please choose an action:',
                'cancelled' => 'The current order process has been cancelled.',
                'continue_hint' => 'Use the buttons below to continue, or send /cancel to cancel.',
                'generic_error' => 'This operation could not be completed. Please try again later.',
                'home_text' => "Welcome to NewZoe Shop\n\nBrowse products, create orders, and continue to payment.",
                'browse_products' => '🛍️ Browse products',
                'my_orders' => '📦 My orders',
                'help_button' => 'ℹ️ Help',
                'language_button' => '🌐 Language',
                'language_title' => 'Choose a language:',
                'language_changed' => 'Language changed to: {language}',
                'products_title' => "Product list\n\n",
                'no_products' => 'There are no products available right now.',
                'products_hint' => 'Select a product to view details:',
                'product_default' => 'Product',
                'out_of_stock' => 'Out of stock',
                'previous' => '‹ Previous',
                'next' => 'Next ›',
                'home' => 'Back to home',
                'product_missing' => 'This product does not exist or is no longer available.',
                'product_missing_retry' => 'This product does not exist or is no longer available. Please choose another.',
                'price' => 'Price: ¥{amount}',
                'stock' => 'Stock: {stock}',
                'fields_intro' => 'Additional information is required to place this order:',
                'field_default' => 'Information',
                'buy_quantity' => 'Buy {quantity} item(s)',
                'back_products' => '‹ Back to products',
                'quantity_changed' => 'The available quantity has changed. Please choose again.',
                'selected_order' => "Selected: {product} × {quantity}\n\nChoose a payment method:",
                'selected_email' => "Selected: {product} × {quantity}\n\nEnter the email address for delivery:",
                'cancel_order' => 'Cancel order',
                'email_invalid' => 'Invalid email format. Please enter it again:',
                'email_recorded' => "Email saved.\n\nEnter an order lookup password, or send - to generate a random password automatically:",
                'password_wait_missing' => 'No order is currently waiting for an order lookup password.',
                'password_too_long' => 'The order lookup password is too long. Please enter it again:',
                'auto_password' => '🔐 Generate lookup password',
                'input_required' => 'Enter {label} (required):',
                'input_optional' => 'Enter {label} (send - to skip):',
                'input_required_error' => 'This field is required. Please enter it again:',
                'payment_wait_missing' => 'No order is currently waiting for a payment method.',
                'no_payment_methods' => 'No payment methods are available right now. Please try again later.',
                'payment_select' => "Your information is complete.\n\nChoose a payment method:",
                'session_expired' => 'This order session has expired. Please start again.',
                'order_id_missing' => 'Order number is missing.',
                'order_created' => 'Order created',
                'product_label' => 'Product:',
                'quantity_label' => 'Quantity:',
                'amount_label' => 'Amount:',
                'order_number_label' => 'Order ID:',
                'lookup_password_label' => 'Lookup password:',
                'deadline_generic' => 'Please complete payment before the deadline shown on the payment page.',
                'deadline' => 'Please complete payment before {deadline}.',
                'no_orders' => 'No orders have been created through the bot yet.',
                'orders_title' => "My orders\n\nSelect an order to view its latest status:",
                'order_button' => 'Order {order}',
                'continue_shopping' => '🛍️ Continue shopping',
                'wrong_order' => 'This order does not belong to this chat, or its record has expired.',
                'status_label' => 'Status:',
                'deadline_label' => 'Payment deadline:',
                'go_pay' => '🚀 Proceed to payment',
                'view_delivery' => '📦 View delivery',
                'refresh' => '🔄 Refresh',
                'shopping' => '🛍️ Shop',
                'refresh_status' => '🔄 Refresh status',
                'qr_missing' => 'Binance QR code or amount due is missing.',
                'binance_method' => 'Payment method: Binance Pay',
                'amount_due' => 'Amount due: {amount} {currency}',
                'quote_expiry' => 'Quote valid until:',
                'binance_instruction' => 'Scan the QR code below with the Binance app and pay the exact amount.',
                'qr_sent' => 'The Binance QR code has been sent. Scan the next message.',
                'wrong_order_short' => 'This order does not belong to this chat.',
                'delivery_unavailable' => 'This order is not ready for delivery. Status: {status}',
                'refresh_order' => '🔄 Refresh order',
                'delivery_heading' => 'Delivery for order {order}:',
                'method_binance' => 'Binance Pay',
                'method_wechat' => 'WeChat Pay',
                'method_alipay' => 'Alipay',
                'method_usdt' => 'USDT',
                'payment_method_default' => 'Payment method {code}',
                'help_text' => "Help\n\n1. Browse products and choose a quantity\n2. Enter any product information requested (if applicable)\n3. Choose a payment method and click the payment button to pay\n4. Refresh status in “My orders”; view delivery content after payment is complete\n\nOrders are automatically linked to this Telegram chat and can be queried in the bot. Order validity follows the deadline shown on the order page.",
                'status_wait_pay' => 'Awaiting payment',
                'status_pending' => 'Pending',
                'status_processing' => 'Processing',
                'status_completed' => 'Completed',
                'status_failure' => 'Processing failed',
                'status_expired' => 'Expired',
                'status_abnormal' => 'Abnormal',
                'status_unknown' => 'Unknown',
            ],
            'vi' => [
                'choose_action' => 'Vui lòng chọn một thao tác:',
                'cancelled' => 'Quy trình đặt hàng hiện tại đã được hủy.',
                'continue_hint' => 'Nhấn nút bên dưới để tiếp tục hoặc gửi /cancel để hủy.',
                'generic_error' => 'Không thể hoàn tất thao tác này. Vui lòng thử lại sau.',
                'home_text' => "Chào mừng bạn đến NewZoe Shop\n\nBạn có thể duyệt sản phẩm, tạo đơn hàng và chuyển đến thanh toán.",
                'browse_products' => '🛍️ Duyệt sản phẩm',
                'my_orders' => '📦 Đơn hàng của tôi',
                'help_button' => 'ℹ️ Hướng dẫn',
                'language_button' => '🌐 Ngôn ngữ',
                'language_title' => 'Chọn ngôn ngữ:',
                'language_changed' => 'Đã chuyển ngôn ngữ sang: {language}',
                'products_title' => "Danh sách sản phẩm\n\n",
                'no_products' => 'Hiện chưa có sản phẩm nào đang bán.',
                'products_hint' => 'Chọn sản phẩm để xem chi tiết:',
                'product_default' => 'Sản phẩm',
                'out_of_stock' => 'Hết hàng',
                'previous' => '‹ Trang trước',
                'next' => 'Trang sau ›',
                'home' => 'Về trang chủ',
                'product_missing' => 'Sản phẩm không tồn tại hoặc đã ngừng bán.',
                'product_missing_retry' => 'Sản phẩm không tồn tại hoặc đã ngừng bán. Vui lòng chọn lại.',
                'price' => 'Giá: ¥{amount}',
                'stock' => 'Tồn kho: {stock}',
                'fields_intro' => 'Cần điền thêm thông tin khi đặt hàng:',
                'field_default' => 'Thông tin',
                'buy_quantity' => 'Mua {quantity} sản phẩm',
                'back_products' => '‹ Quay lại danh sách',
                'quantity_changed' => 'Số lượng mua đã thay đổi. Vui lòng chọn lại.',
                'selected_order' => "Đã chọn: {product} × {quantity}\n\nChọn phương thức thanh toán:",
                'selected_email' => "Đã chọn: {product} × {quantity}\n\nNhập email nhận sản phẩm:",
                'cancel_order' => 'Hủy đặt hàng',
                'email_invalid' => 'Email không hợp lệ. Vui lòng nhập lại:',
                'email_recorded' => "Đã lưu email.\n\nNhập mật khẩu tra cứu đơn hàng; gửi - để hệ thống tự tạo mật khẩu ngẫu nhiên:",
                'password_wait_missing' => 'Hiện không có đơn hàng nào đang chờ mật khẩu tra cứu.',
                'password_too_long' => 'Mật khẩu tra cứu quá dài. Vui lòng nhập lại:',
                'auto_password' => '🔐 Tạo mật khẩu tra cứu tự động',
                'input_required' => 'Nhập {label} (bắt buộc):',
                'input_optional' => 'Nhập {label} (gửi - để bỏ qua):',
                'input_required_error' => 'Trường này là bắt buộc. Vui lòng nhập lại:',
                'payment_wait_missing' => 'Hiện không có đơn hàng nào đang chờ chọn phương thức thanh toán.',
                'no_payment_methods' => 'Hiện không có phương thức thanh toán khả dụng. Vui lòng thử lại sau.',
                'payment_select' => "Đã điền đủ thông tin.\n\nChọn phương thức thanh toán:",
                'session_expired' => 'Phiên đặt hàng đã hết hạn. Vui lòng bắt đầu lại.',
                'order_id_missing' => 'Thiếu mã đơn hàng.',
                'order_created' => 'Đã tạo đơn hàng',
                'product_label' => 'Sản phẩm:',
                'quantity_label' => 'Số lượng:',
                'amount_label' => 'Số tiền:',
                'order_number_label' => 'Mã đơn hàng:',
                'lookup_password_label' => 'Mật khẩu tra cứu:',
                'deadline_generic' => 'Vui lòng thanh toán trước thời hạn hiển thị trên trang thanh toán.',
                'deadline' => 'Vui lòng thanh toán trước {deadline}.',
                'no_orders' => 'Chưa có đơn hàng nào được tạo qua bot.',
                'orders_title' => "Đơn hàng của tôi\n\nChọn đơn hàng để xem trạng thái mới nhất:",
                'order_button' => 'Đơn hàng {order}',
                'continue_shopping' => '🛍️ Tiếp tục mua sắm',
                'wrong_order' => 'Đơn hàng này không thuộc cuộc trò chuyện hiện tại hoặc bản ghi đã hết hạn.',
                'status_label' => 'Trạng thái:',
                'deadline_label' => 'Hạn thanh toán:',
                'go_pay' => '🚀 Tiến hành thanh toán',
                'view_delivery' => '📦 Xem mã thẻ',
                'refresh' => '🔄 Làm mới',
                'shopping' => '🛍️ Mua sắm',
                'refresh_status' => '🔄 Làm mới trạng thái',
                'qr_missing' => 'Thiếu mã QR Binance hoặc số tiền cần thanh toán.',
                'binance_method' => 'Phương thức thanh toán: Binance Pay',
                'amount_due' => 'Số tiền cần trả: {amount} {currency}',
                'quote_expiry' => 'Báo giá có hiệu lực đến:',
                'binance_instruction' => 'Dùng ứng dụng Binance quét mã QR bên dưới và thanh toán đúng số tiền.',
                'qr_sent' => 'Mã QR Binance đã được gửi. Hãy quét mã trong tin nhắn tiếp theo.',
                'wrong_order_short' => 'Đơn hàng này không thuộc cuộc trò chuyện hiện tại.',
                'delivery_unavailable' => 'Đơn hàng chưa thể giao. Trạng thái: {status}',
                'refresh_order' => '🔄 Làm mới đơn hàng',
                'delivery_heading' => 'Nội dung giao hàng cho đơn {order}:',
                'method_binance' => 'Binance Pay',
                'method_wechat' => 'Thanh toán WeChat',
                'method_alipay' => 'Thanh toán Alipay',
                'method_usdt' => 'USDT',
                'payment_method_default' => 'Phương thức thanh toán {code}',
                'help_text' => "Hướng dẫn\n\n1. Duyệt sản phẩm và chọn số lượng\n2. Nhập thông tin sản phẩm được yêu cầu (nếu có)\n3. Chọn phương thức thanh toán và nhấn nút thanh toán\n4. Làm mới trạng thái trong “Đơn hàng của tôi”; xem nội dung giao hàng sau khi thanh toán hoàn tất\n\nĐơn hàng tự động liên kết với cuộc trò chuyện Telegram này và có thể tra cứu ngay trong bot. Thời hạn đơn hàng theo thời hạn hiển thị trên trang đơn hàng.",
                'status_wait_pay' => 'Chờ thanh toán',
                'status_pending' => 'Đang chờ xử lý',
                'status_processing' => 'Đang xử lý',
                'status_completed' => 'Đã hoàn tất',
                'status_failure' => 'Xử lý thất bại',
                'status_expired' => 'Đã hết hạn',
                'status_abnormal' => 'Bất thường',
                'status_unknown' => 'Không rõ',
            ],
        ];

        return $catalog;
    }

    private function shortText(string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        return mb_strlen($text, 'UTF-8') > $limit
            ? mb_substr($text, 0, $limit - 1, 'UTF-8').'…'
            : $text;
    }

    private function splitText(string $text, int $limit = 3500): array
    {
        $parts = [];
        while (mb_strlen($text, 'UTF-8') > $limit) {
            $part = mb_substr($text, 0, $limit, 'UTF-8');
            $breakAt = mb_strrpos($part, "\n", 0, 'UTF-8');
            $breakAt = $breakAt === false ? $limit : $breakAt;
            $parts[] = rtrim(mb_substr($text, 0, $breakAt, 'UTF-8'));
            $text = ltrim(mb_substr($text, $breakAt, null, 'UTF-8'));
        }
        if ($text !== '') {
            $parts[] = $text;
        }
        return $parts ?: [''];
    }

    private function reportApiFailure(Throwable $exception): void
    {
        report($exception);
    }
}
