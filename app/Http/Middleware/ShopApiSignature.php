<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Authenticate the owner API without putting a bearer secret in URLs.
 *
 * The signature covers the exact request path, query string and raw body so
 * callers cannot alter an order request after signing it. A short-lived nonce
 * cache prevents a valid request from being replayed.
 */
class ShopApiSignature
{
    public function handle(Request $request, Closure $next)
    {
        $key = trim((string) config('services.shop_api.key', ''));
        $secret = (string) config('services.shop_api.secret', '');

        if ($key === '' || $secret === '') {
            return $this->error('api_not_configured', 'The shop API is not configured.', 503);
        }

        $providedKey = (string) $request->header('X-Api-Key', '');
        if ($providedKey === '' || !hash_equals($key, $providedKey)) {
            return $this->error('unauthorized', 'API credentials are invalid.', 401);
        }

        $timestamp = (string) $request->header('X-Api-Timestamp', '');
        $nonce = (string) $request->header('X-Api-Nonce', '');
        $signature = strtolower(trim((string) $request->header('X-Api-Signature', '')));
        $tolerance = max(30, (int) config('services.shop_api.timestamp_tolerance', 300));

        if (!preg_match('/^[0-9]{1,12}$/', $timestamp)
            || abs(time() - (int) $timestamp) > $tolerance
        ) {
            return $this->error('invalid_timestamp', 'The request timestamp is outside the allowed window.', 401);
        }

        if (!preg_match('/^[A-Za-z0-9._~-]{8,128}$/', $nonce)) {
            return $this->error('invalid_nonce', 'A valid request nonce is required.', 401);
        }

        if (!preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return $this->error('invalid_signature', 'The request signature is invalid.', 401);
        }

        $expected = hash_hmac('sha256', self::canonical($request, $timestamp, $nonce), $secret);
        if (!hash_equals($expected, $signature)) {
            return $this->error('invalid_signature', 'The request signature is invalid.', 401);
        }

        $replayKey = 'shop-api:nonce:'.sha1($key.'|'.$nonce);
        if (!Cache::add($replayKey, 1, $tolerance + 30)) {
            return $this->error('request_replayed', 'The request nonce has already been used.', 401);
        }

        $request->attributes->set('shop_api_key', $key);

        return $next($request);
    }

    public static function canonical(Request $request, string $timestamp, string $nonce): string
    {
        $path = $request->getPathInfo();
        // getQueryString() is rebuilt from parsed parameters and may reorder
        // them. Sign the raw query from REQUEST_URI so the client and server
        // cover the exact bytes sent over the wire.
        $rawUri = (string) $request->server->get('REQUEST_URI', '');
        $question = strpos($rawUri, '?');
        $query = $question === false
            ? $request->getQueryString()
            : substr($rawUri, $question + 1);

        if ($query !== null && $query !== '') {
            $path .= '?'.$query;
        }

        return strtoupper($request->getMethod())."\n"
            .$path."\n"
            .$timestamp."\n"
            .$nonce."\n"
            .$request->getContent();
    }

    private function error(string $code, string $message, int $status)
    {
        return response()->json([
            'ok' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
