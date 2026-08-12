<?php

namespace App\Service;

use App\Jobs\TelegramPrivateMessage;
use App\Models\Customer;
use App\Models\TelegramBinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TelegramBindingService
{
    public const TOKEN_TTL_MINUTES = 15;

    public function issue(Customer $customer): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        TelegramBinding::query()
            ->where('customer_id', $customer->getKey())
            ->whereNull('consumed_at')
            ->update([
                'consumed_at' => now(),
                'failure_reason' => 'replaced',
                'updated_at' => now(),
            ]);

        $binding = TelegramBinding::query()->create([
            'customer_id' => $customer->getKey(),
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(self::TOKEN_TTL_MINUTES),
        ]);

        return [$binding, $token];
    }

    public function consume(string $token, array $chat, array $from): array
    {
        $result = DB::transaction(function () use ($token, $chat, $from) {
            $binding = TelegramBinding::query()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if (!$binding || $binding->consumed_at || $binding->expires_at->isPast()) {
                return ['status' => 'invalid'];
            }

            if (($chat['type'] ?? '') !== 'private'
                || !preg_match('/^[1-9][0-9]*$/', (string) ($chat['id'] ?? ''))
                || (string) ($from['id'] ?? '') !== (string) ($chat['id'] ?? '')
            ) {
                $binding->consumed_at = now();
                $binding->failure_reason = 'private_chat_required';
                $binding->save();
                return ['status' => 'private_chat_required'];
            }

            $chatId = (string) $chat['id'];
            $customer = Customer::query()->lockForUpdate()->find($binding->customer_id);
            if (!$customer) {
                $binding->consumed_at = now();
                $binding->failure_reason = 'customer_missing';
                $binding->save();
                return ['status' => 'invalid'];
            }

            Customer::query()
                ->where('telegram_chat_id', $chatId)
                ->where('id', '!=', $customer->getKey())
                ->update([
                    'telegram_chat_id' => null,
                    'telegram_username' => null,
                    'telegram_name' => null,
                    'telegram_bound_at' => null,
                    'updated_at' => now(),
                ]);

            $customer->telegram_chat_id = null;
            $customer->save();
            $customer->telegram_chat_id = $chatId;
            $customer->telegram_username = Str::limit((string) ($from['username'] ?? ''), 64, '');
            $customer->telegram_name = Str::limit(trim(
                (string) ($from['first_name'] ?? '').' '.(string) ($from['last_name'] ?? '')
            ), 190, '');
            $customer->telegram_bound_at = now();
            $customer->save();

            $binding->consumed_at = now();
            $binding->failure_reason = null;
            $binding->save();

            return [
                'status' => 'bound',
                'customer_id' => $customer->getKey(),
                'chat_id' => $chatId,
            ];
        });

        if (($result['status'] ?? '') === 'bound') {
            TelegramPrivateMessage::dispatch(
                $result['chat_id'],
                "Telegram 绑定成功\n\n您现在会在此私聊接收自己的订单状态通知。"
            );
            unset($result['chat_id']);
        }

        return $result;
    }

    public function unbind(Customer $customer): void
    {
        $customer->forceFill([
            'telegram_chat_id' => null,
            'telegram_username' => null,
            'telegram_name' => null,
            'telegram_bound_at' => null,
        ])->save();

        TelegramBinding::query()
            ->where('customer_id', $customer->getKey())
            ->whereNull('consumed_at')
            ->update([
                'consumed_at' => now(),
                'failure_reason' => 'unbound',
                'updated_at' => now(),
            ]);
    }
}
