<?php

namespace App\Service;

use App\Jobs\TelegramPrivateMessage;
use App\Models\Customer;
use App\Models\Order;
use App\Models\TelegramBinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

            // A first-time bot checkout provisions a synthetic Customer so
            // orders have a durable owner before the shopper creates a web
            // account. When that shopper later consumes a binding link, move
            // those orders to the logged-in account before releasing the
            // synthetic chat owner. Real customer accounts keep their own
            // historical orders when a chat is rebound.
            $previousOwner = Customer::query()
                ->where('telegram_chat_id', $chatId)
                ->where('id', '!=', $customer->getKey())
                ->lockForUpdate()
                ->first();

            $hasChatColumn = Schema::hasColumn('orders', 'telegram_chat_id');
            // Chat-tagged bot orders belong to the Telegram identity. Moving
            // them does not move unrelated browser orders, whose chat column
            // remains null.
            $orders = $hasChatColumn
                ? Order::query()->where('telegram_chat_id', $chatId)
                : null;
            if ($previousOwner && $this->isProvisionedCustomer($previousOwner, $chatId)) {
                $orders = $orders ?: Order::query()->whereRaw('1 = 0');
                $orders->orWhere(function ($query) use ($previousOwner) {
                    $query->where('customer_id', $previousOwner->getKey());
                    // Older bot orders were created before the Telegram chat
                    // column existed. Their synthetic email still identifies
                    // the same chat and is safe to migrate because the prior
                    // owner is verified as a provisioned Telegram account.
                    $query->orWhere('email', $previousOwner->email);
                });
            }
            if ($orders) {
                $updates = ['customer_id' => $customer->getKey()];
                if ($hasChatColumn) {
                    $updates['telegram_chat_id'] = $chatId;
                }
                $orders->update($updates);
            }

            if ($previousOwner) {
                $previousOwner->forceFill([
                    'telegram_chat_id' => null,
                    'telegram_username' => null,
                    'telegram_name' => null,
                    'telegram_bound_at' => null,
                ])->save();
            }

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

    private function isProvisionedCustomer(Customer $customer, string $chatId): bool
    {
        return $customer->isTelegramProvisionedFor($chatId);
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
