<?php

namespace App\Support\Insurance;

/**
 * How likely two policy numbers are the same policy, the PHP twin of
 * policySimilarity() in scripts/ewccv-matching.cjs (Patryk, 2026-10-14:
 * "we should always match the policy number … we need a probability score").
 */
class PolicyNumber
{
    /** The same policy, whatever the names say. */
    public const SAME = 0.95;

    /** Nearly the same number: the same policy only when the name agrees as well. */
    public const LIKELY = 0.85;

    public static function normalize(?string $number): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $number));
    }

    /**
     * 1 for the same number once case, dashes and spaces are gone; 0.95 when
     * one holds the other (an insurer's prefix); else one minus the edit
     * distance over the longer length.
     */
    public static function similarity(?string $a, ?string $b): float
    {
        $x = self::normalize($a);
        $y = self::normalize($b);
        if ($x === '' || $y === '') {
            return 0.0;
        }
        if ($x === $y) {
            return 1.0;
        }
        if (strlen($x) >= 6 && strlen($y) >= 6 && (str_contains($x, $y) || str_contains($y, $x))) {
            return 0.95;
        }

        return 1 - levenshtein($x, $y) / max(strlen($x), strlen($y));
    }
}
