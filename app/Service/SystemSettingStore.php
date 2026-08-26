<?php

namespace App\Service;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Keeps storefront settings durable while retaining Redis as the fast path.
 *
 * The original application stored this payload only in Cache. A Redis reset
 * or a partial admin-form submission could erase credentials such as the
 * Telegram bot token. The database row becomes authoritative after migration.
 */
class SystemSettingStore
{
    private const SETTING_KEY = 'system-setting';

    /** @var array|null */
    private static $settings;

    /** @var array */
    private static $reportedFailures = [];

    public static function all(): array
    {
        if (is_array(self::$settings)) {
            return self::$settings;
        }

        $cached = self::readCache();
        if ($cached) {
            self::$settings = $cached;

            return self::$settings;
        }

        $stored = self::readPersistent();
        self::$settings = is_array($stored) ? $stored : [];
        if (is_array($stored)) {
            self::putCache(self::$settings);
        }

        return self::$settings;
    }

    public static function get(string $key, $default = null)
    {
        $settings = self::all();
        if (array_key_exists($key, $settings) && $settings[$key] !== null && $settings[$key] !== '') {
            return $settings[$key];
        }

        // A cache payload from an older process may be incomplete. Consult
        // the persistent copy only for a missing field, keeping normal page
        // reads on Redis and making credential loss self-healing.
        $stored = self::readPersistent();
        if (is_array($stored) && array_key_exists($key, $stored) && $stored[$key] !== null && $stored[$key] !== '') {
            self::$settings = array_merge($settings, $stored);
            self::putCache(self::$settings);

            return $stored[$key];
        }

        // Environment configuration lives outside the release tree and is a
        // durable fallback if an older cache payload lacks the bot token.
        if ($key === 'telegram_bot_token') {
            $token = trim((string) env('TELEGRAM_BOT_TOKEN', ''));
            if ($token !== '') {
                return $token;
            }
        }

        return $default;
    }

    public static function save(array $input): array
    {
        // Preserve fields omitted by a partial Dcat request. Explicit empty
        // values still replace existing values, so clearing a field works.
        $settings = array_merge(self::all(), $input);
        self::$settings = $settings;

        self::writePersistent($settings);
        self::putCache($settings);

        return $settings;
    }

    public static function bootstrap(array $defaults): array
    {
        $stored = self::readPersistent();
        $cached = is_array($stored) ? [] : self::readCache();
        $settings = array_merge($defaults, is_array($stored) ? $stored : $cached);

        self::$settings = $settings;
        if (!is_array($stored) && $cached) {
            self::writePersistent($settings);
        }
        self::putCache($settings);

        return $settings;
    }

    private static function readPersistent(): ?array
    {
        try {
            if (!Schema::hasTable('newzoe_system_settings')) {
                return null;
            }

            $value = DB::table('newzoe_system_settings')
                ->where('setting_key', self::SETTING_KEY)
                ->value('setting_value');
            if (!is_string($value) || $value === '') {
                return null;
            }

            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable $exception) {
            self::reportFailure('read persistent settings', $exception);

            return null;
        }
    }

    private static function writePersistent(array $settings): void
    {
        try {
            if (!Schema::hasTable('newzoe_system_settings')) {
                return;
            }

            DB::table('newzoe_system_settings')->updateOrInsert(
                ['setting_key' => self::SETTING_KEY],
                [
                    'setting_value' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        } catch (Throwable $exception) {
            self::reportFailure('write persistent settings', $exception);
        }
    }

    private static function readCache(): array
    {
        try {
            $settings = Cache::get(self::SETTING_KEY, []);

            return is_array($settings) ? $settings : [];
        } catch (Throwable $exception) {
            self::reportFailure('read settings cache', $exception);

            return [];
        }
    }

    private static function putCache(array $settings): void
    {
        try {
            Cache::forever(self::SETTING_KEY, $settings);
        } catch (Throwable $exception) {
            self::reportFailure('write settings cache', $exception);
        }
    }

    private static function reportFailure(string $operation, Throwable $exception): void
    {
        if (isset(self::$reportedFailures[$operation])) {
            return;
        }

        self::$reportedFailures[$operation] = true;
        Log::warning('System settings fallback failed.', [
            'operation' => $operation,
            'exception' => get_class($exception),
        ]);
    }
}
