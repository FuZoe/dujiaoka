<?php

namespace Tests\Unit;

use App\Http\Controllers\Pay\AlipayController;
use Tests\TestCase;

class AlipayConfigTest extends TestCase
{
    public function test_sdk_logs_use_the_laravel_storage_volume(): void
    {
        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($privateKey);
        openssl_pkey_export($privateKey, $privatePem);

        $controller = new AlipayController();
        $reflection = new \ReflectionClass($controller);
        $method = $reflection->getMethod('buildConfig');
        $method->setAccessible(true);

        $config = $method->invoke($controller, (object) [
            'merchant_id' => '2021006189693471',
            'merchant_key' => 'alipay-public-key-placeholder',
            'merchant_pem' => $this->keyBody($privatePem),
        ], false);

        $this->assertSame(storage_path('logs/yansongda-pay.log'), $config['log']['file']);
        $this->assertSame('daily', $config['log']['type']);
    }

    private function keyBody(string $key): string
    {
        return preg_replace('/-----[^-]+-----|\s+/', '', trim($key));
    }
}
