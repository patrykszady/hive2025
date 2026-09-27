<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Ported from jpeterson-design's app/Models/PlatformSetting.php (itself
 * ported from gsc's, minus the BelongsToSite trait — this app is
 * single-tenant, so `key` alone is unique). This app has no
 * App\Models\Concerns\FlushesOnce trait (jpeterson-design's exists to bust
 * a request-memoised once() read elsewhere on that app, e.g. Socials::
 * links()); nothing here reads a platform setting through once(), so that
 * trait and its Once::flush() call are not ported.
 */
class PlatformSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'encrypted',
    ];

    /**
     * Values read this request, by key (null = no row) — a page reads a
     * handful. Kept on the app container rather than in a static, so a
     * fresh app (each test) starts empty.
     */
    protected static function memo(): \ArrayObject
    {
        $app = app();

        if (! $app->bound('platform_settings.memo')) {
            $app->instance('platform_settings.memo', new \ArrayObject);
        }

        return $app->make('platform_settings.memo');
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $memo = static::memo();

        if ($memo->offsetExists($key)) {
            return $memo[$key] ?? $default;
        }

        $row = static::where('key', $key)->first();

        if (! $row) {
            $memo[$key] = null;

            return $default;
        }

        try {
            return $memo[$key] = $row->value ?? $default;
        } catch (DecryptException $e) {
            // APP_KEY rotated or row written with a different key. Surface
            // the default instead of crashing the request; admin can re-save
            // the value to re-encrypt with the current key. Remove unreadable
            // rows so future requests do not repeatedly log/decrypt-fail.
            static::whereKey($row->getKey())->delete();

            Log::notice('PlatformSetting decryption failed; corrupted value was purged.', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return $default;
        }
    }

    public static function put(string $key, ?string $value): void
    {
        static::memo()->offsetUnset($key);

        if ($value === null || $value === '') {
            static::where('key', $key)->delete();

            return;
        }
        // If a prior row was encrypted with a rotated APP_KEY, updateOrCreate
        // tries to read the existing value (for dirty tracking) and throws
        // MAC invalid. Detect and drop it so the save can proceed.
        $existing = static::where('key', $key)->first();
        if ($existing) {
            try {
                $existing->value;
            } catch (DecryptException $e) {
                $existing->delete();
                $existing = null;
            }
        }
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
