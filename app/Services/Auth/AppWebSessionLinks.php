<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * One-time, 60-second hand-offs from the Hive Contractors app into a web
 * page, already signed in: the app opens the resulting signed URL in a
 * react-native-webview, the web route logs the token's user into the web
 * session (see WebSessionLoginController), and redirects to the path the
 * app asked for.
 *
 * The nonce is the single-use guard (Cache::pull() below is atomic: the
 * first request to read it also deletes it, so a second open — a reload, a
 * cached link, a replay — never logs in a second time); the signed route
 * itself only confirms the URL wasn't tampered with in transit, the same
 * role it plays for calls.recording.signed and sms.media.public.
 */
class AppWebSessionLinks
{
    /** How long a hand-off nonce stays valid, in seconds — matches the signed route's own expiry. */
    public const TTL = 60;

    /** Create a nonce for $user to land on $path, valid for self::TTL seconds. */
    public function issue(User $user, string $path): string
    {
        $nonce = Str::random(40);

        Cache::put($this->cacheKey($nonce), ['user_id' => $user->id, 'path' => $path], self::TTL);

        return $nonce;
    }

    /**
     * Redeem a nonce exactly once. Returns null for an unknown, expired, or
     * already-used nonce.
     *
     * @return array{user_id: int, path: string}|null
     */
    public function consume(string $nonce): ?array
    {
        $payload = Cache::pull($this->cacheKey($nonce));

        return is_array($payload) ? $payload : null;
    }

    private function cacheKey(string $nonce): string
    {
        return 'app_web_session:'.$nonce;
    }
}
