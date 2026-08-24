<?php

namespace Tests\Unit;

use App\Http\Middleware\ShopApiSignature;
use Illuminate\Http\Request;
use Tests\TestCase;

class ShopApiSignatureTest extends TestCase
{
    public function test_canonical_string_covers_query_and_raw_body(): void
    {
        $request = Request::create('/api/v1/orders?b=2&a=1', 'POST', [], [], [], [], '{"product_id":12}');
        // Symfony rebuilds query parameters when Request::create() is used;
        // emulate the raw URL that a real HTTP server supplies.
        $request->server->set('REQUEST_URI', '/api/v1/orders?b=2&a=1');

        $this->assertSame(
            "POST\n/api/v1/orders?b=2&a=1\n1700000000\nnonce-1234\n{\"product_id\":12}",
            ShopApiSignature::canonical($request, '1700000000', 'nonce-1234')
        );
    }

    public function test_valid_signature_is_accepted_and_nonce_is_single_use(): void
    {
        config([
            'services.shop_api.key' => 'TEST_API_KEY',
            'services.shop_api.secret' => 'TEST_API_SECRET',
            'services.shop_api.timestamp_tolerance' => 300,
        ]);

        $timestamp = (string) time();
        $nonce = 'nonce-valid-1234';
        $request = Request::create('/api/v1/products', 'GET');
        $request->headers->set('X-Api-Key', 'TEST_API_KEY');
        $request->headers->set('X-Api-Timestamp', $timestamp);
        $request->headers->set('X-Api-Nonce', $nonce);
        $request->headers->set(
            'X-Api-Signature',
            hash_hmac('sha256', ShopApiSignature::canonical($request, $timestamp, $nonce), 'TEST_API_SECRET')
        );

        $middleware = new ShopApiSignature();
        $first = $middleware->handle($request, function () {
            return response()->json(['ok' => true]);
        });
        $this->assertSame(200, $first->getStatusCode());

        $replay = Request::create('/api/v1/products', 'GET');
        foreach ($request->headers->all() as $name => $values) {
            $replay->headers->set($name, $values);
        }
        $second = $middleware->handle($replay, function () {
            return response()->json(['ok' => true]);
        });
        $this->assertSame(401, $second->getStatusCode());
        $this->assertSame('request_replayed', $second->getData(true)['error']['code']);
    }

    public function test_stale_timestamp_is_rejected(): void
    {
        config([
            'services.shop_api.key' => 'TEST_API_KEY',
            'services.shop_api.secret' => 'TEST_API_SECRET',
            'services.shop_api.timestamp_tolerance' => 30,
        ]);

        $timestamp = (string) (time() - 120);
        $nonce = 'nonce-stale-1234';
        $request = Request::create('/api/v1/products', 'GET');
        $request->headers->set('X-Api-Key', 'TEST_API_KEY');
        $request->headers->set('X-Api-Timestamp', $timestamp);
        $request->headers->set('X-Api-Nonce', $nonce);
        $request->headers->set(
            'X-Api-Signature',
            hash_hmac('sha256', ShopApiSignature::canonical($request, $timestamp, $nonce), 'TEST_API_SECRET')
        );

        $response = (new ShopApiSignature())->handle($request, function () {
            return response()->json(['ok' => true]);
        });

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('invalid_timestamp', $response->getData(true)['error']['code']);
    }
}
