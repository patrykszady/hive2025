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
            $this->address_suggestions = [];
            return;
        }

        $this->address_suggestions = $this->googlePlacesService->getAutocompleteSuggestions($value);
    }

    public function updatedAddressSelection($value)
    {
        $address_details = $this->googlePlacesService->getPlaceDetails($value);

        if ($address_details) {
            $this->address_1 = $address_details['street_number'] . ' ' . $address_details['route'];
            $this->city = $address_details['locality'];
            $this->state = $address_details['administrative_area_level_1'];
            $this->zip_code = $address_details['postal_code'];
        }

        $this->address_selection = NULL;
        $this->address_query = NULL;
        $this->address_suggestions = [];
    }
}
