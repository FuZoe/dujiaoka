<?php

namespace App\Models;

class TelegramBinding extends BaseModel
{
    protected $table = 'telegram_bindings';

    protected $fillable = ['customer_id', 'token_hash', 'expires_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
