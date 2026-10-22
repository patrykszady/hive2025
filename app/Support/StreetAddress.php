<?php

namespace App\Support;

/**
 * What counts as a street address, and how to find one in a message.
 *
 * "We live in Arlington Heights by Lake Arlington" gives the classifier a
 * location, and it dutifully files "by Lake Arlington" as the address. That
 * is not somewhere a permit can be pulled or a crew sent. A street address
 * has a house number — and when the homeowner later writes "1210 East
 * Crabtree Drive, Arlington Heights 60004", it is right there in the text.
 */
final class StreetAddress
{
    private const SUFFIX = 'street|st|avenue|ave|road|rd|drive|dr|lane|ln|court|ct|boulevard|blvd|way|place|pl'
        .'|terrace|ter|terr|circle|cir|trail|trl|parkway|pkwy|highway|hwy|route|rte|square|sq|loop|path|run'
        .'|point|pt|ridge|crossing|xing|cove|cv|bend|plaza|plz|row|walk|alley|aly|pike|turnpike|tpke';

    private const DIRECTION = 'n|s|e|w|ne|nw|se|sw|north|south|east|west|northeast|northwest|southeast|southwest';

    /**
     * A street with its casing tidied for display — "6 drake terrace" reads
     * "6 Drake Terrace" — without ever taking a capital away: "McDonald",
     * "PO Box" and "NE" stay as typed, only fully lower-case words are
     * capitalised. Numbers and units ("2nd", "#4") are left alone, and the
     * small joining words stay small except at the front.
     */
    public static function tidyCase(string $street): string
    {
        $small = ['of', 'the', 'and', 'de', 'la', 'del', 'at'];
        $words = preg_split('/(\s+)/u', trim($street), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $seenWord = false;

        foreach ($words as $i => $word) {
            if ($word === '' || preg_match('/^\s+$/u', $word)) {
                continue;
            }

            $isFirst = ! $seenWord;
            $seenWord = true;

            if ($word !== mb_strtolower($word) || ! preg_match('/^\p{L}/u', $word)) {
                continue; // already cased, or starts with a digit / "#"
            }

            if (! $isFirst && in_array($word, $small, true)) {
                continue;
            }

            $words[$i] = mb_strtoupper(mb_substr($word, 0, 1)).mb_substr($word, 1);
        }

        return implode('', $words);
    }

    /**
     * The house and street, without direction or suffix, for comparing
     * addresses written different ways: "949 SOUTH ELM COURT, PALATINE" and
     * "949 S Elm Ct" both give "949 elm". Null without a house number.
     */
    public static function matchKey(?string $address): ?string
    {
        $street = strtolower(trim(explode(',', (string) $address)[0]));
        $words = preg_split('/\s+/', trim(preg_replace('/[^a-z0-9 ]+/', ' ', $street) ?? '')) ?: [];

        if (count($words) < 2 || ! ctype_digit($words[0])) {
            return null;
        }

        $number = array_shift($words);
        $skip = array_merge(explode('|', self::DIRECTION), explode('|', self::SUFFIX));
        $name = array_values(array_filter($words, fn (string $word) => ! in_array($word, $skip, true)));

        return $name === [] ? null : $number.' '.implode(' ', $name);
    }

    /**
     * The same house: same number and street name, and the same town when
     * the written address names one ("…, PALATINE") and the other side has a
     * city to compare.
     */
    public static function sameHouse(?string $address, ?string $street, ?string $city = null): bool
    {
        $key = self::matchKey($address);

        if ($key === null || $key !== self::matchKey($street)) {
            return false;
        }

        $parts = explode(',', (string) $address);
        $writtenTown = isset($parts[1]) ? trim(preg_replace('/[^a-z ]+|\b(il|illinois)\b/', ' ', strtolower($parts[1])) ?? '') : '';
        $town = trim(preg_replace('/[^a-z ]+/', ' ', strtolower((string) $city)) ?? '');

        return $writtenTown === '' || $town === '' || str_starts_with($writtenTown, $town) || str_starts_with($town, $writtenTown);
    }

    /** A street line has a house number: "by Lake Arlington" is a landmark. */
    public static function looksLikeStreet(?string $value): bool
    {
        $value = trim((string) $value);

        return $value !== ''
            && preg_match('/\d/', $value) === 1
            && preg_match('/\p{L}{2,}/u', $value) === 1;
    }

    /**
     * The first street address stated in a piece of text, with whatever
     * locality follows it — "City, ST 60004", "City 60004" or "City, ST":
     *
     *   "1210 East Crabtree Drive, Arlington Heights  60004"
     *   "7815 Kenton Ave\nSkokie, IL 60076"
     *
     * Null when the text names no street. A landmark, a phone number, a ZIP
     * on its own — none of those is one.
     *
     * @return array{address: string, city: ?string, state: ?string, zip: ?string}|null
     */
    public static function findInText(string $text): ?array
    {
        $street = '/(?<![\d-])(\d{1,6}[a-z]?\s+(?:(?:'.self::DIRECTION.')\.?\s+)?(?:[a-z][a-z\'.-]*\s+){0,4}?(?:'.self::SUFFIX.')\.?)(?![a-z])/iu';

        if (! preg_match($street, $text, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $address = rtrim(preg_replace('/\s+/u', ' ', $m[1][0]) ?? '', '. ');
        $rest = substr($text, $m[1][1] + strlen($m[1][0]), 80);

        $unit = '(?:(?:#|apt\.?|apartment|unit|suite|ste\.?)\s*[\w-]+[\s,]*)?';
        $city = null;
        $state = null;
        $zip = null;

        if (preg_match('/^[\s,.]*'.$unit.'(?:([a-z][a-z.\' -]{1,40}?)[\s,]+)?(?:((?-i:[A-Z]{2}))[\s,]+)?(\d{5})(?:-\d{4})?(?!\d)/iu', $rest, $r)) {
            $city = $r[1] ?? null;
            $state = $r[2] ?? null;
            $zip = $r[3];
        } elseif (preg_match('/^[\s,.]*'.$unit.'((?-i:[A-Z])[a-z.\' -]{1,40}?)[\s,]+((?-i:[A-Z]{2}))(?![a-z])/iu', $rest, $r)) {
            $city = $r[1];
            $state = $r[2];
        }

        $city = $city !== null ? trim(preg_replace('/\s+/u', ' ', $city) ?? '', " ,.") : null;

        return [
            'address' => $address,
            'city' => $city !== '' ? $city : null,
            'state' => $state !== null && $state !== '' ? strtoupper($state) : null,
            'zip' => $zip,
        ];
    }
}
