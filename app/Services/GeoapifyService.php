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
