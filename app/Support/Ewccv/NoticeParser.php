<?php

namespace App\Support\Ewccv;

use Illuminate\Support\Str;

/**
 * Reads an EWCCV email (the account's address receives them; live.com
 * forwards them to the certificates mailbox) and says what it is about:
 * a cancellation, reinstatement or non-renewal notice for a tracked policy,
 * a tracking confirmation, a sign-in link, or something else — plus the
 * policy numbers and employer it names, so the notice can be pinned to the
 * right workers comp document.
 *
 * EWCCV's templates are not documented; the matching is deliberately loose
 * (any policy number we track that appears in the text counts) and every
 * unmatched notice is reported rather than dropped.
 */
class NoticeParser
{
    public const KIND_CANCELLATION = 'cancellation';

    public const KIND_REINSTATEMENT = 'reinstatement';

    public const KIND_NONRENEWAL = 'nonrenewal';

    public const KIND_CONFIRMATION = 'confirmation';

    public const KIND_SIGN_IN = 'sign_in';

    public const KIND_OTHER = 'other';

    /** The kinds worth a text message to the admins. */
    public const ALERT_KINDS = [self::KIND_CANCELLATION, self::KIND_NONRENEWAL, self::KIND_REINSTATEMENT];

    /**
     * @param  array<string, mixed>  $message  a Nylas message (subject, from, body/snippet, date, id)
     */
    public static function isFromEwccv(array $message): bool
    {
        $from = Str::lower(json_encode($message['from'] ?? []));
        $subject = Str::lower((string) ($message['subject'] ?? ''));

        return str_contains($from, 'ewccv.com')
            || str_contains($from, 'ncci.com')
            || str_contains($subject, 'ewccv')
            || str_contains($subject, 'coverage verification')
            || str_contains($subject, 'workers compensation coverage');
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public static function kind(array $message): string
    {
        $subject = Str::lower((string) ($message['subject'] ?? ''));
        $text = Str::lower(self::text($message));

        if (str_contains($subject, 'sign in') || str_contains($subject, 'log in')) {
            return self::KIND_SIGN_IN;
        }

        // The subject decides first. EWCCV's subscription confirmation says
        // "notification of any cancellation/reinstatement" in its body, and on
        // 2026-10-12 a body-only reading of it sent five false "reinstated"
        // texts; a body counts only with the phrasing of an actual notice.
        if (preg_match('/received your .*subscription|thank you for subscribing|subscription (confirmation|confirmed|created)/', $subject)) {
            return self::KIND_CONFIRMATION;
        }
        if (preg_match('/reinstat/', $subject)) {
            return self::KIND_REINSTATEMENT;
        }
        if (preg_match('/non-?\s?renew|not (be )?renewed/', $subject)) {
            return self::KIND_NONRENEWAL;
        }
        if (preg_match('/cancel/', $subject)) {
            return self::KIND_CANCELLATION;
        }
        if (preg_match('/thank you for subscribing|successfully subscribed|now tracking|you are tracking/', $text)) {
            return self::KIND_CONFIRMATION;
        }
        if (preg_match('/(has been|was|is) reinstated|reinstatement (has been|was) reported|notice of reinstatement/', $text)) {
            return self::KIND_REINSTATEMENT;
        }
        if (preg_match('/will not be renewed|(has|was) not (been )?renewed|notice of non-?\s?renewal|non-?renewal (has been|was) reported/', $text)) {
            return self::KIND_NONRENEWAL;
        }
        if (preg_match('/(has been|was|is) cancell?ed|cancellation (has been|was) reported|notice of cancellation/', $text)) {
            return self::KIND_CANCELLATION;
        }

        return self::KIND_OTHER;
    }

    /**
     * Policy numbers the message names, letters and digits only (EWCCV writes
     * "WC5-39S-342438-046" as "WC539S342438046").
     *
     * @param  array<string, mixed>  $message
     * @param  list<string>  $known  policy numbers we track, to find even when unlabeled
     * @return list<string>
     */
    public static function policyNumbers(array $message, array $known = []): array
    {
        $text = self::text($message);
        $found = [];

        if (preg_match_all('/policy\s*(?:number|no\.?|#)?\s*[:\-]?\s*([A-Z0-9][A-Z0-9.\-\/]{3,34})/i', $text, $m)) {
            foreach ($m[1] as $raw) {
                $norm = self::normalize($raw);
                if (strlen($norm) >= 5) {
                    $found[] = $norm;
                }
            }
        }

        $flat = self::normalize($text);
        foreach ($known as $number) {
            $norm = self::normalize($number);
            if (strlen($norm) >= 5 && str_contains($flat, $norm)) {
                $found[] = $norm;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public static function employer(array $message): ?string
    {
        if (preg_match('/(?:employer|insured)(?:\s*name)?\s*:\s*([^\n\r]{3,90})/i', self::text($message), $m)) {
            return trim($m[1]);
        }

        return null;
    }

    public static function normalize(string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public static function text(array $message): string
    {
        $body = (string) ($message['body'] ?? $message['snippet'] ?? '');
        $body = preg_replace('#<(br|/p|/div|/tr|/li)[^>]*>#i', "\n", $body) ?? $body;
        $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5);

        return trim((string) preg_replace('/[ \t]+/', ' ', $body));
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public static function excerpt(array $message, int $length = 240): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/', ' ', self::text($message))), $length, '…');
    }
}
