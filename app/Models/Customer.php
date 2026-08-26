<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Customer extends Authenticatable
{
    use Notifiable;

    /**
     * Reserved address space used for customers provisioned by the Telegram
     * checkout. These addresses are never valid web-account identifiers.
     */
    public const TELEGRAM_SYNTHETIC_DOMAIN = 'telegram.newzoe.cloud';

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

    public static function isReservedTelegramEmail(string $email): bool
    {
        return preg_match(
            '/@'.preg_quote(self::TELEGRAM_SYNTHETIC_DOMAIN, '/').'$/i',
            strtolower(trim($email))
        ) === 1;
    }

    /**
     * A synthetic customer is trusted only after the application provisioned
     * and bound it to the same Telegram chat. This prevents a pre-registered
     * reserved address from capturing a future bot order.
     */
    public function isTelegramProvisionedFor(string $chatId): bool
    {
        $chatId = trim($chatId);
        if (!preg_match('/^[1-9][0-9]*$/', $chatId)) {
            return false;
        }

        $email = strtolower(trim((string) $this->email));
        $pattern = '/^telegram-'.preg_quote($chatId, '/').'(?:-[a-z0-9]{8,40})?@'
            .preg_quote(self::TELEGRAM_SYNTHETIC_DOMAIN, '/').'$/i';

        // The chat column is the durable marker used by older deployments;
        // telegram_bound_at was added later and may be null on legacy rows.
        return (string) $this->telegram_chat_id === $chatId
            && preg_match($pattern, $email) === 1;
    }
}
