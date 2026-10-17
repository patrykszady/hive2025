<?php

namespace App\Enums;

/**
 * Outcome of App\Services\Auth\OneTimeLoginCodes::verify(), shared verbatim
 * by the web OneTimeCodeLogin component and the app's login endpoint so
 * both surfaces report identical wording for identical failures.
 */
enum OneTimeLoginCodeResult: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Expired = 'expired';
    case TooManyAttempts = 'too_many_attempts';

    /**
     * User-facing message for a failed verification. Empty for Valid, which
     * never surfaces a message.
     */
    public function message(): string
    {
        return match ($this) {
            self::Valid => '',
            self::Invalid => 'Invalid verification code.',
            self::Expired => 'That code has expired. Please request a new one.',
            self::TooManyAttempts => 'Too many wrong codes. Please request a new one.',
        };
    }
}
