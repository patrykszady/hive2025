<?php

namespace App\Traits;

use App\Services\GooglePlacesService;

trait HandlesAddresses
{
    public $address_query = NULL;
    public $address_selection = NULL;
    public $address_suggestions = [];
    public $address_1 = NULL;
    public $address_2 = NULL;
    public $city = NULL;
    public $state = NULL;
    public $zip_code = NULL;
    protected $googlePlacesService;

    public function bootHandlesAddresses(GooglePlacesService $googlePlacesService)
    {
        $this->googlePlacesService = $googlePlacesService;
    }

    public function rules()
    {
        return [
            'address_query' => 'nullable',
            'address_selection' => 'nullable',
            'address_1' => 'nullable',
            'address_2' => 'nullable',
            'city' => 'nullable',
            'state' => 'nullable',
            'zip_code' => 'nullable',
        ];
    }

    public function updatedAddressQuery($value)
    {
        if (strlen($value) < 3) {
            $this->address_suggestions = []; // Prevents unnecessary API calls
            return;
        }

        $this->address_suggestions = $this->googlePlacesService->getAutocompleteSuggestions($value);
    }

    public function updatedAddressSelection($value)
    {
        // Return early if there is no valid value
        if (empty($value)) {
            return; // No API call is made when value is empty
        }

        // Fetch address details using the provided place_id
        $address_details = $this->googlePlacesService->getPlaceDetails($value);

        if ($address_details) {
            $this->address_1 = ($address_details['street_number'] ?? '') . ' ' . ($address_details['route'] ?? '');
            $this->city = $address_details['locality'] ?? null;
            $this->state = $address_details['administrative_area_level_1'] ?? null;
            $this->zip_code = $address_details['postal_code'] ?? null;
        }

        // Clear only address suggestions after processing
        $this->address_selection = null;
        $this->address_query = null;
        $this->address_suggestions = [];
    }
}
