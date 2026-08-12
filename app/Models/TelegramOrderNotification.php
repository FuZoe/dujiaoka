<?php

namespace App\Models;

class TelegramOrderNotification extends BaseModel
{
    protected $table = 'telegram_order_notifications';

    protected $fillable = ['order_id', 'event_key', 'status', 'next_part'];

    protected $casts = ['sent_at' => 'datetime'];

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
