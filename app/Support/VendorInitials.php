<?php

namespace App\Support;

/**
 * The two letters a vendor's avatar chip shows everywhere it appears — the
 * vendor picker's selected chip and its option rows, and anywhere else a
 * vendor's name stands in for one (Patryk, 2026-11-05: "the avatar should
 * read 'VP' for Village of Palatine, drop words like 'of'").
 *
 * The first letter of the name's first two significant words, skipping
 * short connecting words ("of", "the", "&", …) and tokens that are pure
 * punctuation. A name with only one significant word takes that word's own
 * first two letters instead.
 */
final class VendorInitials
{
    /**
     * Connecting words that never start an initial on their own, so long as
     * another significant word is available.
     */
    private const STOP_WORDS = [
        'of', 'the', 'and', '&', 'a', 'an', 'for',
        'de', 'di', 'da', 'del', 'la', 'le', 'van', 'von', 'y',
    ];

    /**
     * "Village of Palatine" → "VP"; "1900's Barbershop" → "1B"; "A-1 Suburban
     * Lock" → "AS"; "A&A Maintenance" → "AM"; a one-word name takes its own
     * first two letters ("Walmart" → "WA").
     */
    public static function for(?string $name): string
    {
        $words = self::significantWords((string) $name);

        if ($words === []) {
            return '';
        }

        if (count($words) === 1) {
            return mb_strtoupper(mb_substr($words[0], 0, 2));
        }

        return mb_strtoupper(self::firstChar($words[0]).self::firstChar($words[1]));
    }

    /**
     * The name's words, in order, with connecting words and pure punctuation
     * tokens left out — falling back to every token when that leaves
     * nothing (a name that is only a connecting word still needs initials).
     *
     * @return list<string>
     */
    private static function significantWords(string $name): array
    {
        $tokens = array_values(array_filter(preg_split('/\s+/u', trim($name)) ?: []));

        $words = array_values(array_filter($tokens, function (string $token): bool {
            // Pure punctuation ("&", "-", "—"): not a word at all.
            if (preg_match('/[\p{L}\p{N}]/u', $token) !== 1) {
                return false;
            }

            return ! in_array(self::stopWordKey($token), self::STOP_WORDS, true);
        }));

        return $words === [] ? $tokens : $words;
    }

    /**
     * A token trimmed of leading/trailing punctuation and lowercased, for
     * comparing against the stop-word list — "Of," matches "of", but
     * "A-1" (punctuation in the middle, not the edges) is left alone and
     * never collapses to "a".
     */
    private static function stopWordKey(string $token): string
    {
        return mb_strtolower((string) preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $token));
    }

    /** The word's first letter or digit, ignoring any leading punctuation. */
    private static function firstChar(string $word): string
    {
        preg_match('/[\p{L}\p{N}]/u', $word, $match);

        return $match[0] ?? mb_substr($word, 0, 1);
    }
}
