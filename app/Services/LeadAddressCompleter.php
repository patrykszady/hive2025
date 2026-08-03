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
        return $leadData + array_filter([
            'city' => $city ?? $resolved['city'],
            'state' => $state ?? $resolved['state'],
            'zip' => $zip ?? $resolved['zip_code'],
        ]);
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

        return str_contains(mb_strtolower($street), mb_strtolower((string) $city))
            ? $street.' '.collect([$state, $zip])->filter()->implode(' ')
            : $street.', '.$tail;
    }

    private function value(array $data, string $key): ?string
    {
        $value = trim((string) (Arr::get($data, $key) ?? ''));

        return $value === '' ? null : $value;
    }
}
