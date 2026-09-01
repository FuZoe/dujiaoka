<?php

namespace App\Service;

use App\Exceptions\WarzoneApiException;
use App\Models\WarzoneSupplierSetting;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class WarzoneShopClient
{
    /** @var ClientInterface */
    private $client;

    /** @var callable */
    private $sleeper;

    public function __construct(ClientInterface $client = null, callable $sleeper = null)
    {
        $this->client = $client ?: new Client();
        $this->sleeper = $sleeper ?: static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
    }

    public function me(WarzoneSupplierSetting $setting): array
    {
        $payload = $this->request($setting, 'GET', '/api/v1/me');

        return [
            'chat_id' => $this->requiredInteger($payload, 'chat_id', 'account'),
            'first_name' => $this->requiredString($payload, 'first_name', 'account'),
            'wallet_balance' => $this->requiredDecimal($payload, 'wallet_balance', 'account'),
        ];
    }

    public function products(WarzoneSupplierSetting $setting): array
    {
        $payload = $this->request($setting, 'GET', '/api/v1/products');
        if (!array_key_exists('services', $payload) || !is_array($payload['services'])) {
            throw $this->invalidResponse('products');
        }

        $services = [];
        foreach ($payload['services'] as $service) {
            $services[] = $this->normalizeService($service);
        }

        return $services;
    }

    public function snapshot(WarzoneSupplierSetting $setting): array
    {
        $account = $this->me($setting);
        $serviceId = trim((string) $setting->service_id);
        foreach ($this->products($setting) as $service) {
            if (hash_equals($serviceId, $service['service_id'])) {
                return [
                    'balance_usd' => $account['wallet_balance'],
                    'service' => $service,
                ];
            }
        }

        throw new WarzoneApiException('The configured Warzone service was not found.', 404);
    }

    public function order(WarzoneSupplierSetting $setting, int $quantity): array
    {
        if ($quantity < 1 || $quantity > 10000) {
            throw new WarzoneApiException('Warzone order quantity must be between 1 and 10000.');
        }

        $payload = $this->request($setting, 'POST', '/api/v1/order', [
            'json' => [
                'service_id' => trim((string) $setting->service_id),
                'quantity' => $quantity,
            ],
        ]);

        try {
            if (($payload['success'] ?? null) !== true) {
                throw $this->invalidResponse('order');
            }

            $products = $this->requiredStringList($payload, 'products', 'order');
            $returnedQuantity = $this->requiredInteger($payload, 'quantity', 'order');
            $serviceId = $this->requiredString($payload, 'service_id', 'order');
            if ($returnedQuantity !== $quantity
                || count($products) !== $quantity
                || !hash_equals(trim((string) $setting->service_id), $serviceId)) {
                throw $this->invalidResponse('order');
            }

            return [
                'order_id' => $this->requiredProviderOrderId($payload, 'order'),
                'service_id' => $serviceId,
                'quantity' => $returnedQuantity,
                'unit_price' => $this->requiredDecimal($payload, 'unit_price', 'order'),
                'total_cost' => $this->requiredDecimal($payload, 'total_cost', 'order'),
                'new_balance' => $this->requiredDecimal($payload, 'new_balance', 'order'),
                'products' => $products,
            ];
        } catch (WarzoneApiException $exception) {
            throw $this->ambiguousOrderResponse();
        }
    }

    public function orders(WarzoneSupplierSetting $setting, int $page = 1, int $limit = 50): array
    {
        if ($page < 1 || $limit < 1 || $limit > 200) {
            throw new WarzoneApiException('Warzone order history pagination is invalid.');
        }

        $payload = $this->request($setting, 'GET', '/api/v1/orders', [
            'query' => ['page' => $page, 'limit' => $limit],
        ]);
        if (($payload['success'] ?? null) !== true
            || !array_key_exists('orders', $payload)
            || !is_array($payload['orders'])) {
            throw $this->invalidResponse('order history');
        }

        $orders = [];
        foreach ($payload['orders'] as $order) {
            $orders[] = $this->normalizeHistoricalOrder($order);
        }

        return [
            'page' => $this->requiredInteger($payload, 'page', 'order history'),
            'limit' => $this->requiredInteger($payload, 'limit', 'order history'),
            'total_orders' => $this->requiredInteger($payload, 'total_orders', 'order history'),
            'total_pages' => $this->requiredInteger($payload, 'total_pages', 'order history'),
            'orders' => $orders,
        ];
    }

    public function orderById(WarzoneSupplierSetting $setting, string $providerOrderId): array
    {
        $providerOrderId = trim($providerOrderId);
        if ($providerOrderId === ''
            || strlen($providerOrderId) > 128
            || !preg_match('/^[A-Za-z0-9-]+$/', $providerOrderId)) {
            throw new WarzoneApiException('Warzone provider order id is invalid.');
        }

        $payload = $this->request(
            $setting,
            'GET',
            '/api/v1/order/'.rawurlencode($providerOrderId)
        );
        if (($payload['success'] ?? null) !== true
            || !isset($payload['order'])
            || !is_array($payload['order'])) {
            throw $this->invalidResponse('order lookup');
        }

        return $this->normalizeHistoricalOrder($payload['order']);
    }

    private function request(
        WarzoneSupplierSetting $setting,
        string $method,
        string $path,
        array $options = []
    ): array {
        $apiKey = $setting->getApiKey();
        if ($apiKey === '') {
            throw new WarzoneApiException('Warzone API key is not configured.');
        }

        $method = strtoupper($method);
        $maxAttempts = $method === 'GET'
            ? $this->configuredAttempts('get_attempts')
            : $this->configuredAttempts('post_safe_attempts');
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Accept' => 'application/json',
            'X-API-Key' => $apiKey,
        ]);
        $options['connect_timeout'] = $this->configuredTimeout('connect_timeout', 5.0);
        $options['timeout'] = $this->configuredTimeout('timeout', 15.0);
        $options['http_errors'] = false;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $this->client->request(
                    $method,
                    $this->baseUrl().$path,
                    $options
                );
            } catch (Throwable $exception) {
                if ($method !== 'GET') {
                    throw new WarzoneApiException(
                        'Warzone order result is uncertain and requires reconciliation.',
                        null,
                        true
                    );
                }
                if ($attempt === $maxAttempts) {
                    throw new WarzoneApiException(
                        'Warzone API is temporarily unreachable.',
                        null,
                        false,
                        true
                    );
                }
                $this->sleepBeforeRetry();
                continue;
            }

            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                try {
                    return $this->decodeResponse($response);
                } catch (WarzoneApiException $exception) {
                    if ($method !== 'GET') {
                        throw $this->ambiguousOrderResponse($status);
                    }
                    throw $exception;
                }
            }

            $safeToRetry = $method === 'GET'
                ? ($status === 429 || $status >= 500)
                : in_array($status, [429, 503], true);
            if ($safeToRetry && $attempt < $maxAttempts) {
                $this->sleepBeforeRetry($response);
                continue;
            }

            if ($method !== 'GET'
                && !in_array($status, [400, 401, 403, 404, 429, 503], true)) {
                throw $this->ambiguousOrderResponse($status);
            }

            throw $this->httpFailure($status, $safeToRetry);
        }

        throw new WarzoneApiException('Warzone API request failed.', null, false, true);
    }

    private function decodeResponse(ResponseInterface $response): array
    {
        try {
            $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw $this->invalidResponse('API');
        }

        if (!is_array($payload) || $this->isList($payload)) {
            throw $this->invalidResponse('API');
        }

        return $payload;
    }

    private function normalizeService($service): array
    {
        if (!is_array($service) || $this->isList($service)) {
            throw $this->invalidResponse('products');
        }

        $pricing = $this->requiredString($service, 'pricing', 'products');
        if (!in_array($pricing, ['tiered', 'custom', 'unavailable'], true)
            || !array_key_exists('price', $service)
            || !array_key_exists('price_tiers', $service)
            || !isset($service['in_stock'], $service['orderable'])
            || !is_bool($service['in_stock'])
            || !is_bool($service['orderable'])) {
            throw $this->invalidResponse('products');
        }

        $price = $service['price'] === null
            ? null
            : $this->decimalValue($service['price'], 'products');
        if ($pricing !== 'unavailable' && $price === null) {
            throw $this->invalidResponse('products');
        }

        $tiers = $service['price_tiers'];
        if ($tiers !== null && !is_array($tiers)) {
            throw $this->invalidResponse('products');
        }
        $normalizedTiers = null;
        if (is_array($tiers)) {
            $normalizedTiers = [];
            foreach ($tiers as $tier) {
                if (!is_array($tier)) {
                    throw $this->invalidResponse('products');
                }
                $min = $this->requiredInteger($tier, 'min_qty', 'products');
                $max = $this->requiredInteger($tier, 'max_qty', 'products');
                if ($min < 1 || $max < $min) {
                    throw $this->invalidResponse('products');
                }
                $normalizedTiers[] = [
                    'min_qty' => $min,
                    'max_qty' => $max,
                    'unit_price' => $this->requiredDecimal($tier, 'unit_price', 'products'),
                ];
            }
        }

        $stock = $this->requiredInteger($service, 'stock', 'products');
        if ($stock < 0) {
            throw $this->invalidResponse('products');
        }

        return [
            'service_id' => $this->requiredString($service, 'service_id', 'products'),
            'name' => $this->requiredString($service, 'name', 'products'),
            'price' => $price,
            'stock' => $stock,
            'in_stock' => $service['in_stock'],
            'pricing' => $pricing,
            'orderable' => $service['orderable'],
            'price_tiers' => $normalizedTiers,
        ];
    }

    private function normalizeHistoricalOrder($order): array
    {
        if (!is_array($order) || $this->isList($order)) {
            throw $this->invalidResponse('order history');
        }

        $quantity = $this->requiredInteger($order, 'quantity', 'order history');
        if ($quantity < 1) {
            throw $this->invalidResponse('order history');
        }

        return [
            'order_id' => $this->requiredProviderOrderId($order, 'order history'),
            'service_id' => $this->requiredString($order, 'service_id', 'order history'),
            'service' => $this->requiredString($order, 'service', 'order history'),
            'quantity' => $quantity,
            'amount' => $this->requiredDecimal($order, 'amount', 'order history'),
            'status' => $this->requiredString($order, 'status', 'order history'),
            'created_at' => $this->requiredString($order, 'created_at', 'order history'),
            'delivered_products' => $this->requiredStringList(
                $order,
                'delivered_products',
                'order history'
            ),
        ];
    }

    private function requiredString(array $payload, string $key, string $context): string
    {
        if (!array_key_exists($key, $payload)
            || !is_string($payload[$key])
            || trim($payload[$key]) === '') {
            throw $this->invalidResponse($context);
        }

        return trim($payload[$key]);
    }

    private function requiredInteger(array $payload, string $key, string $context): int
    {
        if (!array_key_exists($key, $payload) || !is_int($payload[$key])) {
            throw $this->invalidResponse($context);
        }

        return $payload[$key];
    }

    private function requiredProviderOrderId(array $payload, string $context): string
    {
        $orderId = $this->requiredString($payload, 'order_id', $context);
        if (strlen($orderId) > 128 || !preg_match('/^[A-Za-z0-9-]+$/', $orderId)) {
            throw $this->invalidResponse($context);
        }

        return $orderId;
    }

    private function requiredDecimal(array $payload, string $key, string $context): string
    {
        if (!array_key_exists($key, $payload)) {
            throw $this->invalidResponse($context);
        }

        return $this->decimalValue($payload[$key], $context);
    }

    private function decimalValue($value, string $context): string
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value))
            || !is_numeric($value)
            || (float) $value < 0
            || !is_finite((float) $value)) {
            throw $this->invalidResponse($context);
        }

        $decimal = number_format((float) $value, 8, '.', '');

        return rtrim(rtrim($decimal, '0'), '.') ?: '0';
    }

    private function requiredStringList(array $payload, string $key, string $context): array
    {
        if (!array_key_exists($key, $payload) || !is_array($payload[$key])) {
            throw $this->invalidResponse($context);
        }

        $values = [];
        foreach ($payload[$key] as $value) {
            if (!is_string($value) || trim($value) === '') {
                throw $this->invalidResponse($context);
            }
            $values[] = trim($value);
        }

        return $values;
    }

    private function invalidResponse(string $context): WarzoneApiException
    {
        return new WarzoneApiException('Warzone returned an invalid '.$context.' response.');
    }

    private function ambiguousOrderResponse(int $status = null): WarzoneApiException
    {
        return new WarzoneApiException(
            'Warzone order result is uncertain and requires reconciliation.',
            $status,
            true
        );
    }

    private function httpFailure(int $status, bool $retryable): WarzoneApiException
    {
        if ($status === 400) {
            $message = 'Warzone rejected the request.';
        } elseif ($status === 401 || $status === 403) {
            $message = 'Warzone API credentials were rejected.';
        } elseif ($status === 404) {
            $message = 'The requested Warzone resource was not found.';
        } elseif ($status === 429) {
            $message = 'Warzone API rate limit was reached.';
        } elseif ($status === 503) {
            $message = 'Warzone service is temporarily unavailable.';
        } else {
            $message = 'Warzone API request failed with HTTP '.$status.'.';
        }

        return new WarzoneApiException($message, $status, false, $retryable);
    }

    private function configuredAttempts(string $key): int
    {
        return min(5, max(1, (int) config('services.warzone.'.$key, 3)));
    }

    private function configuredTimeout(string $key, float $default): float
    {
        return min(60.0, max(0.1, (float) config('services.warzone.'.$key, $default)));
    }

    private function baseUrl(): string
    {
        $baseUrl = rtrim(trim((string) config('services.warzone.base_url')), '/');
        $parts = parse_url($baseUrl);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new WarzoneApiException('Warzone API base URL is invalid.');
        }

        return $baseUrl;
    }

    private function sleepBeforeRetry(ResponseInterface $response = null): void
    {
        $milliseconds = min(5000, max(0, (int) config('services.warzone.retry_delay_ms', 250)));
        if ($response && $response->hasHeader('Retry-After')) {
            $retryAfter = trim($response->getHeaderLine('Retry-After'));
            if (ctype_digit($retryAfter)) {
                $milliseconds = min(5000, max($milliseconds, (int) $retryAfter * 1000));
            }
        }
        call_user_func($this->sleeper, $milliseconds);
    }

    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}
