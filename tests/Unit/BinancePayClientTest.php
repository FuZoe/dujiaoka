<?php

namespace Tests\Unit;

use App\Models\BinancePaySetting;
use App\Service\BinancePayClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Tests\TestCase;

class BinancePayClientTest extends TestCase
{
    public function test_credentials_are_encrypted_and_signed_read_only_request_uses_proxy(): void
    {
        config([
            'services.binance_pay.base_url' => 'https://api.binance.test',
            'services.binance_pay.proxy' => 'http://PROXY:17895',
            'services.binance_pay.recv_window' => 5000,
        ]);
        $setting = new BinancePaySetting();
        $setting->setApiKey('READ_ONLY_KEY')->setApiSecret('TOP_SECRET');

        $this->assertStringNotContainsString('READ_ONLY_KEY', $setting->api_key_encrypted);
        $this->assertStringNotContainsString('TOP_SECRET', $setting->api_secret_encrypted);

        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->withArgs(function ($method, $url, array $options) {
            $query = $options['query'];
            $signature = $query['signature'];
            unset($query['signature']);
            $expected = hash_hmac(
                'sha256',
                http_build_query($query, '', '&', PHP_QUERY_RFC3986),
                'TOP_SECRET'
            );

            return $method === 'GET'
                && $url === 'https://api.binance.test/sapi/v1/pay/transactions'
                && $options['headers']['X-MBX-APIKEY'] === 'READ_ONLY_KEY'
                && $options['proxy'] === 'http://PROXY:17895'
                && hash_equals($expected, $signature);
        })->andReturn(new Response(200, [], json_encode([
            'success' => true,
            'code' => '000000',
            'data' => [],
        ])));

        $this->assertSame([], (new BinancePayClient($http))->transactions($setting, 1000, 2000, 10));
    }

    public function test_network_failure_does_not_expose_signed_request_details(): void
    {
        $setting = new BinancePaySetting();
        $setting->setApiKey('READ_ONLY_KEY')->setApiSecret('TOP_SECRET');
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->andThrow(new ConnectException(
            'cURL error for https://api.binance.test/?signature=SHOULD_NOT_LEAK',
            new \GuzzleHttp\Psr7\Request('GET', 'https://api.binance.test')
        ));

        try {
            (new BinancePayClient($http))->transactions($setting);
            $this->fail('Expected request failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Binance Pay API request failed.', $exception->getMessage());
            $this->assertStringNotContainsString('signature=', $exception->getMessage());
        }
    }
}
