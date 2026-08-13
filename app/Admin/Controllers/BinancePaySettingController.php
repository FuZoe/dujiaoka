<?php

namespace App\Admin\Controllers;

use App\Admin\Forms\BinancePaySettingForm;
use App\Models\BinancePaySetting;
use App\Models\Pay;
use App\Service\BinancePayClient;
use Illuminate\Support\Facades\DB;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Widgets\Card;
use Illuminate\Http\JsonResponse;

class BinancePaySettingController extends AdminController
{
    public function index(Content $content)
    {
        return $content
            ->title(admin_trans('pay.binance.title'))
            ->description(admin_trans('pay.binance.description'))
            ->body(new Card(new BinancePaySettingForm()));
    }

    public function test(BinancePayClient $client): JsonResponse
    {
        $setting = BinancePaySetting::current();

        try {
            $result = $client->testConnection($setting);
            if (empty($result['ok'])) {
                $this->disableChannel($setting, (string) ($result['message'] ?? ''));
                return response()->json([
                    'ok' => false,
                    'message' => (string) ($result['message'] ?? admin_trans('pay.binance.messages.test_failed')),
                ], 422);
            }

            $setting->markConnectionTest(true)->save();

            return response()->json([
                'ok' => true,
                'message' => (string) ($result['message'] ?? admin_trans('pay.binance.messages.test_ok')),
                'transaction_count' => (int) ($result['transaction_count'] ?? 0),
            ]);
        } catch (\Throwable $exception) {
            $this->disableChannel($setting, $exception->getMessage());
            return response()->json([
                'ok' => false,
                'message' => mb_substr($exception->getMessage(), 0, 180),
            ], 422);
        }
    }

    private function disableChannel(BinancePaySetting $setting, string $error): void
    {
        DB::transaction(function () use ($setting, $error) {
            $setting->enabled = false;
            $setting->markConnectionTest(false, $error)->save();
            Pay::query()->where('pay_check', 'binancepay')->update([
                'is_open' => Pay::STATUS_CLOSE,
                'updated_at' => now(),
            ]);
        });
    }
}
