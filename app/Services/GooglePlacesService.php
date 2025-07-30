<?php

namespace App\Services;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

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
            // Endpoint for Places Autocomplete API
            $url = "https://maps.googleapis.com/maps/api/place/autocomplete/json";

            // Make the HTTP request
            $response = $this->httpClient->get($url, [
                'query' => [
                    'input' => $input,
                    'key' => $this->apiKey,
                    'types' => 'address', // Restrict results to addresses
                    'region' => 'us',    // Bias results to the United States
                    'location' => '41.8781,-87.6298', // Latitude and longitude for Chicago, Illinois
                    'radius' => 50000,               // Radius in meters (50 km)
                ],
            ]);

            // Decode the JSON response
            $data = json_decode($response->getBody(), true);

            // Return predictions if available
            return $data['predictions'] ?? [];
        } catch (RequestException $e) {
            // Log the error and return an empty array
            Log::error("Google Places API Error: " . $e->getMessage());
            return [];
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
                    'key' => $this->apiKey, // API key for authentication
                ],
            ]);

            // Decode the JSON response
            $data = json_decode($response->getBody(), true);

            // Extract the address components
            $addressComponents = $data['result']['address_components'] ?? [];

            // Define the types to filter by
            $typesToFilter = ['street_number', 'route', 'locality', 'administrative_area_level_1', 'postal_code'];

            // Initialize the address array
            $addressArray = [];

            // Process each address component
            foreach ($addressComponents as $component) {
                foreach ($component['types'] as $type) {
                    if (in_array($type, $typesToFilter)) {
                        $addressArray[$type] = $component['short_name'];
                    }
                }
            }

            // Create the formatted address string
            $addressFormatted =
                $addressArray['street_number'] . ' ' . $addressArray['route'] . ', ' .
                $addressArray['locality'] . ', ' .
                $addressArray['administrative_area_level_1'] . ' ' .
                $addressArray['postal_code'];

            // Add the formatted address to the array
            $addressArray['formatted_address'] = $addressFormatted;

            return $addressArray;

        } catch (RequestException $e) {
            // Log the error and return an empty array
            Log::channel('google_places')->error("Google Place Details API Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Generate a map URL for an address
     * 
     * @param string $address Street address
     * @param string $city City
     * @param string $state State
     * @param string $zipCode Postal/ZIP code
     * @param string $provider Map provider (google or apple)
     * @return string URL to the map
     */
    public function getMapUrl($address, $city, $state, $zipCode, $provider = 'google')
    {
        // Format the address components into a query string
        $query = urlencode("{$address}, {$city}, {$state} {$zipCode}");
        
        // Generate URL based on the provider
        if ($provider === 'apple') {
            return "https://maps.apple.com/?q={$query}";
        } else {
            return "https://www.google.com/maps/search/?api=1&query={$query}";
        }
    }
}
