<?php

namespace App\Service;

use App\Models\BinancePaySetting;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use RuntimeException;
use Throwable;

class BinancePayClient
{
    /** @var ClientInterface */
    private $client;

    public function __construct(ClientInterface $client = null)
    {
        $this->client = $client ?: new Client();
    }

    public function transactions(
        BinancePaySetting $setting,
        int $startTime = null,
        int $endTime = null,
        int $limit = 100
    ): array {
        $apiKey = $setting->getApiKey();
        $apiSecret = $setting->getApiSecret();
        if ($apiKey === '' || $apiSecret === '') {
            throw new RuntimeException('Binance Pay credentials are not configured.');
        }

        $params = [
            'timestamp' => (int) round(microtime(true) * 1000),
            'recvWindow' => min(60000, max(1000, (int) config('services.binance_pay.recv_window', 5000))),
            'limit' => min(100, max(1, $limit)),
        ];
        if ($startTime !== null) {
            $params['startTime'] = $startTime;
        }
        if ($endTime !== null) {
            $params['endTime'] = $endTime;
        }
        $params['signature'] = hash_hmac('sha256', http_build_query($params, '', '&', PHP_QUERY_RFC3986), $apiSecret);

        $options = [
            'connect_timeout' => (float) config('services.binance_pay.connect_timeout', 8),
            'timeout' => (float) config('services.binance_pay.timeout', 20),
            'headers' => ['X-MBX-APIKEY' => $apiKey],
            'query' => $params,
        ];
        $proxy = trim((string) config('services.binance_pay.proxy'));
        if ($proxy !== '') {
            $options['proxy'] = $proxy;
        }

        try {
            $response = $this->client->request(
                'GET',
                rtrim((string) config('services.binance_pay.base_url', 'https://api.binance.com'), '/')
                    .'/sapi/v1/pay/transactions',
                $options
            );
            $payload = json_decode((string) $response->getBody(), true);
        } catch (Throwable $exception) {
            $detail = '';
            if (method_exists($exception, 'getResponse') && $exception->getResponse()) {
                $response = $exception->getResponse();
                $body = json_decode((string) $response->getBody(), true);
                if (is_array($body)) {
                    $detail = trim((string) (($body['code'] ?? '').' '.($body['msg'] ?? $body['message'] ?? '')));
                }
                $detail = 'HTTP '.$response->getStatusCode().($detail !== '' ? ': '.$detail : '');
            }
            throw new RuntimeException('Binance Pay API request failed'.($detail !== '' ? ': '.mb_substr($detail, 0, 180) : '.'));
        }

        if (!is_array($payload) || empty($payload['success']) || !isset($payload['data']) || !is_array($payload['data'])) {
            $detail = is_array($payload)
                ? trim((string) (($payload['code'] ?? '').' '.($payload['msg'] ?? $payload['message'] ?? '')))
                : '';
            throw new RuntimeException('Binance Pay returned an invalid response'.($detail !== '' ? ': '.mb_substr($detail, 0, 180) : '.'));
        }

        return $payload['data'];
    }

    public function testConnection(BinancePaySetting $setting): array
    {
        try {
            $transactions = $this->transactions(
                $setting,
                (int) round((microtime(true) - 86400) * 1000),
                (int) round(microtime(true) * 1000),
                1
            );

            return [
                'ok' => true,
                'message' => 'Binance Pay connection succeeded.',
                'transaction_count' => count($transactions),
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }
}
