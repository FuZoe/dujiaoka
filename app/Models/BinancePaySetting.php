<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class BinancePaySetting extends BaseModel
{
    protected $table = 'binance_pay_settings';

    protected $fillable = [
        'receiver_binance_id',
        'receive_qr_payload',
        'receive_qr_image_path',
        'cny_per_usdt',
        'enabled',
        'connection_test_ok',
        'last_tested_at',
        'last_polled_at',
        'last_error',
    ];

    protected $hidden = [
        'api_key_encrypted',
        'api_secret_encrypted',
        'tested_credentials_hash',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'connection_test_ok' => 'boolean',
        'last_tested_at' => 'datetime',
        'last_polled_at' => 'datetime',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['id' => 1],
            [
                'receive_qr_payload' => config('services.binance_pay.receive_qr_payload'),
                'cny_per_usdt' => config('services.binance_pay.cny_per_usdt', '7.20000000'),
                'enabled' => false,
            ]
        );
    }

    public function setApiKey(string $apiKey): self
    {
        $apiKey = trim($apiKey);
        $this->api_key_encrypted = $apiKey === '' ? null : Crypt::encryptString($apiKey);
        $this->resetConnectionTest();

        return $this;
    }

    public function getApiKey(): string
    {
        return $this->decryptCredential($this->api_key_encrypted);
    }

    public function setApiSecret(string $apiSecret): self
    {
        $apiSecret = trim($apiSecret);
        $this->api_secret_encrypted = $apiSecret === '' ? null : Crypt::encryptString($apiSecret);
        $this->resetConnectionTest();

        return $this;
    }

    public function getApiSecret(): string
    {
        return $this->decryptCredential($this->api_secret_encrypted);
    }

    public function hasCredentials(): bool
    {
        return $this->getApiKey() !== '' && $this->getApiSecret() !== '';
    }

    public function credentialFingerprint(): string
    {
        if (!$this->hasCredentials()) {
            return '';
        }

        return hash('sha256', $this->getApiKey()."\0".$this->getApiSecret());
    }

    public function hasSuccessfulConnectionTest(): bool
    {
        return (bool) $this->connection_test_ok
            && is_string($this->tested_credentials_hash)
            && $this->tested_credentials_hash !== ''
            && hash_equals($this->tested_credentials_hash, $this->credentialFingerprint());
    }

    public static function isOfficialReceiveUrl(string $url): bool
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        return $scheme === 'https'
            && in_array($host, ['app.binance.com', 'www.binance.com', 'binance.com'], true)
            && empty($parts['user'])
            && empty($parts['pass'])
            && empty($parts['port'])
            && (bool) preg_match('#^/(?:uni-qr|qr)/[A-Za-z0-9_-]+/?$#', $path);
    }

    public function hasOfficialReceiveUrl(): bool
    {
        return static::isOfficialReceiveUrl((string) $this->receive_qr_payload);
    }

    public function hasReceiverId(): bool
    {
        return trim((string) $this->receiver_binance_id) !== '';
    }

    public function hasValidRate(): bool
    {
        $rate = (string) $this->cny_per_usdt;

        return is_numeric($rate) && bccomp($rate, '0', 8) > 0;
    }

    public function isReady(): bool
    {
        return (bool) $this->enabled
            && $this->hasCredentials()
            && $this->hasSuccessfulConnectionTest()
            && $this->hasReceiverId()
            && $this->hasOfficialReceiveUrl()
            && $this->hasValidRate();
    }

    public function markConnectionTest(bool $ok, string $error = ''): self
    {
        $this->connection_test_ok = $ok;
        $this->last_tested_at = now();
        $this->tested_credentials_hash = $ok ? $this->credentialFingerprint() : null;
        $this->last_error = $ok ? null : mb_substr($error, 0, 1000);

        return $this;
    }

    private function resetConnectionTest(): void
    {
        $this->connection_test_ok = false;
        $this->last_tested_at = null;
        $this->tested_credentials_hash = null;
    }

    private function decryptCredential($encrypted): string
    {
        if (!is_string($encrypted) || $encrypted === '') {
            return '';
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException $exception) {
            return '';
        }
    }
}
