<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * A page's meta description, cut so a search result never shows one that
 * stops mid-phrase: whole sentences while they fit the limit, otherwise the
 * words that fit closed with an ellipsis (when the whole sentences would
 * leave a description too short to say what the page is).
 */
class MetaDescription
{
    public static function from(string $text, int $limit = 160): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $wholeSentences = '';

        foreach (preg_split('/(?<=[.!?])\s+/u', $text) ?: [] as $sentence) {
            $candidate = $wholeSentences === '' ? $sentence : $wholeSentences.' '.$sentence;

            if (mb_strlen($candidate) > $limit) {
                break;
            }

            $wholeSentences = $candidate;
        }

        if (mb_strlen($wholeSentences) >= intdiv($limit * 3, 5)) {
            return $wholeSentences;
        }

        return rtrim(Str::limit($text, $limit - 1, '', preserveWords: true), " ,;:—–-").'…';
    }
}
