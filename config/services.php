<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'telegram' => [
        'proxy' => env('TELEGRAM_PROXY'),
        'connect_timeout' => env('TELEGRAM_CONNECT_TIMEOUT', 5),
        'timeout' => env('TELEGRAM_TIMEOUT', 15),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],

    // Owner API credentials. Keep these separate from payment-provider
    // secrets so rotating an integration does not interrupt checkout.
    'shop_api' => [
        'base_url' => env('DUJIAOKA_API_BASE_URL', env('APP_URL', 'http://localhost')),
        'key' => env('DUJIAOKA_API_KEY', ''),
        'secret' => env('DUJIAOKA_API_SECRET', ''),
        'timestamp_tolerance' => env('DUJIAOKA_API_TIMESTAMP_TOLERANCE', 300),
        'idempotency_ttl' => env('DUJIAOKA_API_IDEMPOTENCY_TTL', 86400),
    ],

    'newzoe_pay' => [
        'payment_minutes' => env('NEWZOE_PAY_PAYMENT_MINUTES', 20),
        'settlement_grace_minutes' => env('NEWZOE_PAY_SETTLEMENT_GRACE_MINUTES', 5),
        // New WeChat checkouts are paused during the phone's unreliable
        // overnight forwarding period. The values are local-clock times.
        'wechat_night_pause_enabled' => env('NEWZOE_PAY_WECHAT_NIGHT_PAUSE_ENABLED', true),
        'wechat_pause_start' => env('NEWZOE_PAY_WECHAT_PAUSE_START', '22:00'),
        'wechat_pause_end' => env('NEWZOE_PAY_WECHAT_PAUSE_END', '06:00'),
        'schedule_timezone' => env('NEWZOE_PAY_SCHEDULE_TIMEZONE', 'Asia/Shanghai'),
    ],

    'binance_pay' => [
        'base_url' => env('BINANCE_PAY_BASE_URL', 'https://api.binance.com'),
        'proxy' => env('BINANCE_PAY_PROXY', 'http://172.19.0.1:17895'),
        'connect_timeout' => env('BINANCE_PAY_CONNECT_TIMEOUT', 8),
        'timeout' => env('BINANCE_PAY_TIMEOUT', 20),
        'recv_window' => env('BINANCE_PAY_RECV_WINDOW', 5000),
        'poll_interval_seconds' => env('BINANCE_PAY_POLL_INTERVAL_SECONDS', env('BINANCE_PAY_POLL_INTERVAL', 60)),
        'max_requests_per_poll' => env('BINANCE_PAY_MAX_REQUESTS_PER_POLL', 40),
        'quote_ttl_minutes' => env('BINANCE_PAY_QUOTE_TTL_MINUTES', 15),
        'match_time_skew_seconds' => env('BINANCE_PAY_MATCH_TIME_SKEW_SECONDS', 5),
        'settlement_grace_seconds' => env('BINANCE_PAY_SETTLEMENT_GRACE_SECONDS', 300),
        'currency' => env('BINANCE_PAY_CURRENCY', 'USDT'),
        'cny_per_usdt' => env('BINANCE_PAY_CNY_PER_USDT', '7.20000000'),
        'receive_qr_payload' => env('BINANCE_PAY_QR_URL', 'https://app.binance.com/uni-qr/Sg9jgWUd'),
        'accepted_order_types' => ['C2C', 'PAY'],
    ],

    'warzone' => [
        'base_url' => env('WARZONE_API_BASE_URL', 'https://api.warzoneshop.in'),
        'connect_timeout' => env('WARZONE_API_CONNECT_TIMEOUT', 5),
        'timeout' => env('WARZONE_API_TIMEOUT', 15),
        'get_attempts' => env('WARZONE_API_GET_ATTEMPTS', 3),
        'post_safe_attempts' => env('WARZONE_API_POST_SAFE_ATTEMPTS', 3),
        'retry_delay_ms' => env('WARZONE_API_RETRY_DELAY_MS', 250),
    ],

];
