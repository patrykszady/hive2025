<?php

namespace App\Services;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class GooglePlacesService
{
    protected $apiKey;       // Stores the API key for authentication
    protected $httpClient;   // Guzzle HTTP client for API requests

    public function __construct()
    {
        $this->httpClient = new Client();
        $this->apiKey = env('GOOGLE_MAPS_API_KEY');
    }

    /**
     * Fetch autocomplete suggestions from Google Places API.
     */
    public function getAutocompleteSuggestions($input)
    {
        // Prevent API calls for queries shorter than 3 characters
        if (strlen($input) < 3) {
            return [];
        }

        try {
            // Get location from authenticated user's vendor
            $location = $this->getUserVendorLocation();
            
            // Endpoint for Places Autocomplete API
            $url = "https://maps.googleapis.com/maps/api/place/autocomplete/json";

            // Make the HTTP request - add space at the end of input
            $response = $this->httpClient->get($url, [
                'query' => [
                    'input' => $input . ' ', // Added space at the end as requested
                    'key' => $this->apiKey,
                    'types' => 'address', // Restrict results to addresses
                    'region' => 'us',    // Bias results to the United States
                    'location' => $location, // Using user's vendor location
                    'radius' => 50000,   // Radius in meters (50 km)
                ],
            ]);

            // Decode the JSON response
            $data = json_decode($response->getBody(), true);
            $predictions = $data['predictions'] ?? [];
            
            // Filter out predictions that don't appear to have street numbers
            $filteredPredictions = [];
            foreach ($predictions as $prediction) {
                // Check if description starts with a number (likely a street number)
                if (preg_match('/^\d+\s+/', $prediction['description'])) {
                    $filteredPredictions[] = $prediction;
                }
            }
            
            // Return filtered predictions
            return $filteredPredictions;
        } catch (RequestException $e) {
            // Log the error and return an empty array
            Log::error("Google Places API Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get the location coordinates from the authenticated user's vendor address
     * 
     * @return string Location coordinates in format "latitude,longitude"
     */
    protected function getUserVendorLocation()
    {
        // Default to Chicago if we can't determine user's vendor location
        $defaultLocation = '41.8781,-87.6298';
        
        try {
            // Get the authenticated user
            $user = Auth::user();
            
            if (!$user || !$user->vendor) {
                return $defaultLocation;
            }
            
            $vendor = $user->vendor;
            
            // Check if we have all the address components
            if (!$vendor->address || !$vendor->city || !$vendor->state || !$vendor->zip) {
                return $defaultLocation;
            }
            
            // Create a cache key based on the vendor's address
            $cacheKey = 'vendor_location_' . md5($vendor->address . $vendor->city . $vendor->state . $vendor->zip);
            
            // Try to get coordinates from cache first
            if (Cache::has($cacheKey)) {
                return Cache::get($cacheKey);
            }
            
            // If not in cache, geocode the address
            $address = urlencode($vendor->address . ', ' . $vendor->city . ', ' . $vendor->state . ' ' . $vendor->zip);
            
            $response = $this->httpClient->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'query' => [
                    'address' => $address,
                    'key' => $this->apiKey
                ]
            ]);
            
            $data = json_decode($response->getBody(), true);
            
            if ($data['status'] === 'OK' && !empty($data['results'][0]['geometry']['location'])) {
                $lat = $data['results'][0]['geometry']['location']['lat'];
                $lng = $data['results'][0]['geometry']['location']['lng'];
                $location = "$lat,$lng";
                
                // Cache the result for 30 days
                Cache::put($cacheKey, $location, now()->addDays(30));
                
                return $location;
            }
            
            // Fallback to default if geocoding failed
            return $defaultLocation;
            
        } catch (\Exception $e) {
            Log::error("Error getting vendor location: " . $e->getMessage());
            return $defaultLocation;
        }
    }

    /**
     * Fetch detailed place information using Place ID.
     */
    public function getPlaceDetails($placeId)
    {
        try {
            // Endpoint for Place Details API
            $url = "https://maps.googleapis.com/maps/api/place/details/json";

            // Make the HTTP request
            $response = $this->httpClient->get($url, [
                'query' => [
                    'place_id' => $placeId,
                    'key' => $this->apiKey,
                    'fields' => 'address_component,formatted_address', // Only request what we need
                ],
            ]);

            // Decode the JSON response
            $data = json_decode($response->getBody(), true);
            
            // Get Google's formatted address as a fallback
            $googleFormattedAddress = $data['result']['formatted_address'] ?? '';

            // Extract the address components
            $addressComponents = $data['result']['address_components'] ?? [];

            // Define the types to filter by
            $typesToFilter = ['street_number', 'route', 'locality', 'administrative_area_level_1', 'postal_code'];

            // Initialize the address array with empty defaults
            $addressArray = [
                'street_number' => '',
                'route' => '',
                'locality' => '',
                'administrative_area_level_1' => '',
                'postal_code' => '',
                'formatted_address' => $googleFormattedAddress, // Default to Google's formatting
                'google_formatted_address' => $googleFormattedAddress,
            ];

            // Process each address component
            foreach ($addressComponents as $component) {
                foreach ($component['types'] as $type) {
                    if (in_array($type, $typesToFilter)) {
                        $addressArray[$type] = $component['short_name'];
                    }
                }
            }

            // Only build our own formatted address if we have the required components
            if (!empty($addressArray['street_number']) && !empty($addressArray['route'])) {
                $addressParts = [];
                
                // Add street address
                $addressParts[] = $addressArray['street_number'] . ' ' . $addressArray['route'];
                
                // Add city if available
                if (!empty($addressArray['locality'])) {
                    $cityPart = $addressArray['locality'];
                    
                    // Add state if available
                    if (!empty($addressArray['administrative_area_level_1'])) {
                        $cityPart .= ', ' . $addressArray['administrative_area_level_1'];
                        
                        // Add postal code if available
                        if (!empty($addressArray['postal_code'])) {
                            $cityPart .= ' ' . $addressArray['postal_code'];
                        }
                    }
                    
                    $addressParts[] = $cityPart;
                }
                
                // Build the formatted address
                $addressArray['formatted_address'] = implode(', ', $addressParts);
            }

            return $addressArray;

        } catch (RequestException $e) {
            // Log the error and return an empty array
            Log::channel('google_places')->error("Google Place Details API Error: " . $e->getMessage());
            return [];
        }
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
        // Format the address components into a query string
        $query = urlencode("{$address}, {$city}, {$state} {$zipCode}");
        
        // Auto-detect iOS devices if provider not explicitly specified
        if ($provider === null) {
            $userAgent = request()->header('User-Agent');
            $isIOS = (stripos($userAgent, 'iPhone') !== false || 
                     stripos($userAgent, 'iPad') !== false || 
                     stripos($userAgent, 'iPod') !== false);
            
            $provider = $isIOS ? 'apple' : 'google';
        }
        
        // Generate URL based on the provider
        if ($provider === 'apple') {
            return "https://maps.apple.com/?q={$query}";
        } else {
            return "https://www.google.com/maps/search/?api=1&query={$query}";
        }
    }
}
