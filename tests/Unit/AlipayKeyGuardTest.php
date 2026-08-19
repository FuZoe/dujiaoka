<?php

namespace Tests\Unit;

use App\Service\AlipayKeyGuard;
use PHPUnit\Framework\TestCase;

class AlipayKeyGuardTest extends TestCase
{
    public function test_it_detects_the_public_key_derived_from_the_application_private_key(): void
    {
        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($privateKey);

        openssl_pkey_export($privateKey, $privatePem);
        $details = openssl_pkey_get_details($privateKey);
        $applicationPublicKey = $details['key'];

        $guard = new AlipayKeyGuard();

        $this->assertTrue($guard->isApplicationPublicKey($applicationPublicKey, $privatePem));
        $this->assertTrue($guard->isApplicationPublicKey(
            $this->keyBody($applicationPublicKey),
            $this->keyBody($privatePem)
        ));
    }

    public function test_it_accepts_an_unrelated_alipay_public_key(): void
    {
        $applicationPrivateKey = openssl_pkey_new(['private_key_bits' => 2048]);
        $alipayKey = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($applicationPrivateKey, $applicationPrivatePem);
        $alipayDetails = openssl_pkey_get_details($alipayKey);

        $this->assertFalse((new AlipayKeyGuard())->isApplicationPublicKey(
            $alipayDetails['key'],
            $applicationPrivatePem
        ));
    }

    private function keyBody(string $key): string
    {
        return preg_replace('/-----[^-]+-----|\s+/', '', trim($key));
    }
}
