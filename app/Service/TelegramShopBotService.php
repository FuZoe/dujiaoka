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
            $this->send($chatId, '当前下单流程已取消。', $this->homeKeyboard());
            return;
        }

        $state = $this->getState($chatId);
        if (!$state) {
            $this->send($chatId, '请选择一个操作：', $this->homeKeyboard());
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
                    $this->send($chatId, '请使用下方按钮继续，或发送 /cancel 取消。', $this->homeKeyboard());
            }
        } catch (Throwable $exception) {
            $this->reportApiFailure($exception);
            $this->send($chatId, '这次操作没有完成，请稍后重试。', $this->homeKeyboard());
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
                    $this->respond($chatId, '请选择一个操作：', $this->homeKeyboard(), $origin);
                    return;
                case 'help':
                    $this->respond($chatId, $this->helpText(), $this->homeKeyboard(), $origin);
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
                    $this->respond($chatId, '当前下单流程已取消。', $this->homeKeyboard(), $origin);
                    return;
            }
        } catch (Throwable $exception) {
            $this->reportApiFailure($exception);
            $this->respond($chatId, '这次操作没有完成，请稍后重试。', $this->homeKeyboard(), $origin);
        }
    }

    private function showHome(string $chatId): void
    {
        $this->send(
            $chatId,
            "欢迎来到 NewZoe 商城\n\n可以直接浏览商品、创建订单并跳转支付。",
            $this->homeKeyboard()
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

        $text = "商品列表\n\n";
        if (!$slice) {
            $text .= "当前没有可售商品。";
        } else {
            $text .= "点击商品查看详情：";
        }

        $keyboard = [];
        foreach ($slice as $product) {
            $stock = (int) ($product['stock'] ?? 0);
            $label = '🛒 '.$this->shortText((string) ($product['name'] ?? '商品'), 28)
                .' · ¥'.number_format((float) ($product['price'] ?? 0), 2);
            if ($stock < 1) {
                $label .= ' · 缺货';
            }
            $keyboard[] = [[
                'text' => $label,
                'callback_data' => 'shop:product:'.(int) ($product['id'] ?? 0),
            ]];
        }

        $pager = [];
        if ($page > 0) {
            $pager[] = ['text' => '‹ 上一页', 'callback_data' => 'shop:products:'.($page - 1)];
        }
        if ($page < $pages - 1) {
            $pager[] = ['text' => '下一页 ›', 'callback_data' => 'shop:products:'.($page + 1)];
        }
        if ($pager) {
            $keyboard[] = $pager;
        }
        $keyboard[] = [
            ['text' => '📦 我的订单', 'callback_data' => 'shop:orders'],
            ['text' => '返回首页', 'callback_data' => 'shop:home'],
        ];

        $this->respond($chatId, $text, ['inline_keyboard' => $keyboard], $origin);
    }

    private function showProduct(string $chatId, int $productId, array $origin = []): void
    {
        $product = $this->product($productId);
        if (!$product) {
            $this->respond($chatId, '商品不存在或已经下架。', $this->homeKeyboard(), $origin);
            return;
        }

        $stock = (int) ($product['stock'] ?? 0);
        $text = (string) ($product['name'] ?? '商品')."\n\n"
            .'价格：¥'.number_format((float) ($product['price'] ?? 0), 2)."\n"
            .'库存：'.($stock > 0 ? $stock : '缺货')."\n";
        if (!empty($product['description'])) {
            $description = trim(strip_tags((string) $product['description']));
            $text .= "\n".$this->shortText($description, 2600)."\n";
        }
        if (!empty($product['input_fields'])) {
            $text .= "\n下单时还需要填写：";
            foreach ($product['input_fields'] as $field) {
                $text .= "\n· ".(string) ($field['label'] ?? $field['field'] ?? '信息');
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
                    'text' => '购买 '.$quantity.' 件',
                    'callback_data' => 'shop:qty:'.$productId.':'.$quantity,
                ]];
            }
        }
        $keyboard[] = [
            ['text' => '‹ 返回商品列表', 'callback_data' => 'shop:products:0'],
            ['text' => '返回首页', 'callback_data' => 'shop:home'],
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
            $this->respond($chatId, '商品不存在或已经下架，请重新选择商品。', $this->homeKeyboard(), $origin);
            return;
        }
        $stock = (int) ($product['stock'] ?? 0);
        $limit = (int) ($product['max_quantity'] ?? 0);
        $limit = $limit > 0 ? min($limit, $stock) : $stock;
        if ($quantity < 1 || $quantity > $limit) {
            $this->respond($chatId, '购买数量已经变化，请重新选择商品。', $this->homeKeyboard(), $origin);
            return;
        }

        $this->putState($chatId, [
            'step' => 'email',
            'product' => $product,
            'quantity' => $quantity,
            'inputs' => [],
            'input_index' => 0,
        ]);
        $this->respond(
            $chatId,
            '已选择：'.(string) $product['name']." × ".$quantity."\n\n请输入接收商品的邮箱：",
            ['inline_keyboard' => [[
                ['text' => '取消下单', 'callback_data' => 'shop:cancel'],
            ]]],
            $origin
        );
    }

    private function acceptEmail(string $chatId, string $text, array $state): void
    {
        $email = strtolower(trim($text));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 200) {
            $this->send($chatId, '邮箱格式不正确，请重新输入：', $this->cancelKeyboard());
            return;
        }
        $state['email'] = $email;
        $state['step'] = 'password';
        $this->putState($chatId, $state);
        $this->send(
            $chatId,
            "邮箱已记录。\n\n请输入查单密码；发送 - 可由系统自动生成一个随机密码：",
            $this->passwordKeyboard()
        );
    }

    private function choosePassword(string $chatId, string $choice, array $origin = []): void
    {
        $state = $this->getState($chatId);
        if (!$state || ($state['step'] ?? '') !== 'password') {
            $this->respond($chatId, '当前没有等待查单密码的订单。', $this->homeKeyboard(), $origin);
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
            $this->send($chatId, '查单密码过长，请重新输入：', $this->passwordKeyboard());
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

        $label = (string) ($field['label'] ?? $field['field'] ?? '商品信息');
        $required = !empty($field['required']);
        $text = '请输入'.$label.($required ? '（必填）' : '（可发送 - 跳过）').'：';
        $this->respond($chatId, $text, $this->cancelKeyboard(), $origin);
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
            $this->send($chatId, '这一项是必填的，请重新输入：', $this->cancelKeyboard());
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
            $this->respond($chatId, '当前没有等待支付方式的订单。', $this->homeKeyboard(), $origin);
            return;
        }

        $data = $this->api->paymentMethods();
        $methods = array_values($data['payment_methods'] ?? []);
        if (!$methods) {
            $this->respond($chatId, '当前没有可用支付方式，请稍后再试。', $this->homeKeyboard(), $origin);
            return;
        }

        $text = "信息已填写完成\n\n请选择支付方式：";
        $keyboard = [];
        foreach ($methods as $method) {
            $code = (string) ($method['code'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_-]{1,50}$/', $code)) {
                continue;
            }
            $keyboard[] = [[
                'text' => '💳 '.(string) ($method['name'] ?? $code),
                'callback_data' => 'shop:method:'.$code,
            ]];
        }
        if (!$keyboard) {
            $this->respond($chatId, '当前没有可用支付方式，请稍后再试。', $this->homeKeyboard(), $origin);
            return;
        }
        $keyboard[] = [['text' => '取消下单', 'callback_data' => 'shop:cancel']];
        $this->respond($chatId, $text, ['inline_keyboard' => $keyboard], $origin);
    }

    private function choosePaymentMethod(string $chatId, string $method, array $origin = []): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,50}$/', $method)) {
            return;
        }
        $state = $this->getState($chatId);
        if (!$state || ($state['step'] ?? '') !== 'payment') {
            $this->respond($chatId, '当前下单流程已经过期，请重新开始。', $this->homeKeyboard(), $origin);
            return;
        }

        $payload = [
            'product_id' => (int) $state['product']['id'],
            'quantity' => (int) $state['quantity'],
            'email' => (string) $state['email'],
            'payment_method' => $method,
            'search_password' => (string) ($state['search_password'] ?? ''),
            'inputs' => (array) ($state['inputs'] ?? []),
        ];
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
            throw new RuntimeException('订单号缺失。');
        }

        $this->rememberOrder($chatId, $orderSN);
        Cache::forget($this->sessionKey($chatId));
        $text = "订单已创建\n\n"
            .'商品：'.(string) ($state['product']['name'] ?? '商品')."\n"
            .'数量：'.(int) ($state['quantity'] ?? 1)."\n"
            .'金额：¥'.(string) ($order['amount'] ?? '0.00')."\n"
            .'订单号：'.$orderSN."\n";
        if (!empty($state['search_password'])) {
            $text .= '查单密码：'.$state['search_password']."\n";
        }
        if (empty($order['expires_at'])) {
            $text .= "\n请在支付页面显示的截止时间前完成支付。";
        } else {
            $text .= "\n请在 ".$order['expires_at'].' 前完成支付。';
        }

        if ($this->isBinancePayment($method)) {
            // The Binance checkout page is still available on the website, but
            // Telegram users receive the exact quote and QR code in-chat.
            $paymentData = $this->api->pay($orderSN, $method);
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

        $keyboard = $this->orderKeyboard($orderSN, $payment);
        $this->respond($chatId, $text, ['inline_keyboard' => $keyboard], $origin);
    }

    private function showOrders(string $chatId, array $origin = []): void
    {
        $orders = $this->ownedOrders($chatId);
        if (!$orders) {
            $this->respond($chatId, '这里还没有通过机器人创建的订单。', $this->homeKeyboard(), $origin);
            return;
        }

        $text = "我的订单\n\n点击订单查看最新状态：";
        $keyboard = [];
        foreach ($orders as $orderSN) {
            $keyboard[] = [[
                'text' => '订单 '.$orderSN,
                'callback_data' => 'shop:order:'.$orderSN,
            ]];
        }
        $keyboard[] = [['text' => '🛍️ 继续购物', 'callback_data' => 'shop:products:0']];
        $this->respond($chatId, $text, ['inline_keyboard' => $keyboard], $origin);
    }

    private function showOrder(string $chatId, string $orderSN, array $origin = []): void
    {
        if (!$this->ownsOrder($chatId, $orderSN)) {
            $this->respond($chatId, '这个订单不属于当前聊天，或订单记录已经过期。', $this->homeKeyboard(), $origin);
            return;
        }

        $data = $this->api->order($orderSN);
        $order = (array) ($data['order'] ?? []);
        $status = (string) ($order['status'] ?? 'unknown');
        $text = '订单 '.$orderSN."\n\n"
            .'状态：'.$this->statusLabel($status)."\n"
            .'金额：¥'.(string) ($order['amount'] ?? '0.00')."\n"
            .'数量：'.(int) ($order['quantity'] ?? 0)."\n"
            .'商品：'.(string) (($order['product']['name'] ?? '') ?: '商品')."\n";
        if (!empty($order['expires_at'])) {
            $text .= '支付截止：'.(string) $order['expires_at']."\n";
        }

        $binancePayment = null;
        $keyboard = [];
        if ($status === 'wait_pay') {
            try {
                $paymentMethod = (string) ($order['payment_method'] ?? '');
                $paymentData = $this->api->pay(
                    $orderSN,
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
                            'text' => '🚀 前往支付',
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
                'text' => '📦 查看卡密',
                'callback_data' => 'shop:delivery:'.$orderSN,
            ]];
        }
        $keyboard[] = [
            ['text' => '🔄 刷新', 'callback_data' => 'shop:order:'.$orderSN],
            ['text' => '🛍️ 购物', 'callback_data' => 'shop:products:0'],
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

    private function orderKeyboard(string $orderSN, array $payment = []): array
    {
        $keyboard = [];
        if (!$this->isBinancePayment((string) ($payment['method'] ?? ''))
            && !empty($payment['url'])
        ) {
            $keyboard[] = [[
                'text' => '🚀 前往支付',
                'url' => (string) $payment['url'],
            ]];
        }
        $keyboard[] = [
            ['text' => '🔄 刷新状态', 'callback_data' => 'shop:order:'.$orderSN],
            ['text' => '📦 我的订单', 'callback_data' => 'shop:orders'],
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
            throw new RuntimeException('币安支付二维码或应付金额缺失。');
        }

        $currency = strtoupper(trim((string) ($payment['currency'] ?? 'USDT')));
        $caption = $orderText
            ."\n\n支付方式：币安支付\n"
            .'应付：'.$expected.' '.$currency."\n";
        if (!empty($payment['quote_expires_at'])) {
            $caption .= '报价有效至：'.(string) $payment['quote_expires_at']."\n";
        }
        $caption .= "\n请使用币安 App 扫描下方二维码，支付准确金额。";
        // Telegram limits photo captions to 1024 characters. Bound only the
        // untrusted order summary so amount, expiry, and payment instructions
        // remain visible even when a product name is unusually long.
        $orderTextLength = mb_strlen($orderText, 'UTF-8');
        $captionLength = mb_strlen($caption, 'UTF-8');
        $fixedCaption = mb_substr($caption, $orderTextLength, $captionLength, 'UTF-8');
        $summaryLimit = max(0, 1024 - mb_strlen($fixedCaption, 'UTF-8'));
        $summary = mb_substr($orderText, 0, $summaryLimit, 'UTF-8');
        $caption = $summary.$fixedCaption;
        $keyboard = ['reply_markup' => ['inline_keyboard' => $this->orderKeyboard($orderSN, $payment)]];

        // A callback message cannot be converted into a photo with
        // editMessageText. Update it when possible, then send the QR as the
        // next message so the customer always gets an image.
        if (!empty($origin['message_id'])) {
            try {
                $this->telegram->editMessageText(
                    $this->token(),
                    $chatId,
                    (int) $origin['message_id'],
                    $orderText."\n\n币安二维码已发送，请扫描下一条消息。",
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
            $this->respond($chatId, '这个订单不属于当前聊天。', $this->homeKeyboard(), $origin);
            return;
        }

        $data = $this->api->delivery($orderSN);
        $delivery = (array) ($data['delivery'] ?? []);
        if (empty($delivery['available'])) {
            $this->respond(
                $chatId,
                '订单当前还不能发货，状态：'.$this->statusLabel((string) ($delivery['status'] ?? 'unknown')),
                ['inline_keyboard' => [[[
                    'text' => '🔄 刷新订单',
                    'callback_data' => 'shop:order:'.$orderSN,
                ]]]],
                $origin
            );
            return;
        }

        $content = trim((string) ($delivery['content'] ?? ''));
        $parts = $this->splitText("订单 ".$orderSN." 发货内容：\n\n".$content);
        foreach ($parts as $index => $part) {
            $keyboard = $index === count($parts) - 1 ? $this->homeKeyboard() : [];
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
        $orders = $this->ownedOrders($chatId);
        array_unshift($orders, strtoupper($orderSN));
        $orders = array_values(array_unique(array_slice($orders, 0, 10)));
        Cache::put(
            $this->ordersKey($chatId),
            $orders,
            now()->addMinutes(self::ORDER_MINUTES)
        );
    }

    private function ownedOrders(string $chatId): array
    {
        return array_values(array_filter(
            (array) Cache::get($this->ordersKey($chatId), []),
            function ($orderSN) {
                return preg_match('/^[A-Z0-9]{1,150}$/', (string) $orderSN) === 1;
            }
        ));
    }

    private function ownsOrder(string $chatId, string $orderSN): bool
    {
        return in_array(strtoupper(trim($orderSN)), $this->ownedOrders($chatId), true);
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

    private function homeKeyboard(): array
    {
        return ['inline_keyboard' => [
            [
                ['text' => '🛍️ 浏览商品', 'callback_data' => 'shop:products:0'],
                ['text' => '📦 我的订单', 'callback_data' => 'shop:orders'],
            ],
            [['text' => 'ℹ️ 使用说明', 'callback_data' => 'shop:help']],
        ]];
    }

    private function cancelKeyboard(): array
    {
        return ['inline_keyboard' => [
            [['text' => '取消下单', 'callback_data' => 'shop:cancel']],
        ]];
    }

    private function passwordKeyboard(): array
    {
        return ['inline_keyboard' => [
            [['text' => '🔐 自动生成查单密码', 'callback_data' => 'shop:pwd:auto']],
            [['text' => '取消下单', 'callback_data' => 'shop:cancel']],
        ]];
    }

    private function helpText(): string
    {
        return "使用说明\n\n"
            ."1. 浏览商品并选择购买数量\n"
            ."2. 按提示填写邮箱和查单密码\n"
            ."3. 选择支付方式，点击支付按钮完成付款\n"
            ."4. 在“我的订单”里刷新状态，支付完成后查看发货内容\n\n"
            ."订单有效期以订单页面显示为准。机器人只在私聊中处理订单和发货内容。";
    }

    private function statusLabel(string $status): string
    {
        return [
            'wait_pay' => '待支付',
            'pending' => '待处理',
            'processing' => '处理中',
            'completed' => '已完成',
            'failure' => '处理失败',
            'expired' => '已过期',
            'abnormal' => '异常',
        ][$status] ?? '未知';
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
