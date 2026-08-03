<?php

namespace App\Services;

use App\Models\Vendor;
use App\Support\ApiErrorFormatter;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GeoapifyService
{
    protected $apiKey;
    protected $httpClient;

    public function __construct()
    {
        $this->httpClient = new Client();
        $this->apiKey = (string) config('services.geoapify.key', '');
    }

    /**
     * Fetch autocomplete suggestions from Geoapify Geocoding API.
     */
    public function getAutocompleteSuggestions($input)
    {
        if (strlen((string) $input) < 3 || $this->apiKey === '') {
            return [];
        }

        $location = $this->getVendorLocation(1) ?? $this->getUserVendorLocation();
        $cacheKey = 'geoapify_autocomplete_v3_' . md5(strtolower(trim((string) $input)) . ($location ?? ''));

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            $query = [
                'text' => $input,
                'apiKey' => $this->apiKey,
                'format' => 'json',
                'limit' => 5,
                'filter' => 'countrycode:us',
            ];

            if ($location !== null) {
                $query['bias'] = 'proximity:' . $location;
            }

            $response = $this->httpClient->get('https://api.geoapify.com/v1/geocode/autocomplete', [
                'query' => $query,
            ]);

            $data = json_decode($response->getBody(), true);
            $results = $data['results'] ?? [];
            $results = static::sortResultsByDistance($results, $location);

            $suggestions = collect($results)
                ->filter(fn (array $result) => ! empty($result['place_id']) && ! empty($result['formatted']))
                ->map(function (array $result): array {
                    $normalized = static::normalizeGeoapifyResult($result);

                    Cache::put('geoapify_place_' . $normalized['place_id'], $normalized, now()->addHours(24));

                    return [
                        'place_id' => $normalized['place_id'],
                        'description' => $normalized['formatted_address'],
                    ];
                })
                ->reject(fn (array $suggestion): bool => static::isTownshipSuggestion($suggestion['description']))
                ->values()
                ->all();

            Cache::put($cacheKey, $suggestions, now()->addMinutes(10));

            return $suggestions;
        } catch (RequestException $e) {
            Log::channel('google_places')->error('Geoapify Autocomplete API Error', ApiErrorFormatter::format($e, [
                'input' => $input,
            ]));

            return [];
        }
    }

    /**
     * Get the auth vendor's coordinates for proximity bias.
     * Falls back to geocoding the vendor address (cached for 30 days).
     *
     * @return string|null Coordinates in format "lon,lat"
     */
    protected function getUserVendorLocation(): ?string
    {
        try {
            $user = Auth::user();

            if (! $user || ! $user->vendor) {
                return null;
            }

            return $this->resolveVendorLocation($user->vendor);
        } catch (\Exception $e) {
            Log::channel('google_places')->error('Error getting vendor location', ApiErrorFormatter::format($e));

            return null;
        }
    }

    protected function getVendorLocation(int $vendorId): ?string
    {
        try {
            $vendor = Vendor::withoutGlobalScopes()->find($vendorId);

            if (! $vendor) {
                return null;
            }

            return $this->resolveVendorLocation($vendor);
        } catch (\Exception $e) {
            Log::channel('google_places')->error('Error getting vendor by id for location', ApiErrorFormatter::format($e, [
                'vendor_id' => $vendorId,
            ]));

            return null;
        }
    }

    protected function resolveVendorLocation(Vendor $vendor): ?string
    {
        $lat = data_get($vendor, 'options.lat') ?? data_get($vendor, 'lat');
        $lon = data_get($vendor, 'options.lon') ?? data_get($vendor, 'options.lng') ?? data_get($vendor, 'lon') ?? data_get($vendor, 'lng');

        if (is_numeric($lat) && is_numeric($lon)) {
            return $lon . ',' . $lat;
        }

        $addressLine = trim(implode(' ', array_filter([
            $vendor->address,
            $vendor->city,
            $vendor->state,
            $vendor->zip_code,
        ])));

        if ($addressLine === '' || $this->apiKey === '') {
            return null;
        }

        $cacheKey = 'geoapify_vendor_coords_' . $vendor->id . '_' . md5($addressLine);

        return Cache::remember($cacheKey, now()->addDays(30), function () use ($addressLine) {
            try {
                $response = $this->httpClient->get('https://api.geoapify.com/v1/geocode/search', [
                    'query' => [
                        'text' => $addressLine,
                        'apiKey' => $this->apiKey,
                        'format' => 'json',
                        'limit' => 1,
                        'filter' => 'countrycode:us',
                    ],
                ]);

                $data = json_decode($response->getBody(), true);
                $first = $data['results'][0] ?? null;

                if (! $first || ! isset($first['lat'], $first['lon'])) {
                    return null;
                }

                return $first['lon'] . ',' . $first['lat'];
            } catch (RequestException $e) {
                Log::channel('google_places')->error('Geoapify vendor geocode error', ApiErrorFormatter::format($e));

                return null;
            }
        });
    }

    /**
     * Look up the U.S. county for a free-form address (e.g. "123 Main St, Springfield, IL 62701").
     * Returns the bare county name without the trailing "County" suffix, or null if it can't be resolved.
     * Result is cached for 30 days, keyed by the normalized address.
     */
    public function lookupCounty(string $address): ?string
    {
        $address = trim($address);

        if ($address === '' || $this->apiKey === '') {
            return null;
        }

        $cacheKey = 'geoapify_county_' . md5(strtolower($address));

        return Cache::remember($cacheKey, now()->addDays(30), function () use ($address) {
            try {
                $response = $this->httpClient->get('https://api.geoapify.com/v1/geocode/search', [
                    'query' => [
                        'text' => $address,
                        'apiKey' => $this->apiKey,
                        'format' => 'json',
                        'limit' => 1,
                        'filter' => 'countrycode:us',
                    ],
                ]);

                $data = json_decode($response->getBody(), true);
                $first = $data['results'][0] ?? null;

                if (! is_array($first)) {
                    return null;
                }

                $county = (string) ($first['county'] ?? '');
                $county = trim(preg_replace('/\s+County$/i', '', $county));

                return $county !== '' ? $county : null;
            } catch (RequestException $e) {
                Log::channel('google_places')->error('Geoapify county lookup error', ApiErrorFormatter::format($e, [
                    'address' => $address,
                ]));

                return null;
            }
        });
    }

    /**
     * Forward-geocode a free-form address and return its ZIP code. Used to
     * enrich lead addresses that arrive without one (e.g. from the
     * gs.construction site: "104 N Plum Grove Rd, Palatine, IL, USA").
     */
    public function lookupZipCode(string $address): ?string
    {
        $address = trim($address);

        if ($address === '' || $this->apiKey === '') {
            return null;
        }

        $cacheKey = 'geoapify_zip_' . md5(strtolower($address));

        return Cache::remember($cacheKey, now()->addDays(30), function () use ($address) {
            try {
                $response = $this->httpClient->get('https://api.geoapify.com/v1/geocode/search', [
                    'query' => [
                        'text' => $address,
                        'apiKey' => $this->apiKey,
                        'format' => 'json',
                        'limit' => 1,
                        'filter' => 'countrycode:us',
                    ],
                ]);

                $data = json_decode($response->getBody(), true);
                $first = $data['results'][0] ?? null;

                if (! is_array($first)) {
                    return null;
                }

                $zip = trim((string) ($first['postcode'] ?? ''));

                return preg_match('/^\d{5}(?:-\d{4})?$/', $zip) === 1 ? $zip : null;
            } catch (RequestException $e) {
                Log::channel('google_places')->error('Geoapify zip lookup error', ApiErrorFormatter::format($e, [
                    'address' => $address,
                ]));

                return null;
            }
        });
    }

    /**
     * Resolve a free-form address to its full components.
     *
     * Returns null unless the answer can be TRUSTED, which means the query has
     * to be anchored: a bare street is unanswerable, and the geocoder does not
     * say so — "511 Sherwood Dr" comes back as Rolla, Missouri with confidence
     * 1.0 and match_type full_match, because it simply picked the first
     * Sherwood Drive in the country. So we require a city / state / ZIP in the
     * query, and we require the result to agree with it.
     *
     * @return array{address: string, city: string, state: string, zip_code: string}|null
     */
    public function geocodeAddress(string $address): ?array
    {
        $address = trim($address);

        if ($address === '' || $this->apiKey === '') {
            return null;
        }

        $anchor = self::addressAnchor($address);

        if ($anchor === null) {
            Log::channel('google_places')->info('Geoapify: refusing to geocode an unanchored street', [
                'address' => $address,
            ]);

            return null;
        }

        $cacheKey = 'geoapify_full_'.md5(strtolower($address));

        return Cache::remember($cacheKey, now()->addDays(30), function () use ($address, $anchor) {
            // Structured first: free text makes the geocoder guess which
            // "N Magnolia Ave" you meant and it answers 60642 (wrong) with
            // confidence 0.5, while the same address split into fields answers
            // 60660 (right) with confidence 1.
            $parts = self::splitForGeocoding($address);

            if ($parts !== null) {
                $result = $this->geocodeQuery($parts, $address, $anchor);

                if ($result !== null) {
                    return $result;
                }

                // Without a state the geocoder searches the whole country and
                // hedges — "5647 N Magnolia Ave, Chicago" comes back 60642 at
                // confidence 0.5. Its guess at the STATE is reliable even when
                // the ZIP isn't, so pin that and ask again: the same address
                // then answers 60660 at confidence 1.
                if (! isset($parts['state'])) {
                    $state = $this->probeState($parts);

                    if ($state !== null) {
                        $result = $this->geocodeQuery($parts + ['state' => $state], $address, $anchor);

                        if ($result !== null) {
                            return $result;
                        }
                    }
                }
            }

            return $this->geocodeQuery(['text' => $address], $address, $anchor);
        });
    }

    /**
     * One geocoder call, accepted only if the answer is complete, confident
     * and consistent with what the sender told us.
     *
     * @param  array<string, string>  $query
     * @param  array{zip: ?string, state: ?string}  $anchor
     * @return array{address: string, city: string, state: string, zip_code: string}|null
     */
    private function geocodeQuery(array $query, string $address, array $anchor): ?array
    {
        try {
            $response = $this->httpClient->get('https://api.geoapify.com/v1/geocode/search', [
                'query' => $query + [
                    'apiKey' => $this->apiKey,
                    'format' => 'json',
                    'limit' => 1,
                    'filter' => 'countrycode:us',
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            $first = $data['results'][0] ?? null;

            if (! is_array($first)) {
                return null;
            }

            $zip = trim((string) ($first['postcode'] ?? ''));
            $city = trim((string) ($first['city'] ?? ''));
            $state = strtoupper(trim((string) ($first['state_code'] ?? '')));
            $houseNumber = trim((string) ($first['housenumber'] ?? ''));
            $street = trim((string) ($first['street'] ?? ''));
            $confidence = (float) ($first['rank']['confidence'] ?? 0);

            $complete = $houseNumber !== '' && $street !== '' && $city !== ''
                && preg_match('/^[A-Z]{2}$/', $state) === 1
                && preg_match('/^\d{5}(?:-\d{4})?$/', $zip) === 1;

            // The result has to agree with what the sender told us — otherwise
            // the geocoder resolved a different place entirely.
            // When the sender named a city, the answer must be that city — a
            // state match alone let "Barrington" resolve to "Port Barrington".
            $statedCity = self::statedCity($address);

            $agrees = $statedCity !== null
                ? str_contains(self::normalizeForCompare($statedCity), self::normalizeForCompare($city))
                    || str_contains(self::normalizeForCompare($city), self::normalizeForCompare($statedCity))
                : (($anchor['zip'] !== null && $anchor['zip'] === substr($zip, 0, 5))
                    || ($anchor['state'] !== null && $anchor['state'] === $state));

            // A partial match means it filled in the blanks itself — that is
            // exactly how the wrong ZIP gets through.
            if (! $complete || ! $agrees || $confidence < 0.9) {
                Log::channel('google_places')->info('Geoapify: address not resolved confidently enough to use', [
                    'address' => $address,
                    'structured' => ! isset($query['text']),
                    'resolved' => compact('city', 'state', 'zip'),
                    'confidence' => $confidence,
                    'complete' => $complete,
                    'agrees' => $agrees,
                ]);

                return null;
            }

            return [
                'address' => trim($houseNumber.' '.$street),
                'city' => $city,
                'state' => $state,
                'zip_code' => $zip,
            ];
        } catch (RequestException $e) {
            Log::channel('google_places')->error('Geoapify address lookup error', ApiErrorFormatter::format($e, [
                'address' => $address,
            ]));

            return null;
        }
    }

    /**
     * Break a written address into the fields the structured endpoint wants.
     * Null when there's no house number or no locality to go with it.
     *
     * @return array<string, string>|null
     */
    public static function splitForGeocoding(string $address): ?array
    {
        $segments = array_values(array_filter(array_map('trim', explode(',', $address))));

        if ($segments === []) {
            return null;
        }

        if (! preg_match('/^(\d+[A-Za-z]?)\s+(.+)$/', $segments[0], $m)) {
            return null;
        }

        $query = ['housenumber' => $m[1], 'street' => $m[2], 'country' => 'United States'];

        $tail = implode(' ', array_slice($segments, 1));

        if (preg_match('/\b(\d{5})(?:-\d{4})?\b/', $tail, $zipMatch)) {
            $query['postcode'] = $zipMatch[1];
            $tail = str_replace($zipMatch[0], '', $tail);
        }

        if (preg_match('/\b([A-Z]{2})\b(?![a-z])/', strtoupper($tail), $stateMatch)
            && in_array($stateMatch[1], self::US_STATE_CODES, true)) {
            $query['state'] = $stateMatch[1];
            $tail = preg_replace('/\b'.$stateMatch[1].'\b/i', '', $tail) ?? $tail;
        }

        $city = trim(preg_replace('/\b(USA|US|United States)\b/i', '', $tail) ?? '', " \t\n\r\0\x0B,");

        if ($city !== '') {
            $query['city'] = $city;
        }

        // Needs somewhere to look, not just a street.
        if (! isset($query['city']) && ! isset($query['postcode']) && ! isset($query['state'])) {
            return null;
        }

        return $query;
    }

    /**
     * Every real address matching this street within reach of the office,
     * nearest first.
     *
     * A street with no city is genuinely ambiguous — "511 Sherwood Dr" exists
     * in Addison (12.0 mi) AND Streamwood (13.4 mi). Rather than let the
     * geocoder pick (it answers Rolla, Missouri) or silently take the nearest,
     * hand back the candidates so a human can say which one it is.
     *
     * @return array<int, array{address: string, city: string, state: string, zip_code: string, miles: float}>
     */
    public function nearbyAddressCandidates(string $address, int $limit = 5): array
    {
        $parts = self::splitForGeocoding($address);

        if ($parts === null) {
            // No house number, or nothing to search on.
            if (! preg_match('/^(\d+[A-Za-z]?)\s+(.+)$/', trim($address), $m)) {
                return [];
            }

            $parts = ['housenumber' => $m[1], 'street' => $m[2], 'country' => 'United States'];
        }

        $office = $this->getVendorLocation(1) ?? $this->getUserVendorLocation();

        if ($office === null || $this->apiKey === '') {
            return [];
        }

        $cacheKey = 'geoapify_nearby_'.md5(strtolower($address).$office);

        return Cache::remember($cacheKey, now()->addDays(30), function () use ($parts, $office, $address, $limit) {
            try {
                $response = $this->httpClient->get('https://api.geoapify.com/v1/geocode/search', [
                    'query' => $parts + [
                        'apiKey' => $this->apiKey,
                        'format' => 'json',
                        'limit' => 20,
                        // Constrain to the service area, then rank by distance:
                        // an unconstrained search returns the wrong state.
                        'filter' => 'circle:'.$office.','.self::SERVICE_RADIUS_METERS,
                        'bias' => 'proximity:'.$office,
                    ],
                ]);

                $data = json_decode($response->getBody(), true);
                $results = static::sortResultsByDistance($data['results'] ?? [], $office);

                [$originLon, $originLat] = array_pad(explode(',', $office), 2, null);

                return collect($results)
                    ->filter(function (array $result): bool {
                        return trim((string) ($result['housenumber'] ?? '')) !== ''
                            && trim((string) ($result['street'] ?? '')) !== ''
                            && trim((string) ($result['city'] ?? '')) !== ''
                            && preg_match('/^[A-Za-z]{2}$/', (string) ($result['state_code'] ?? '')) === 1
                            && preg_match('/^\d{5}(?:-\d{4})?$/', (string) ($result['postcode'] ?? '')) === 1;
                    })
                    ->map(function (array $result) use ($originLat, $originLon): array {
                        $km = static::distanceInKilometers(
                            (float) $originLat,
                            (float) $originLon,
                            $result['lat'] ?? null,
                            $result['lon'] ?? null,
                        );

                        return [
                            'address' => trim($result['housenumber'].' '.$result['street']),
                            'city' => (string) $result['city'],
                            'state' => strtoupper((string) $result['state_code']),
                            'zip_code' => (string) $result['postcode'],
                            'miles' => $km === null ? 0.0 : round($km * 0.621371, 1),
                        ];
                    })
                    ->unique(fn (array $c) => $c['address'].'|'.$c['city'].'|'.$c['zip_code'])
                    ->take($limit)
                    ->values()
                    ->all();
            } catch (RequestException $e) {
                Log::channel('google_places')->error('Geoapify nearby address lookup error', ApiErrorFormatter::format($e, [
                    'address' => $address,
                ]));

                return [];
            }
        });
    }

    /** How far from the office we'll look for an unanchored address (50 miles). */
    private const SERVICE_RADIUS_METERS = 80000;

    /**
     * What in this address pins it to a place: a ZIP, a state, or a trailing
     * city segment. Null when it is only a street.
     *
     * @return array{zip: ?string, state: ?string}|null
     */
    public static function addressAnchor(string $address): ?array
    {
        $segments = array_values(array_filter(array_map('trim', explode(',', $address))));

        // Scan for a state ONLY past the street: street suffixes are valid
        // state codes ("960 Danielson Ct" is not Connecticut, "5 Oak St" is not
        // a state at all), and every two-letter token has to be considered —
        // testing just the first one missed the IL in "5 Oak St, Barrington, IL".
        $tail = implode(' ', array_slice($segments, 1));

        $state = null;
        if (preg_match_all('/\b([A-Za-z]{2})\b/', $tail, $matches)) {
            foreach ($matches[1] as $candidate) {
                $candidate = strtoupper($candidate);
                if (in_array($candidate, self::US_STATE_CODES, true)) {
                    $state = $candidate;
                    break;
                }
            }
        }

        $zip = preg_match('/\b(\d{5})(?:-\d{4})?\b/', $address, $m) ? $m[1] : null;

        // "960 Danielson Ct, Gurnee" — a segment after the street counts.
        $hasCitySegment = count($segments) > 1 && $segments[1] !== '';

        if ($zip === null && $state === null && ! $hasCitySegment) {
            return null;
        }

        return ['zip' => $zip, 'state' => $state];
    }

    /**
     * Ask only what state this address is in — used to narrow a second,
     * decisive lookup. Returns null unless the answer is unambiguous.
     *
     * @param  array<string, string>  $parts
     */
    private function probeState(array $parts): ?string
    {
        try {
            $response = $this->httpClient->get('https://api.geoapify.com/v1/geocode/search', [
                'query' => $parts + [
                    'apiKey' => $this->apiKey,
                    'format' => 'json',
                    'limit' => 1,
                    'filter' => 'countrycode:us',
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            $state = strtoupper(trim((string) ($data['results'][0]['state_code'] ?? '')));

            return preg_match('/^[A-Z]{2}$/', $state) === 1 ? $state : null;
        } catch (RequestException) {
            return null;
        }
    }

    /** The city segment the sender wrote, if any. */
    private static function statedCity(string $address): ?string
    {
        $parts = self::splitForGeocoding($address);

        return $parts['city'] ?? null;
    }

    private static function normalizeForCompare(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $value));
    }

    /** @var list<string> */
    private const US_STATE_CODES = [
        'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA', 'HI', 'ID', 'IL', 'IN', 'IA',
        'KS', 'KY', 'LA', 'ME', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ',
        'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT',
        'VA', 'WA', 'WV', 'WI', 'WY', 'DC',
    ];

    public static function sortResultsByDistance(array $results, ?string $location): array
    {
        if ($location === null) {
            return $results;
        }

        [$originLon, $originLat] = array_pad(explode(',', $location), 2, null);

        if (! is_numeric($originLon) || ! is_numeric($originLat)) {
            return $results;
        }

        return collect($results)
            ->sortBy(function (array $result) use ($originLon, $originLat): float {
                $distance = static::distanceInKilometers(
                    (float) $originLat,
                    (float) $originLon,
                    $result['lat'] ?? null,
                    $result['lon'] ?? null
                );

                return $distance ?? INF;
            })
            ->values()
            ->all();
    }

    public static function isTownshipSuggestion(string $description): bool
    {
        return preg_match('/\bTownship\b/i', $description) === 1;
    }

    protected static function distanceInKilometers(float $fromLat, float $fromLon, mixed $toLat, mixed $toLon): ?float
    {
        if (! is_numeric($toLat) || ! is_numeric($toLon)) {
            return null;
        }

        $earthRadiusKm = 6371.0;
        $lat1 = deg2rad($fromLat);
        $lat2 = deg2rad((float) $toLat);
        $deltaLat = deg2rad((float) $toLat - $fromLat);
        $deltaLon = deg2rad((float) $toLon - $fromLon);

        $a = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }

    /**
     * Fetch detailed place information using place_id.
     */
    public function getPlaceDetails($placeId)
    {
        if (empty($placeId)) {
            return [];
        }

        $cached = Cache::get('geoapify_place_' . $placeId);

        if (is_array($cached)) {
            return $cached;
        }

        if ($this->apiKey === '') {
            return [];
        }

        try {
            $response = $this->httpClient->get('https://api.geoapify.com/v1/geocode/search', [
                'query' => [
                    'filter' => 'place:' . $placeId,
                    'apiKey' => $this->apiKey,
                    'format' => 'json',
                    'limit' => 1,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            $result = $data['results'][0] ?? null;

            if (! is_array($result)) {
                return [];
            }

            $normalized = static::normalizeGeoapifyResult($result);
            Cache::put('geoapify_place_' . $normalized['place_id'], $normalized, now()->addHours(24));

            return $normalized;
        } catch (RequestException $e) {
            Log::channel('google_places')->error('Geoapify Place Details API Error', ApiErrorFormatter::format($e, [
                'place_id' => $placeId,
            ]));

            return [];
        }
    }

    /**
     * Normalize a Geoapify result to the historical address keys used by the app.
     *
     * @return array<string, string>
     */
    public static function normalizeGeoapifyResult(array $result): array
    {
        $streetNumber = (string) ($result['housenumber'] ?? '');
        $route = (string) ($result['street'] ?? '');
        $locality = (string) ($result['city'] ?? $result['town'] ?? $result['village'] ?? '');
        $state = (string) ($result['state_code'] ?? $result['state'] ?? '');
        $postalCode = (string) ($result['postcode'] ?? '');
        $formattedAddress = preg_replace('/,?\s*United States of America\s*$/', '', trim((string) ($result['formatted'] ?? '')));
        $placeId = (string) ($result['place_id'] ?? '');

        if ($formattedAddress === '') {
            $line1 = trim($streetNumber . ' ' . $route);
            $line2 = trim($locality . ($state !== '' ? ', ' . $state : '') . ($postalCode !== '' ? ' ' . $postalCode : ''));
            $formattedAddress = trim($line1 . ($line2 !== '' ? ', ' . $line2 : ''));
        }

        return [
            'place_id' => $placeId,
            'street_number' => $streetNumber,
            'route' => $route,
            'locality' => $locality,
            'administrative_area_level_1' => $state,
            'postal_code' => $postalCode,
            'formatted_address' => $formattedAddress,
            'google_formatted_address' => $formattedAddress,
        ];
    }

    /**
     * Generate a map URL for an address based on device type
     *
     * @param string $address Street address
     * @param string $city City
     * @param string $state State
     * @param string $zipCode Postal/ZIP code
     * @param string|null $provider Optional map provider override
     * @return string URL to the map
     */
    public function getMapUrl($address, $city, $state, $zipCode, $provider = null)
    {
        $query = urlencode("{$address}, {$city}, {$state} {$zipCode}");

        if ($provider === null) {
            $userAgent = request()->header('User-Agent');
            $isIOS = (stripos($userAgent, 'iPhone') !== false || stripos($userAgent, 'iPad') !== false || stripos($userAgent, 'iPod') !== false);
            $provider = $isIOS ? 'apple' : 'google';
        }

        if ($provider === 'apple') {
            return "https://maps.apple.com/?q={$query}";
        }

        return "https://www.google.com/maps/search/?api=1&query={$query}";
    }
}
