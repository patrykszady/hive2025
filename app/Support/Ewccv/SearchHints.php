<?php

namespace App\Support\Ewccv;

use App\Models\Vendor;

/**
 * What else to search EWCCV by for a vendor, kept on the vendor's options
 * (`ewccv_search`): the names the business has used (an older certificate's
 * "Smartech Inc"), addresses it has had (the Gurnee one before Prospect
 * Heights), and its FEIN once known — EWCCV's search by FEIN is exact.
 * The scraper tries these before its generic name and address variants, and
 * the comparison scores a result against every name, not just the current.
 *
 * @phpstan-type Hints array{names: list<string>, addresses: list<array{address: string, city: ?string, zip: ?string}>, fein: ?string}
 */
class SearchHints
{
    public const KEY = 'ewccv_search';

    /** @return Hints */
    public static function for(Vendor $vendor): array
    {
        $raw = json_decode(json_encode($vendor->options ?? []), true);
        $hints = is_array($raw[self::KEY] ?? null) ? $raw[self::KEY] : [];

        $known = is_array($hints['known_record'] ?? null) && filled($hints['known_record']['writtenPolicyId'] ?? null)
            ? [
                'writtenPolicyId' => (string) $hints['known_record']['writtenPolicyId'],
                'policyNumber' => (string) ($hints['known_record']['policyNumber'] ?? ''),
                'employerName' => (string) ($hints['known_record']['employerName'] ?? ''),
                'fein' => (string) ($hints['known_record']['fein'] ?? ''),
                'for_policy_number' => filled($hints['known_record']['for_policy_number'] ?? null) ? (string) $hints['known_record']['for_policy_number'] : null,
            ]
            : null;

        return [
            'known_record' => $known,
            'names' => array_values(array_filter(array_map('strval', (array) ($hints['names'] ?? [])))),
            'addresses' => array_values(array_filter(array_map(
                fn ($a) => is_array($a) && filled($a['address'] ?? null)
                    ? ['address' => (string) $a['address'], 'city' => $a['city'] ?? null, 'zip' => $a['zip'] ?? null]
                    : null,
                (array) ($hints['addresses'] ?? [])
            ))),
            'fein' => filled($hints['fein'] ?? null) ? (string) $hints['fein'] : null,
        ];
    }

    /**
     * @param  array{names?: list<string>, addresses?: list<array{address: string, city?: ?string, zip?: ?string}>, fein?: ?string, known_record?: ?array<string, mixed>}  $hints
     */
    public static function save(Vendor $vendor, array $hints): void
    {
        $options = json_decode(json_encode($vendor->options ?? []), true) ?: [];
        $current = self::for($vendor);
        $merged = [
            'names' => array_values(array_unique(array_filter([...$current['names'], ...array_map('strval', (array) ($hints['names'] ?? []))]))),
            'addresses' => array_values(array_unique(array_filter([...$current['addresses'], ...(array) ($hints['addresses'] ?? [])]), SORT_REGULAR)),
            'fein' => filled($hints['fein'] ?? null) ? (string) $hints['fein'] : $current['fein'],
            'known_record' => array_key_exists('known_record', $hints) ? $hints['known_record'] : $current['known_record'],
        ];
        $options[self::KEY] = $merged;
        $vendor->options = (object) $options;
        $vendor->saveQuietly();
    }

    public static function clear(Vendor $vendor): void
    {
        $options = json_decode(json_encode($vendor->options ?? []), true) ?: [];
        unset($options[self::KEY]);
        $vendor->options = (object) $options;
        $vendor->saveQuietly();
    }

    /** Learn the FEIN EWCCV returned for a confirmed match, unless one is already known. */
    public static function learnFein(Vendor $vendor, ?string $fein): void
    {
        $fein = trim((string) $fein);
        if ($fein === '' || self::for($vendor)['fein'] !== null) {
            return;
        }
        self::save($vendor, ['fein' => $fein]);
    }
}
