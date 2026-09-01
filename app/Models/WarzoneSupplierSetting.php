<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class WarzoneSupplierSetting extends BaseModel
{
    protected $table = 'warzone_supplier_settings';

    protected $fillable = [
        'goods_id',
        'service_id',
        'unit_cost_usd',
        'enabled',
        'connection_test_ok',
        'last_balance_usd',
        'last_supplier_stock',
        'last_supplier_orderable',
        'last_product_price_usd',
        'last_snapshot_at',
        'last_tested_at',
        'last_error',
    ];

    protected $hidden = [
        'api_key_encrypted',
        'tested_credentials_hash',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'connection_test_ok' => 'boolean',
        'last_supplier_orderable' => 'boolean',
        'last_snapshot_at' => 'datetime',
        'last_tested_at' => 'datetime',
    ];

    public static function currentForGoods(int $goodsId): self
    {
        return static::query()->firstOrCreate(
            ['goods_id' => $goodsId],
            [
                'service_id' => 'S_01',
                'unit_cost_usd' => '0.4000',
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
        if (!is_string($this->api_key_encrypted) || $this->api_key_encrypted === '') {
            return '';
        }

        try {
            return Crypt::decryptString($this->api_key_encrypted);
        } catch (DecryptException $exception) {
            return '';
        }
    }

    public function hasApiKey(): bool
    {
        return $this->getApiKey() !== '';
    }

    public function credentialFingerprint(): string
    {
        if (!$this->hasApiKey() || trim((string) $this->service_id) === '') {
            return '';
        }

        return hash('sha256', $this->getApiKey()."\0".trim((string) $this->service_id));
    }

    public function hasSuccessfulConnectionTest(): bool
    {
        $fingerprint = $this->credentialFingerprint();

        return (bool) $this->connection_test_ok
            && $fingerprint !== ''
            && is_string($this->tested_credentials_hash)
            && strlen($this->tested_credentials_hash) === 64
            && hash_equals($this->tested_credentials_hash, $fingerprint);
    }

    public function connectionTestStatus(): string
    {
        if (!$this->hasApiKey()) {
            return 'not_configured';
        }
        if ($this->hasSuccessfulConnectionTest()) {
            return 'passed';
        }
        if ((bool) $this->connection_test_ok) {
            return 'stale';
        }

        return 'not_tested';
    }

    public function hasValidUnitCost(): bool
    {
        return is_numeric($this->unit_cost_usd)
            && bccomp((string) $this->unit_cost_usd, '0', 4) === 1;
    }

    public function isReady(): bool
    {
        return (bool) $this->enabled
            && trim((string) $this->service_id) !== ''
            && $this->hasValidUnitCost()
            && $this->hasSuccessfulConnectionTest();
    }

    public function markConnectionTest(bool $ok, string $error = ''): self
    {
        $this->connection_test_ok = $ok;
        $this->tested_credentials_hash = $ok ? $this->credentialFingerprint() : null;
        $this->last_tested_at = now();
        $this->last_error = $ok ? null : $this->safeError($error);

        return $this;
    }

    public function recordSnapshot(string $balanceUsd, array $service): self
    {
        $this->last_balance_usd = $balanceUsd;
        $this->last_supplier_stock = (int) ($service['stock'] ?? 0);
        $this->last_supplier_orderable = (bool) ($service['orderable'] ?? false);
        $this->last_product_price_usd = isset($service['price']) ? (string) $service['price'] : null;
        $this->last_snapshot_at = now();
        $this->last_error = null;

        return $this;
    }

    public function purchases()
    {
        return $this->hasMany(WarzoneSupplierPurchase::class, 'setting_id');
    }

    private function resetConnectionTest(): void
    {
        $this->connection_test_ok = false;
        $this->tested_credentials_hash = null;
        $this->last_tested_at = null;
    }

    private function safeError(string $error): ?string
    {
        $error = trim($error);

        return $error === '' ? null : mb_substr($error, 0, 1000);
    }
}
