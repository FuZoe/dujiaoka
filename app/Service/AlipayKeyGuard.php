<?php

namespace App\Service;

class AlipayKeyGuard
{
    /**
     * Detect the common configuration error where the application's public
     * key is stored in place of Alipay's public key.
     */
    public function isApplicationPublicKey(string $configuredKey, string $applicationPrivateKey): bool
    {
        $configured = $this->keyBody($configuredKey);
        if ($configured === '' || trim($applicationPrivateKey) === '') {
            return false;
        }

        foreach ($this->privateKeyCandidates($applicationPrivateKey) as $candidate) {
            $privateKey = @openssl_pkey_get_private($candidate);
            if ($privateKey === false) {
                continue;
            }

            $details = openssl_pkey_get_details($privateKey);
            if (is_resource($privateKey)) {
                openssl_free_key($privateKey);
            }

            if (is_array($details)
                && isset($details['key'])
                && hash_equals($configured, $this->keyBody((string) $details['key']))) {
                return true;
            }
        }

        return false;
    }

    private function keyBody(string $key): string
    {
        return preg_replace('/-----[^-]+-----|\s+/', '', trim($key)) ?: '';
    }

    private function privateKeyCandidates(string $key): array
    {
        $key = trim($key);
        if (strpos($key, '-----BEGIN') !== false) {
            return [$key];
        }

        $body = $this->keyBody($key);
        $wrapped = trim(chunk_split($body, 64, "\n"));

        return [
            "-----BEGIN PRIVATE KEY-----\n{$wrapped}\n-----END PRIVATE KEY-----",
            "-----BEGIN RSA PRIVATE KEY-----\n{$wrapped}\n-----END RSA PRIVATE KEY-----",
        ];
    }
}
