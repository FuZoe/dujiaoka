<?php

namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ShopApiClient
{
    private $client;
    private $baseUrl;
    private $key;
    private $secret;

    public function __construct(ClientInterface $client = null)
    {
        $this->client = $client ?: new Client();
        $this->baseUrl = rtrim((string) config('services.shop_api.base_url'), '/');
        $this->key = trim((string) config('services.shop_api.key'));
        $this->secret = (string) config('services.shop_api.secret');
    }

    public function products(): array
    {
        return $this->request('GET', '/api/v1/products')['data'];
    }

    public function paymentMethods(): array
    {
        return $this->request('GET', '/api/v1/payment-methods')['data'];
    }

    public function createOrder(array $payload, string $idempotencyKey): array
    {
        return $this->request('POST', '/api/v1/orders', $payload, [
            'Idempotency-Key' => $idempotencyKey,
        ])['data'];
    }

    public function pay(string $orderSN, string $paymentMethod = ''): array
    {
        $payload = $paymentMethod !== '' ? ['payment_method' => $paymentMethod] : [];
        return $this->request(
            'POST',
            '/api/v1/orders/'.rawurlencode($orderSN).'/pay',
            $payload
        )['data'];
    }

    public function order(string $orderSN): array
    {
        return $this->request('GET', '/api/v1/orders/'.rawurlencode($orderSN))['data'];
    }

    public function delivery(string $orderSN): array
    {
        return $this->request(
            'GET',
            '/api/v1/orders/'.rawurlencode($orderSN).'/delivery'
        )['data'];
    }

    private function request(
        string $method,
        string $path,
        ?array $payload = null,
        array $extraHeaders = []
    ): array {
        if ($this->baseUrl === '' || $this->key === '' || $this->secret === '') {
            throw new RuntimeException('商城 API 尚未配置。');
        }

        $body = $payload === null
            ? ''
            : (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload !== null && $body === '') {
            throw new RuntimeException('商城 API 请求内容无效。');
        }

        $timestamp = (string) time();
        $nonce = Str::random(24);
        $canonical = strtoupper($method)."\n"
            .$path."\n"
            .$timestamp."\n"
            .$nonce."\n"
            .$body;
        $signature = hash_hmac('sha256', $canonical, $this->secret);
        $headers = array_merge([
            'Accept' => 'application/json',
            'X-Api-Key' => $this->key,
            'X-Api-Timestamp' => $timestamp,
            'X-Api-Nonce' => $nonce,
            'X-Api-Signature' => $signature,
        ], $extraHeaders);

        $options = [
            'headers' => $headers,
            'http_errors' => false,
            'connect_timeout' => (float) config('services.telegram.connect_timeout', 5),
            'timeout' => (float) config('services.telegram.timeout', 15),
        ];
        if ($body !== '') {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = $body;
        }

        try {
            $response = $this->client->request(
                strtoupper($method),
                $this->baseUrl.$path,
                $options
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('商城 API 请求失败。');
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded) || empty($decoded['ok']) || !isset($decoded['data'])) {
            $error = is_array($decoded) ? ($decoded['error'] ?? []) : [];
            $message = trim((string) ($error['message'] ?? '商城 API 返回异常。'));
            throw new RuntimeException($message !== '' ? $message : '商城 API 返回异常。');
        }

        return $decoded;
    }
}
