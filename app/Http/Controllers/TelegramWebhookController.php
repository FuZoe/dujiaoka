<?php

namespace App\Http\Controllers;

use App\Service\TelegramBindingService;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function webhook(Request $request, TelegramBindingService $bindings)
    {
        $expected = (string) config('services.telegram.webhook_secret');
        $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token');
        if ($expected === '' || !hash_equals($expected, $provided)) {
            return response()->json(['ok' => false], 403);
        }

        $message = $request->input('message', []);
        $text = trim((string) ($message['text'] ?? ''));
        if (!preg_match('/^\/start(?:@[A-Za-z0-9_]+)? bind_([A-Za-z0-9_-]{43})$/', $text, $matches)) {
            return response()->json(['ok' => true]);
        }

        $bindings->consume($matches[1], $message['chat'] ?? [], $message['from'] ?? []);
        return response()->json(['ok' => true]);
    }

    public function health()
    {
        return response()->json([
            'ok' => true,
            'bot_username_configured' => trim((string) config('services.telegram.bot_username')) !== '',
            'webhook_secret_configured' => trim((string) config('services.telegram.webhook_secret')) !== '',
        ]);
    }
}
