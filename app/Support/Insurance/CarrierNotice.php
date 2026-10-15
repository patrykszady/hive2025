<?php

namespace App\Support\Insurance;

use Illuminate\Support\Carbon;

/**
 * Reads an insurer's notice to a certificate holder: what happened to the
 * policy, from when, and which policy numbers it names.
 *
 * The certificate request asks every agent to list the company as
 * certificate holder with notice of cancellation, so carriers email their
 * cancellation, non-renewal and reinstatement notices to the certificates
 * mailbox, most often as a PDF letter. Every ACORD certificate quotes the
 * cancellation clause ("should any of the above described policies be
 * cancelled…"), so a certificate is never read as a notice, and a notice
 * counts only with the phrasing of an actual notice.
 */
class CarrierNotice
{
    public const CANCELLATION = 'cancellation';

    public const PENDING_CANCELLATION = 'pending_cancellation';

    public const NONRENEWAL = 'nonrenewal';

    public const REINSTATEMENT = 'reinstatement';

    /** The kinds that mean the coverage is gone or going. */
    public const ALERT_KINDS = [self::CANCELLATION, self::PENDING_CANCELLATION, self::NONRENEWAL];

    /** Phrases only a certificate carries. */
    protected const CERTIFICATE_MARKERS = [
        'certificate of liability insurance',
        'acord 25',
        'this certificate is issued as a matter of information only',
    ];

    /** Checked in this order: a reinstatement letter mentions the cancellation it undoes. */
    protected const PATTERNS = [
        self::REINSTATEMENT => '/\breinstat(?:ed|ement)\b|rescission of (?:the )?cancell?ation|cancell?ation (?:has been |is )?rescinded|withdraw(?:n|al of) (?:the |our )?(?:notice of )?cancell?ation/',
        self::NONRENEWAL => '/\bnon-?renew(?:al|ed)\b|will not (?:be )?renew|not be renewed/',
        self::PENDING_CANCELLATION => '/notice of intent to cancel|intent to cancel|pending cancell?ation|unless (?:the )?(?:full |minimum )?(?:payment|premium|amount due)[^.]{0,40}(?:is )?received/',
        self::CANCELLATION => '/notice of cancell?ation|cancell?ation notice|has been cancell?ed|is (?:hereby )?cancell?ed|will be cancell?ed|cancell?ation effective|cancell?ation date|policy (?:was )?cancell?ed/',
    ];

    public static function kind(string $text): ?string
    {
        $lower = strtolower((string) preg_replace('/\s+/', ' ', $text));
        foreach (self::CERTIFICATE_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return null;
            }
        }
        foreach (self::PATTERNS as $kind => $pattern) {
            if (preg_match($pattern, $lower)) {
                return $kind;
            }
        }

        return null;
    }

    /** The day the change takes effect, when the notice states it next to a date. */
    public static function effectiveDate(string $text): ?Carbon
    {
        $flat = (string) preg_replace('/\s+/', ' ', $text);
        $date = '(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}|(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.? \d{1,2},? \d{4})';
        $anchors = '(?:effective(?: date)?|cancell?ation date|non-?renewal date|reinstatement date|cancell?ed (?:on|as of)|will be cancell?ed (?:on|as of|at [^,]{0,30} on)?|expires? on|terminat(?:es|ed) on)';
        if (! preg_match('/'.$anchors.'[\s:,]*(?:at 12:01 ?a\.?m\.?[^0-9a-z]{0,10}(?:on )?)?'.$date.'/i', $flat, $match)) {
            return null;
        }
        try {
            return Carbon::parse(str_replace('-', '/', $match[1]))->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Every token that could be a policy number — letters, digits, dashes
     * and spaces with at least four digits in it — for matching against the
     * certificates on file by likeness (PolicyNumber::similarity).
     *
     * @return list<string>
     */
    public static function policyCandidates(string $text): array
    {
        preg_match_all('/\b[A-Z0-9][A-Z0-9\-\/ ]{3,28}[A-Z0-9]\b/i', strtoupper($text), $matches);
        $candidates = [];
        foreach ($matches[0] as $token) {
            foreach (array_unique([$token, ...preg_split('/\s+/', $token)]) as $piece) {
                $normalized = PolicyNumber::normalize($piece);
                if (strlen($normalized) >= 6 && strlen($normalized) <= 25 && preg_match_all('/\d/', $normalized) >= 4) {
                    $candidates[$normalized] = true;
                }
            }
        }

        return array_map('strval', array_keys($candidates));
    }
}
