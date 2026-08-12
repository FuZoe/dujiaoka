<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Customer extends Authenticatable
{
    use Notifiable;

    protected $fillable = ['email', 'password'];

    protected $hidden = ['password', 'remember_token', 'telegram_chat_id'];

    protected $casts = ['telegram_bound_at' => 'datetime'];

    public function orders()
    {
        return $this->hasMany(Order::class, 'customer_id');
    }

    public function bindings()
    {
        return $this->hasMany(TelegramBinding::class, 'customer_id');
    }

    public function isTelegramBound(): bool
    {
        return preg_match('/^[1-9][0-9]*$/', (string) $this->telegram_chat_id) === 1;
    }
}
