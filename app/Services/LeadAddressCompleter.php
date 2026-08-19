<?php

namespace App\Services;

use Illuminate\Support\Arr;

/**
 * Completes a lead's address at import time.
 *
 * A lead's address becomes a client record, so it has to be whole — street,
 * city, state and ZIP. Enquiries rarely arrive that way: the website form
 * often carries only a street, and emailed enquiries scatter the parts through
 * the prose. Whatever the sender stated always wins; the geocoder only fills
 * blanks, and only when the address is anchored well enough to trust (see
 * GeoapifyService::geocodeAddress — an unanchored street is refused rather
 * than resolved to the wrong state).
 *
 * Incomplete is a valid outcome: the lead still saves, no client is created,
 * and the Message tab stays shut until someone completes it by hand.
 */
class LeadAddressCompleter
{
    public function __construct(private readonly GeoapifyService $geoapify) {}

    /**
     * @param  array<string, mixed>  $leadData
     * @return array<string, mixed>  the same payload with city/state/zip filled where possible
     */
    public function complete(array $leadData): array
    {
        $street = $this->value($leadData, 'address');

        if ($street === null) {
            return $leadData;
        }

        $city = $this->value($leadData, 'city');
        $state = $this->value($leadData, 'state');
        $zip = $this->value($leadData, 'zip');

        if ($city !== null && $state !== null && $zip !== null) {
            return $leadData;
        }

        $resolved = $this->geoapify->geocodeAddress($this->queryFor($street, $city, $state, $zip));

        if ($resolved === null && $city === null && $state === null && $zip === null) {
            // Nothing to anchor on. Look near the office instead — but only
            // commit when there's exactly ONE match in the service area.
            // "511 Sherwood Dr" is a real address in Addison (12.0 mi) AND
            // Streamwood (13.4 mi); taking the nearest would have filed this
            // lead under the wrong town.
            $candidates = $this->geoapify->nearbyAddressCandidates($street);

            if (count($candidates) === 1) {
                $resolved = $candidates[0];
            } elseif (count($candidates) > 1) {
                // Remember them so whoever opens the lead can pick, instead of
                // typing an address we already know two versions of.
                $leadData['address_candidates'] = $candidates;
            }
        }

        if ($resolved === null) {
            return $leadData;
        }

        // Stated values win — a sender who wrote "60660" knows their own ZIP
        // better than a geocoder that returns 60642 for the same street.
        //
        // Assign rather than union: `$leadData + [...]` only fills keys that
        // are MISSING, and the website form posts the blanks explicitly
        // ("city": null). The key was already there, so the union kept the
        // null and the lead stayed incomplete even when the geocoder had
        // answered it correctly.
        foreach ([
            'city' => $city ?? $resolved['city'],
            'state' => $state ?? $resolved['state'],
            'zip' => $zip ?? $resolved['zip_code'],
        ] as $key => $value) {
            if (filled($value)) {
                $leadData[$key] = $value;
            }
        }

        // An address typed without commas carries the city and state inside
        // the street ("166 Akenside rd  Riverside Il") — and that whole string
        // is what every screen then shows beside the city it already repeats.
        // Once the city is known the tail is pure duplication, so keep just
        // the street, in the sender's own words rather than the geocoder's
        // ("166 Akenside Rd", not its "166 Akenside Road").
        if (($onlyStreet = $this->streetOnly($street)) !== null) {
            $leadData['address'] = $onlyStreet;
        }

        return $leadData;
    }

    /**
     * The street on its own, when the sender ran it together with the city
     * and state. Null when there was nothing to strip.
     *
     * Casing is only ever ADDED — a fully lower-case word gets capitalised
     * ("rd" -> "Rd") while anything the sender already capitalised is left
     * exactly as typed, so "McDonald" and "PO" survive intact.
     */
    private function streetOnly(string $street): ?string
    {
        $normalized = GeoapifyService::normalizeSeparators($street);

        if (! str_contains($normalized, ',')) {
            return null;
        }

        $words = explode(' ', explode(',', $normalized)[0]);

        foreach ($words as $i => $word) {
            if ($word !== '' && $word === mb_strtolower($word)) {
                $words[$i] = mb_strtoupper(mb_substr($word, 0, 1)).mb_substr($word, 1);
            }
        }

        return implode(' ', $words);
    }

    /**
     * Everything we know, in one string, so the geocoder has an anchor.
     */
    private function queryFor(string $street, ?string $city, ?string $state, ?string $zip): string
    {
        $tail = collect([$city, $state, $zip])->filter()->implode(' ');

        if ($tail === '') {
            return $street;
        }

        // Only skip the city when the street really does already name it.
        // str_contains() reports an EMPTY needle as found, so a null city took
        // this branch every time and dropped the comma that separates street
        // from state — the separator everything downstream splits on.
        return $city !== null && str_contains(mb_strtolower($street), mb_strtolower($city))
            ? $street.' '.collect([$state, $zip])->filter()->implode(' ')
            : $street.', '.$tail;
    }

    private function value(array $data, string $key): ?string
    {
        $value = trim((string) (Arr::get($data, $key) ?? ''));

        return $value === '' ? null : $value;
    }
}
