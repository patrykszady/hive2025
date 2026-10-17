<?php

namespace App\Services\Auth;

use App\Enums\OneTimeLoginCodeResult;
use App\Mail\EmailVerificationCode;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Email one-time-code sign-in: generate, mail, and verify a 6-digit code
 * cached only under the recipient's email address (otp_login_code:{email},
 * otp_login_attempts:{email}, otp_login_last_sent:{email},
 * otp_login_has_sent:{email}).
 *
 * Single source of truth for both the web App\Livewire\Auth\OneTimeCodeLogin
 * component and the app's POST /api/app/v1/login{,/code} endpoints, so the
 * two surfaces log in with identical rules, cache keys, and messages.
 */
class OneTimeLoginCodes
{
    /** Wrong guesses allowed before the code is thrown away. */
    public const MAX_ATTEMPTS = 5;

    /** How long a mailed code stays valid, in seconds. */
    public const CODE_TTL = 600;

    /** Minimum gap between two codes mailed to the same address, in seconds. */
    public const RESEND_COOLDOWN = 60;

    /**
     * Generate a code, mail it, and store it for verification. Returns false
     * when the mail could not go out — nothing is stored and no cooldown
     * starts, so the caller may retry immediately.
     */
    public function issue(User $user): bool
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        try {
            Mail::to($user->email)->send(new EmailVerificationCode($code));
        } catch (\Throwable $exception) {
            return false;
        }

        Cache::put($this->codeCacheKey($user->email), $code, self::CODE_TTL);
        Cache::forget($this->attemptsCacheKey($user->email));
        Cache::put($this->hasSentCacheKey($user->email), true, 86400);
        Cache::put($this->lastSentCacheKey($user->email), now()->timestamp, self::RESEND_COOLDOWN);

        return true;
    }

    /**
     * Verify a guessed code against the one mailed to this address, tracking
     * wrong guesses and throwing the code away after MAX_ATTEMPTS.
     */
    public function verify(string $email, string $code): OneTimeLoginCodeResult
    {
        $expected = Cache::get($this->codeCacheKey($email));

        if (! is_string($expected) || $expected === '') {
            return OneTimeLoginCodeResult::Expired;
        }

        if (! hash_equals($expected, $code)) {
            $attempts = (int) Cache::get($this->attemptsCacheKey($email), 0) + 1;

            if ($attempts >= self::MAX_ATTEMPTS) {
                Cache::forget($this->codeCacheKey($email));
                Cache::forget($this->attemptsCacheKey($email));

                return OneTimeLoginCodeResult::TooManyAttempts;
            }

            Cache::put($this->attemptsCacheKey($email), $attempts, self::CODE_TTL);

            return OneTimeLoginCodeResult::Invalid;
        }

        Cache::forget($this->codeCacheKey($email));
        Cache::forget($this->attemptsCacheKey($email));

        return OneTimeLoginCodeResult::Valid;
    }

    /**
     * A code is currently waiting for this address (mailed and not yet
     * expired or used up) — lets the web component jump straight to the
     * verify step on mount.
     */
    public function hasPendingCode(string $email): bool
    {
        $code = Cache::get($this->codeCacheKey($email));

        return is_string($code) && $code !== '';
    }

    public function withinCooldown(string $email): bool
    {
        return $this->remainingCooldown($email) > 0;
    }

    /** Seconds remaining before another code may be mailed to this address. */
    public function remainingCooldown(string $email): int
    {
        $lastSent = Cache::get($this->lastSentCacheKey($email));

        if (! is_int($lastSent)) {
            return 0;
        }

        return max(0, self::RESEND_COOLDOWN - (now()->timestamp - $lastSent));
    }

    private function codeCacheKey(string $email): string
    {
        return 'otp_login_code:' . $email;
    }

    private function attemptsCacheKey(string $email): string
    {
        return 'otp_login_attempts:' . $email;
    }

    private function lastSentCacheKey(string $email): string
    {
        return 'otp_login_last_sent:' . $email;
    }

    private function hasSentCacheKey(string $email): string
    {
        return 'otp_login_has_sent:' . $email;
    }
}
