<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Settings stored in the database and changed from the admin panel. They
 * are applied over config/ on every request (one cached read), so the rest
 * of the app keeps reading config() as before.
 */
class Settings
{
    private const CACHE_KEY = 'settings:all';

    /**
     * Setting => the config value it overrides.
     */
    private const APPLIED = [
        'site_name' => 'app.name',
        'pdf_max_mb' => 'uploads.pdf_max_kb',
        'uploads_per_day' => 'uploads.per_day',
        'uploads_pending' => 'uploads.pending',
        'assistant_daily_limit' => 'assistant.daily_limit',
    ];

    /**
     * @return array<string, string|null>
     */
    public static function all(): array
    {
        try {
            /** @var array<string, string|null> $settings */
            $settings = Cache::rememberForever(self::CACHE_KEY, fn () => DB::table('settings')->pluck('value', 'key')->all());

            return $settings;
        } catch (Throwable) {
            // Before the first migration there is no settings table.
            return [];
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::all()[$key] ?? $default;
    }

    /**
     * @param  array<string, string|int|null>  $values
     */
    public static function put(array $values): void
    {
        $now = now();

        DB::table('settings')->upsert(
            array_map(fn (string $key, $value) => ['key' => $key, 'value' => $value === null ? null : (string) $value, 'updated_at' => $now], array_keys($values), $values),
            ['key'],
            ['value', 'updated_at'],
        );

        Cache::forget(self::CACHE_KEY);
        self::apply();
    }

    /**
     * Puts the stored settings over the config defaults.
     */
    public static function apply(): void
    {
        foreach (self::all() as $key => $value) {
            if ($value === null || $value === '' || ! isset(self::APPLIED[$key])) {
                continue;
            }

            config([self::APPLIED[$key] => match ($key) {
                'pdf_max_mb' => (int) $value * 1024,
                'uploads_per_day', 'uploads_pending', 'assistant_daily_limit' => (int) $value,
                default => $value,
            }]);
        }
    }
}
