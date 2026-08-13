<?php

namespace App\Admin\Forms;

use App\Models\BinancePaySetting;
use App\Models\Pay;
use Dcat\Admin\Widgets\Form;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BinancePaySettingForm extends Form
{
    public function handle(array $input)
    {
        $setting = BinancePaySetting::current();
        $apiKey = trim((string) ($input['api_key'] ?? ''));
        $apiSecret = trim((string) ($input['api_secret'] ?? ''));
        $receiverId = trim((string) ($input['receiver_binance_id'] ?? $setting->receiver_binance_id));
        $qrPayload = trim((string) ($input['receive_qr_payload'] ?? $setting->receive_qr_payload));
        $rate = trim((string) ($input['cny_per_usdt'] ?? $setting->cny_per_usdt));
        $requestedEnabled = (int) ($input['enabled'] ?? 0) === 1;
        $apiKeyChanged = $apiKey !== '' && !hash_equals($setting->getApiKey(), $apiKey);
        $apiSecretChanged = $apiSecret !== '' && !hash_equals($setting->getApiSecret(), $apiSecret);
        $credentialsChanged = $apiKeyChanged || $apiSecretChanged;
        [$validQrImage, $qrImagePath] = $this->resolveQrImagePath($setting, $input);

        if ($qrPayload !== '' && !BinancePaySetting::isOfficialReceiveUrl($qrPayload)) {
            return $this->response()->error(admin_trans('pay.binance.errors.invalid_qr_payload'));
        }
        if (!is_numeric($rate) || bccomp($rate, '0', 8) <= 0) {
            return $this->response()->error(admin_trans('pay.binance.errors.invalid_rate'));
        }
        if (!$validQrImage) {
            return $this->response()->error(admin_trans('pay.binance.errors.invalid_qr_image'));
        }
        if (mb_strlen($receiverId) > 64) {
            return $this->response()->error(admin_trans('pay.binance.errors.invalid_receiver_id'));
        }
        if ($requestedEnabled && $receiverId === '') {
            return $this->response()->error(admin_trans('pay.binance.errors.missing_receiver_id'));
        }
        if ($requestedEnabled && !$credentialsChanged && !$this->canEnable($setting, $receiverId, $qrPayload, $rate)) {
            return $this->response()->error(admin_trans('pay.binance.errors.incomplete'));
        }

        $oldQrImagePath = $setting->receive_qr_image_path;
        DB::transaction(function () use (
            $setting,
            $input,
            $apiKey,
            $apiSecret,
            $apiKeyChanged,
            $apiSecretChanged,
            $receiverId,
            $qrPayload,
            $qrImagePath,
            $rate,
            $requestedEnabled,
            $credentialsChanged
        ) {
            if ($apiKeyChanged) {
                $setting->setApiKey($apiKey);
            }
            if ($apiSecretChanged) {
                $setting->setApiSecret($apiSecret);
            }

            $setting->receiver_binance_id = $receiverId ?: null;
            $setting->receive_qr_payload = $qrPayload ?: null;
            $setting->receive_qr_image_path = $qrImagePath;
            $setting->cny_per_usdt = $rate;
            // A changed credential must be tested again before this channel can be exposed.
            $setting->enabled = $credentialsChanged ? false : $requestedEnabled;
            $setting->save();

            Pay::query()->where('pay_check', 'binancepay')->update([
                'is_open' => $setting->enabled ? Pay::STATUS_OPEN : Pay::STATUS_CLOSE,
                'pay_client' => Pay::PAY_CLIENT_ALL,
                'pay_handleroute' => '/pay/binance',
                'updated_at' => now(),
            ]);
        });

        if ($oldQrImagePath && $oldQrImagePath !== $qrImagePath) {
            Storage::disk('admin')->delete($oldQrImagePath);
        }

        $message = $credentialsChanged
            ? admin_trans('pay.binance.messages.credentials_saved')
            : admin_trans('pay.binance.messages.saved');

        return $this->response()->success($message)->refresh();
    }

    public function form()
    {
        $setting = BinancePaySetting::current();

        $this->html(view('admin.binance-pay.status', ['setting' => $setting]));
        $this->password('api_key', admin_trans('pay.binance.fields.api_key'))
            ->help(admin_trans('pay.binance.helps.api_key'));
        $this->password('api_secret', admin_trans('pay.binance.fields.api_secret'))
            ->help(admin_trans('pay.binance.helps.api_secret'));
        $this->text('receiver_binance_id', admin_trans('pay.binance.fields.receiver_binance_id'))
            ->rules('nullable|string|max:64')
            ->help(admin_trans('pay.binance.helps.receiver_binance_id'));
        $this->url('receive_qr_payload', admin_trans('pay.binance.fields.receive_qr_payload'))
            ->help(admin_trans('pay.binance.helps.receive_qr_payload'));
        $this->image('receive_qr_image', admin_trans('pay.binance.fields.receive_qr_image'))
            ->disk('admin')
            ->move('payment/binance-pay')
            ->uniqueName()
            ->autoUpload()
            ->retainable()
            ->removable()
            ->accept('jpg,jpeg,png,webp', 'image/jpeg,image/png,image/webp')
            ->maxSize(2048)
            ->help(admin_trans('pay.binance.helps.receive_qr_image'));
        $this->decimal('cny_per_usdt', admin_trans('pay.binance.fields.cny_per_usdt'))
            ->rules('required|numeric|min:0.00000001')
            ->help(admin_trans('pay.binance.helps.cny_per_usdt'));
        $this->switch('enabled', admin_trans('pay.binance.fields.enabled'));
        $this->html(view('admin.binance-pay.actions'));
        $this->confirm(
            admin_trans('pay.binance.confirm.title'),
            admin_trans('pay.binance.confirm.content')
        );
    }

    public function default()
    {
        $setting = BinancePaySetting::current();

        return [
            'api_key' => '',
            'api_secret' => '',
            'receiver_binance_id' => $setting->receiver_binance_id,
            'receive_qr_payload' => $setting->receive_qr_payload,
            'receive_qr_image' => $setting->receive_qr_image_path,
            'cny_per_usdt' => $setting->cny_per_usdt,
            'enabled' => (int) $setting->enabled,
        ];
    }

    private function canEnable(
        BinancePaySetting $setting,
        string $receiverId,
        string $qrPayload,
        string $rate
    ): bool {
        return $setting->hasCredentials()
            && $setting->hasSuccessfulConnectionTest()
            && $receiverId !== ''
            && BinancePaySetting::isOfficialReceiveUrl($qrPayload)
            && is_numeric($rate)
            && bccomp($rate, '0', 8) > 0;
    }

    private function resolveQrImagePath(BinancePaySetting $setting, array $input): array
    {
        $submitted = $input['receive_qr_image'] ?? $setting->receive_qr_image_path;
        if (is_array($submitted)) {
            $submitted = reset($submitted);
        }
        $path = str_replace('\\', '/', trim((string) $submitted));
        if ($path === '') {
            return [true, $setting->receive_qr_image_path ?: null];
        }

        if (!preg_match('#^payment/binance-pay/[A-Za-z0-9._-]+$#', $path)) {
            return [false, null];
        }

        return [Storage::disk('admin')->exists($path), $path];
    }

}
