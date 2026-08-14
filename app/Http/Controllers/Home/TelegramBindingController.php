<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\BaseController;
use App\Models\TelegramBinding;
use App\Service\TelegramBindingService;
use Illuminate\Http\Request;

class TelegramBindingController extends BaseController
{
    public function create(Request $request, TelegramBindingService $bindings)
    {
        $username = ltrim(trim((string) config('services.telegram.bot_username')), '@');
        if ($username === '') {
            return redirect()->route('account')
                ->with('status', 'Telegram 绑定服务正在配置中，请稍后再试。');
        }

        [$binding, $token] = $bindings->issue($request->user());
        $deepLink = 'https://t.me/'.$username.'?start=bind_'.$token;

        return $this->render(
            'account/telegram-bind',
            compact('binding', 'deepLink'),
            '绑定 Telegram'
        );
    }

    public function status(Request $request, int $id)
    {
        $binding = TelegramBinding::query()
            ->where('customer_id', $request->user()->getKey())
            ->whereKey($id)
            ->firstOrFail();
        $request->user()->refresh();

        $status = 'waiting';
        if ($request->user()->isTelegramBound() && $binding->consumed_at && !$binding->failure_reason) {
            $status = 'bound';
        } elseif ($binding->failure_reason) {
            $status = 'failed';
        } elseif ($binding->expires_at->isPast()) {
            $status = 'expired';
        }

        return response()->json(['status' => $status]);
    }

    public function destroy(Request $request, TelegramBindingService $bindings)
    {
        $bindings->unbind($request->user());
        return redirect()->route('account')->with('status', 'Telegram 已解绑。');
    }
}
