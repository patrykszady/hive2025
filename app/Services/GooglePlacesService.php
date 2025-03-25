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
     *
     * @param string $input
     * @return array
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
     *
     * @param string $placeId
     * @return array
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
            Log::error("Google Place Details API Error: " . $e->getMessage());
            return [];
        }
    }
}



// use GoogleMaps;
// public function geocodeWithCustomFormat($address, $hardcodedZip = 60070)
// {
//     // Step 1: Geocode the hardcoded ZIP code to get the state/region
//     $zipCodeResult = GoogleMaps::load('geocoding')
//         ->setParam(['address' => $hardcodedZip])
//         ->get();

//     $zipCodeData = json_decode($zipCodeResult, true);
//     $zipCodeRegion = 'us';

//     // Extract the "state" or "administrative_area_level_1" from the results
//     foreach ($zipCodeData['results'][0]['address_components'] as $component) {
//         if (in_array('administrative_area_level_1', $component['types'])) {
//             $zipCodeRegion = $component['short_name']; // Example: "IL" for Illinois
//             break;
//         }
//     }

//     // Step 2: Geocode the input address with the region bias
//     $geocodeResult = GoogleMaps::load('geocoding')
//         ->setParam([
//             'address' => $address,
//             'region' => $zipCodeRegion // Apply region bias based on the ZIP code
//         ])
//         ->get();

//     $geocodeData = json_decode($geocodeResult, true);
//     $results = $geocodeData['results'];

//     // Step 3: Format the result string
//     $formattedAddresses = array_map(function ($result) {
//         $result['formatted_address'] = $this->formatAddress($result['address_components']);
//         return $result;
//     }, $results);

//     return $formattedAddresses;
// }

// public function formatAddress($addressComponents)
// {
//     $street = $city = $state = $zip = '';

//     foreach ($addressComponents as $component) {
//         if (in_array('street_number', $component['types'])) {
//             $street = $component['long_name'];
//         }
//         if (in_array('route', $component['types'])) {
//             $street .= ' ' . $component['short_name'];
//         }
//         if (in_array('locality', $component['types'])) {
//             $city = $component['long_name'];
//         }
//         if (in_array('administrative_area_level_1', $component['types'])) {
//             $state = $component['short_name']; // Use state abbreviation
//         }
//         if (in_array('postal_code', $component['types'])) {
//             $zip = $component['long_name'];
//         }
//     }

//     // Combine components into the desired format
//     return "{$street}, {$city}, {$state} {$zip}";
// }
