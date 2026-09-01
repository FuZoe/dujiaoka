<?php

namespace Tests\Unit;

use App\Exceptions\WarzoneApiException;
use App\Models\WarzoneSupplierSetting;
use App\Service\WarzoneShopClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Tests\TestCase;

class WarzoneShopClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.warzone.base_url' => 'https://warzone.test',
            'services.warzone.get_attempts' => 3,
            'services.warzone.post_safe_attempts' => 3,
            'services.warzone.retry_delay_ms' => 0,
        ]);
    }

    public function test_snapshot_normalizes_balance_and_configured_service(): void
    {
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->twice()->withArgs(function ($method, $url, array $options) {
            return $method === 'GET'
                && in_array($url, [
                    'https://warzone.test/api/v1/me',
                    'https://warzone.test/api/v1/products',
                ], true)
                && $options['headers']['X-API-Key'] === 'WAR_TEST_SECRET'
                && $options['http_errors'] === false;
        })->andReturn(
            new Response(200, [], json_encode([
                'chat_id' => 12345,
                'first_name' => 'Alex',
                'wallet_balance' => 4.8,
            ])),
            new Response(200, [], json_encode([
                'services' => [$this->servicePayload()],
            ]))
        );

        $snapshot = (new WarzoneShopClient($http))->snapshot($this->setting());

        $this->assertSame('4.8', $snapshot['balance_usd']);
        $this->assertSame('S_01', $snapshot['service']['service_id']);
        $this->assertSame('0.4', $snapshot['service']['price']);
        $this->assertSame(4552, $snapshot['service']['stock']);
    }

    public function test_order_returns_only_validated_delivery_data(): void
    {
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->withArgs(function ($method, $url, array $options) {
            return $method === 'POST'
                && $url === 'https://warzone.test/api/v1/order'
                && $options['json'] === ['service_id' => 'S_01', 'quantity' => 2]
                && $options['headers']['X-API-Key'] === 'WAR_TEST_SECRET';
        })->andReturn(new Response(200, [], json_encode([
            'success' => true,
            'order_id' => 'ORD-04621-9a84',
            'service_id' => 'S_01',
            'quantity' => 2,
            'unit_price' => 0.4,
            'total_cost' => 0.8,
            'new_balance' => 4.0,
            'products' => ['PRODUCT_ONE', 'PRODUCT_TWO'],
            'untrusted_extra' => 'ignored',
        ])));

        $result = (new WarzoneShopClient($http))->order($this->setting(), 2);

        $this->assertSame('ORD-04621-9a84', $result['order_id']);
        $this->assertSame('0.8', $result['total_cost']);
        $this->assertSame(['PRODUCT_ONE', 'PRODUCT_TWO'], $result['products']);
        $this->assertArrayNotHasKey('untrusted_extra', $result);
    }

    public function test_post_retries_only_documented_safe_statuses(): void
    {
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->twice()->andReturn(
            new Response(503, [], json_encode(['error' => 'maintenance'])),
            new Response(200, [], json_encode([
                'success' => true,
                'order_id' => 'ORD-04621-9a84',
                'service_id' => 'S_01',
                'quantity' => 1,
                'unit_price' => 0.4,
                'total_cost' => 0.4,
                'new_balance' => 4.4,
                'products' => ['PRODUCT_ONE'],
            ]))
        );
        $sleepCalls = 0;
        $client = new WarzoneShopClient($http, function () use (&$sleepCalls): void {
            $sleepCalls++;
        });

        $this->assertSame('ORD-04621-9a84', $client->order($this->setting(), 1)['order_id']);
        $this->assertSame(1, $sleepCalls);
    }

    public function test_post_server_error_is_ambiguous_and_sensitive_body_is_ignored(): void
    {
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->andReturn(new Response(500, [], json_encode([
            'error' => 'WAR_TEST_SECRET PRODUCT_ONE',
            'products' => ['PRODUCT_ONE'],
        ])));

        try {
            (new WarzoneShopClient($http))->order($this->setting(), 1);
            $this->fail('Expected an ambiguous order result.');
        } catch (WarzoneApiException $exception) {
            $this->assertTrue($exception->isAmbiguous());
            $this->assertFalse($exception->isRetryable());
            $this->assertSame(500, $exception->statusCode());
            $this->assertStringNotContainsString('WAR_TEST_SECRET', $exception->getMessage());
            $this->assertStringNotContainsString('PRODUCT_ONE', $exception->getMessage());
        }
    }

    public function test_post_network_error_is_ambiguous_without_leaking_exception_details(): void
    {
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->andThrow(new ConnectException(
            'Connection failed with WAR_TEST_SECRET and PRODUCT_ONE',
            new Request('POST', 'https://warzone.test/api/v1/order')
        ));

        try {
            (new WarzoneShopClient($http))->order($this->setting(), 1);
            $this->fail('Expected an ambiguous order result.');
        } catch (WarzoneApiException $exception) {
            $this->assertTrue($exception->isAmbiguous());
            $this->assertNull($exception->statusCode());
            $this->assertStringNotContainsString('WAR_TEST_SECRET', $exception->getMessage());
            $this->assertStringNotContainsString('PRODUCT_ONE', $exception->getMessage());
        }
    }

    public function test_get_retries_server_error_and_validates_response(): void
    {
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->twice()->andReturn(
            new Response(500, [], 'not json'),
            new Response(200, [], json_encode([
                'chat_id' => 12345,
                'first_name' => 'Alex',
                'wallet_balance' => 2.15,
            ]))
        );
        $sleeps = 0;
        $client = new WarzoneShopClient($http, function () use (&$sleeps): void {
            $sleeps++;
        });

        $this->assertSame('2.15', $client->me($this->setting())['wallet_balance']);
        $this->assertSame(1, $sleeps);
    }

    public function test_invalid_success_response_does_not_expose_products(): void
    {
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->andReturn(new Response(200, [], json_encode([
            'success' => true,
            'order_id' => 'ORD-04621-9a84',
            'service_id' => 'S_01',
            'quantity' => 1,
            'unit_price' => 0.4,
            'total_cost' => 0.4,
            'new_balance' => 4.4,
            'products' => ['PRODUCT_ONE', 'PRODUCT_TWO'],
        ])));

        try {
            (new WarzoneShopClient($http))->order($this->setting(), 1);
            $this->fail('Expected invalid response exception.');
        } catch (WarzoneApiException $exception) {
            $this->assertTrue($exception->isAmbiguous());
            $this->assertStringNotContainsString('PRODUCT_ONE', $exception->getMessage());
            $this->assertStringNotContainsString('PRODUCT_TWO', $exception->getMessage());
        }
    }

    public function test_post_invalid_json_is_ambiguous(): void
    {
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->andReturn(new Response(200, [], 'truncated-json'));

        try {
            (new WarzoneShopClient($http))->order($this->setting(), 1);
            $this->fail('Expected an ambiguous order result.');
        } catch (WarzoneApiException $exception) {
            $this->assertTrue($exception->isAmbiguous());
            $this->assertSame(200, $exception->statusCode());
        }
    }

    public function test_exhausted_safe_post_retries_are_not_ambiguous(): void
    {
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->times(3)->andReturn(new Response(503, [], '{}'));

        try {
            (new WarzoneShopClient($http, static function (): void {
            }))->order($this->setting(), 1);
            $this->fail('Expected supplier unavailable exception.');
        } catch (WarzoneApiException $exception) {
            $this->assertFalse($exception->isAmbiguous());
            $this->assertTrue($exception->isRetryable());
            $this->assertSame(503, $exception->statusCode());
        }
    }

    public function test_unrecognized_post_error_status_is_ambiguous(): void
    {
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->andReturn(new Response(422, [], '{}'));

        try {
            (new WarzoneShopClient($http))->order($this->setting(), 1);
            $this->fail('Expected an ambiguous order result.');
        } catch (WarzoneApiException $exception) {
            $this->assertTrue($exception->isAmbiguous());
            $this->assertSame(422, $exception->statusCode());
        }
    }

    public function test_order_history_and_lookup_are_normalized(): void
    {
        $history = $this->historyOrderPayload();
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->twice()->andReturn(
            new Response(200, [], json_encode([
                'success' => true,
                'page' => 1,
                'limit' => 50,
                'total_orders' => 1,
                'total_pages' => 1,
                'orders' => [$history],
            ])),
            new Response(200, [], json_encode([
                'success' => true,
                'order' => $history,
            ]))
        );
        $client = new WarzoneShopClient($http);

        $this->assertSame('PRODUCT_ONE', $client->orders($this->setting())['orders'][0]['delivered_products'][0]);
        $this->assertSame('ORD-04621-9a84', $client->orderById($this->setting(), 'ORD-04621-9a84')['order_id']);
    }

    private function setting(): WarzoneSupplierSetting
    {
        $setting = new WarzoneSupplierSetting([
            'goods_id' => 16,
            'service_id' => 'S_01',
            'unit_cost_usd' => '0.4000',
            'enabled' => true,
        ]);
        $setting->setApiKey('WAR_TEST_SECRET');

        return $setting;
    }

    private function servicePayload(): array
    {
        return [
            'service_id' => 'S_01',
            'name' => 'Time-sensitive product',
            'price' => 0.4,
            'stock' => 4552,
            'in_stock' => true,
            'pricing' => 'tiered',
            'orderable' => true,
            'price_tiers' => [
                ['min_qty' => 1, 'max_qty' => 10000, 'unit_price' => 0.4],
            ],
        ];
    }

    private function historyOrderPayload(): array
    {
        return [
            'order_id' => 'ORD-04621-9a84',
            'service_id' => 'S_01',
            'service' => 'Time-sensitive product',
            'quantity' => 1,
            'amount' => 0.4,
            'status' => 'success',
            'created_at' => '2026-08-20 14:02:11',
            'delivered_products' => ['PRODUCT_ONE'],
        ];
    }
}
