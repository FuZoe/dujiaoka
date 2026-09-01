<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use JsonException;

class WarzoneSupplierPurchase extends BaseModel
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PURCHASING = 'purchasing';
    public const STATUS_STOCKED = 'stocked';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_AMBIGUOUS = 'ambiguous';
    public const STATUS_FAILED = 'failed';

    protected $table = 'warzone_supplier_purchases';

    protected $fillable = [
        'setting_id',
        'goods_id',
        'order_id',
        'order_sn',
        'provider_order_id',
        'service_id',
        'quantity',
        'status',
        'unit_cost_usd',
        'total_cost_usd',
        'products',
        'attempt_count',
        'last_error',
        'started_at',
        'stocked_at',
        'completed_at',
    ];

    protected $hidden = ['products_encrypted'];

    protected $casts = [
        'started_at' => 'datetime',
        'stocked_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function setting()
    {
        return $this->belongsTo(WarzoneSupplierSetting::class, 'setting_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function setProductsAttribute($products): void
    {
        $this->setProducts(is_array($products) ? $products : []);
    }

    public function getProductsAttribute(): array
    {
        return $this->getProducts();
    }

    public function setProducts(array $products): self
    {
        $normalized = [];
        foreach ($products as $product) {
            if (!is_string($product) || trim($product) === '') {
                throw new InvalidArgumentException('Supplier products must be non-empty strings.');
            }
            $normalized[] = trim($product);
        }
        $products = $normalized;
        $this->products_encrypted = empty($products)
            ? null
            : Crypt::encryptString(json_encode($products, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $this;
    }

    public function getProducts(): array
    {
        if (!is_string($this->products_encrypted) || $this->products_encrypted === '') {
            return [];
        }

        try {
            $products = json_decode(Crypt::decryptString($this->products_encrypted), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException | JsonException $exception) {
            return [];
        }

        if (!is_array($products)) {
            return [];
        }
        foreach ($products as $product) {
            if (!is_string($product) || trim($product) === '') {
                return [];
            }
        }

        return array_values($products);
    }

    public function hasProducts(): bool
    {
        return count($this->getProducts()) >= (int) $this->quantity;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_AMBIGUOUS,
            self::STATUS_FAILED,
        ], true);
    }
}
