<?php

namespace App\Support;

/**
 * Recognises a bank charge that is a paper check ("CHECK 2658") even when the
 * bank left its check_number field empty. On 2026-09-08 Plaid labelled such a
 * charge "Jc Licht" with no check number, so Hive treated it as a JC Licht card
 * purchase: it took that vendor and, two days later, the sync overwrote the
 * pending $425.16 JC Licht purchase with it, expense link and all.
 */
final class CheckCharge
{
    /**
     * The check number in a bank description such as "CHECK 2658",
     * "CHECK # 2658" or "CHECK NO. 2658", or null when it is not a check.
     * Deposits ("Deposit by Check", "MOBILE CHECK DEPOSIT") do not match.
     */
    public static function numberFrom(?string $description): ?string
    {
        if ($description === null || $description === '') {
            return null;
        }

        return preg_match('/^\s*CHECK\s*(?:NO\.?|NUMBER|#)?\s*0*(\d{2,})\b/i', $description, $m) === 1
            ? $m[1]
            : null;
    }

    /**
     * Whether a charge with this check number and description is a paper check.
     */
    public static function isCheck(?string $checkNumber, ?string $description): bool
    {
        return (trim((string) $checkNumber) !== '' && ctype_digit(trim((string) $checkNumber)))
            || self::numberFrom($description) !== null;
    }
}
