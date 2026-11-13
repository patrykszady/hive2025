<?php

namespace App\Support\Transactions;

/**
 * Whether a Match Vendor rule would claim the very card it is saved from.
 *
 * A rule's "Match As" text is tried against the bank's own text of each
 * charge (plaid_merchant_description) and, when no rule matches that,
 * against Plaid's clean merchant name (TransactionController::
 * add_vendor_to_transactions(), PART 3; since 2026-11-13). The check,
 * deposit, transfer and cash codes read the bank text only. A rule that
 * matches neither on the card it is saved from would match nothing and
 * leave the card on the page with no word of why, so it is refused when it
 * is saved, with text from the bank line to use instead.
 *
 * A NEW vendor is found again by its name, not by a rule, and is not held
 * to this.
 */
class MatchTextReach
{
    /**
     * Why the rule would match nothing on its own card, or null when it
     * does. $options is the card's "Options" box, which the rule appends to
     * its pattern; $names are Plaid's merchant names for the card's charges.
     *
     * @param  list<string|null>  $names
     */
    public static function refusal(string $descriptor, string $matchText, ?string $options = null, bool $isCode = false, array $names = []): ?string
    {
        $matchText = trim($matchText);
        $descriptor = trim($descriptor);
        $names = array_values(array_unique(array_filter(array_map(fn (?string $name): string => trim((string) $name), $names))));

        if ($matchText === '' || $descriptor === '' || self::reaches($descriptor, $matchText, $options, $isCode)) {
            return null;
        }

        if (! $isCode) {
            foreach ($names as $name) {
                if (self::reaches($name, $matchText, $options)) {
                    return null;
                }
            }
        }

        $suggestion = self::suggestion($descriptor, $matchText);

        return sprintf(
            '"%s" is not in this charge\'s bank text ("%s")%s, so it would match nothing and the card would stay. Use words from %s instead%s.',
            $matchText,
            $descriptor,
            ! $isCode && $names !== [] ? ' or in Plaid\'s name for it ("'.implode('", "', $names).'")' : '',
            ! $isCode && $names !== [] ? 'either' : 'the bank text',
            $suggestion !== null ? ', like "'.$suggestion.'"' : '',
        );
    }

    /** The rule as the matcher applies it, tried on the card's own bank text. */
    public static function reaches(string $descriptor, string $matchText, ?string $options = null, bool $isCode = false): bool
    {
        if ($isCode) {
            return stripos($descriptor, $matchText) !== false;
        }

        $pattern = '/'.str_replace('*', '\*', $matchText).($options ?? '').'/i';

        return @preg_match($pattern, $descriptor) === 1;
    }

    /**
     * The longest stretch of the bank text that begins one of the typed
     * words ("Frontier" → "FRONTIER" of "FRONTIERMYSB3L"), as the bank
     * prints it. Null when no typed word of four letters or more starts a
     * word of the bank text.
     */
    private static function suggestion(string $descriptor, string $matchText): ?string
    {
        $best = null;
        $words = preg_split('/[^\p{L}\p{N}]+/u', $matchText, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = preg_split('/[^\p{L}\p{N}&.\'-]+/u', $descriptor, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($words as $word) {
            if (mb_strlen($word) < 4) {
                continue;
            }

            foreach ($tokens as $token) {
                if (mb_stripos($token, $word) === 0 && ($best === null || mb_strlen($word) > mb_strlen($best))) {
                    $best = mb_substr($token, 0, mb_strlen($word));
                }
            }
        }

        return $best;
    }
}
