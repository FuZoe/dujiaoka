<?php

namespace Tests\Unit;

use App\Service\ShopApiClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ShopApiClientTest extends TestCase
{
    public function test_it_signs_the_exact_api_path_and_json_body(): void
    {
        config([
            'services.shop_api.base_url' => 'https://shop.example.test',
            'services.shop_api.key' => 'API_KEY',
            'services.shop_api.secret' => 'API_SECRET',
        ]);

        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')
            ->once()
            ->withArgs(function (string $method, string $url, array $options) {
                $headers = $options['headers'];
                $body = '{"email":"customer@example.test","product_id":12}';
                $canonical = $method."\n"
                    .'/api/v1/orders'."\n"
                    .$headers['X-Api-Timestamp']."\n"
                    .$headers['X-Api-Nonce']."\n"
                    .$body;

                return $method === 'POST'
                    && $url === 'https://shop.example.test/api/v1/orders'
                    && $options['body'] === $body
                    && $headers['X-Api-Key'] === 'API_KEY'
                    && hash_hmac('sha256', $canonical, 'API_SECRET') === $headers['X-Api-Signature']
                    && $headers['Idempotency-Key'] === 'telegram-order-1';
            })
            ->andReturn(new Response(201, [], json_encode([
                'ok' => true,
                'data' => ['order' => ['id' => 'ORDER123']],
            ])));

        $result = (new ShopApiClient($http))->createOrder([
            'email' => 'customer@example.test',
            'product_id' => 12,
        ], 'telegram-order-1');

        $this->assertSame('ORDER123', $result['order']['id']);
    }

    public function test_it_surfaces_api_errors_without_leaking_credentials(): void
    {
        config([
            'services.shop_api.base_url' => 'https://shop.example.test',
            'services.shop_api.key' => 'API_KEY',
            'services.shop_api.secret' => 'API_SECRET',
        ]);

        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')
            ->once()
            ->andReturn(new Response(401, [], json_encode([
                'ok' => false,
                'error' => ['code' => 'unauthorized', 'message' => 'API credentials are invalid.'],
            ])));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('API credentials are invalid.');
        (new ShopApiClient($http))->products();
    }

    public function test_it_fetches_payment_methods_from_the_dedicated_endpoint(): void
    {
        config([
            'services.shop_api.base_url' => 'https://shop.example.test',
            'services.shop_api.key' => 'API_KEY',
            'services.shop_api.secret' => 'API_SECRET',
        ]);

        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')
            ->once()
            ->withArgs(function (string $method, string $url): bool {
                return $method === 'GET'
                    && $url === 'https://shop.example.test/api/v1/payment-methods';
            })
            ->andReturn(new Response(200, [], json_encode([
                'ok' => true,
                'data' => ['payment_methods' => [['code' => 'binancepay']]],
            ])));

        $result = (new ShopApiClient($http))->paymentMethods();

        $this->assertSame('binancepay', $result['payment_methods'][0]['code']);
    }
}
