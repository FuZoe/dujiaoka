<?php

namespace App\Service;

use Carbon\Carbon;
use DateTimeZone;
use Illuminate\Support\Collection;

/**
 * Centralises time-based payment availability rules.
 *
 * The pause window is evaluated in the configured local timezone rather than
 * the PHP process timezone. This keeps the rule deterministic when workers
 * and web requests run with different system timezone settings.
 */
class PaymentAvailability
{
    private const DEFAULT_TIMEZONE = 'Asia/Shanghai';
    private const DEFAULT_PAUSE_START = '22:00';
    private const DEFAULT_PAUSE_END = '06:00';

    /**
     * Determine whether a configured gateway represents WeChat payment.
     *
     * @param mixed $gateway Pay model, array, or pay_check string
     */
    public function isWechat($gateway): bool
    {
        if (is_string($gateway)) {
            $check = $gateway;
        } elseif (is_array($gateway)) {
            $check = isset($gateway['pay_check']) ? $gateway['pay_check'] : '';
        } elseif (is_object($gateway)) {
            $check = isset($gateway->pay_check) ? $gateway->pay_check : '';
        } else {
            $check = '';
        }

        $check = strtolower(trim((string) $check));
        if ($check === '') {
            return false;
        }

        // Keep this broad enough for legacy WeChat gateways while leaving
        // unrelated channels (such as a combined Stripe gateway) untouched.
        return strpos($check, 'wechat') !== false
            || strpos($check, 'weixin') !== false
            || strpos($check, 'wepay') !== false
            || strpos($check, 'wx') === 0
            || in_array($check, ['wescan', 'mwx', 'pswx', 'vwx', 'payjswescan'], true);
    }

    /**
     * Return true while the WeChat pause window is active.
     */
    public function isWechatPaused(?Carbon $at = null): bool
    {
        if (!$this->pauseEnabled()) {
            return false;
        }

        $local = ($at ?: Carbon::now())->copy()->setTimezone($this->timezone());
        $minute = ((int) $local->hour * 60) + (int) $local->minute;
        $start = $this->parseMinute((string) config(
            'services.newzoe_pay.wechat_pause_start',
            self::DEFAULT_PAUSE_START
        ), self::DEFAULT_PAUSE_START);
        $end = $this->parseMinute((string) config(
            'services.newzoe_pay.wechat_pause_end',
            self::DEFAULT_PAUSE_END
        ), self::DEFAULT_PAUSE_END);

        // Equal boundaries are treated as an empty window, which is safer
        // than accidentally disabling a payment method all day after a typo.
        if ($start === $end) {
            return false;
        }

        // The default window crosses midnight: [22:00, 24:00) U [00:00, 06:00).
        if ($start > $end) {
            return $minute >= $start || $minute < $end;
        }

        return $minute >= $start && $minute < $end;
    }

    /**
     * Return whether a gateway can be shown or entered at the supplied time.
     *
     * @param mixed $gateway Pay model, array, or pay_check string
     */
    public function isAvailable($gateway, ?Carbon $at = null): bool
    {
        return !$this->isWechat($gateway) || !$this->isWechatPaused($at);
    }

    /**
     * Filter a payment collection while preserving the collection API used by
     * PayService. Arrays are accepted as well to keep the helper easy to test.
     *
     * @param Collection|array $gateways
     * @return Collection|array
     */
    public function filter($gateways, ?Carbon $at = null)
    {
        if ($gateways instanceof Collection) {
            return $gateways->filter(function ($gateway) use ($at) {
                return $this->isAvailable($gateway, $at);
            })->values();
        }

        if (is_array($gateways)) {
            return array_values(array_filter($gateways, function ($gateway) use ($at) {
                return $this->isAvailable($gateway, $at);
            }));
        }

        return $gateways;
    }

    private function pauseEnabled(): bool
    {
        return (bool) config('services.newzoe_pay.wechat_night_pause_enabled', true);
    }

    private function timezone(): DateTimeZone
    {
        $name = (string) config('services.newzoe_pay.schedule_timezone', self::DEFAULT_TIMEZONE);

        try {
            return new DateTimeZone($name ?: self::DEFAULT_TIMEZONE);
        } catch (\Exception $exception) {
            return new DateTimeZone(self::DEFAULT_TIMEZONE);
        }
    }

    private function parseMinute(string $value, string $fallback): int
    {
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) {
            $value = $fallback;
        }

        return ((int) substr($value, 0, 2) * 60) + (int) substr($value, 3, 2);
    }
}
