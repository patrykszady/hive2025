<?php

namespace App\Traits;

use App\Services\GeoapifyService;

trait HandlesAddresses
{
    public $address_query = NULL;
    public $address_suggestions = [];
    public bool $addressJustSelected = false;
    public $address_1 = NULL;
    public $address_2 = NULL;
    public $city = NULL;
    public $state = NULL;
    public $zip_code = NULL;
    protected $geoapifyService;

    public function bootHandlesAddresses(GeoapifyService $geoapifyService)
    {
        $this->geoapifyService = $geoapifyService;
    }

    public function rules()
    {
        return [
            'address_query' => 'nullable',
            'address_1' => 'nullable',
            'address_2' => 'nullable',
            'city' => 'nullable',
            'state' => 'nullable',
            'zip_code' => 'nullable',
        ];
    }

    public function updatedAddressQuery($value)
    {
        if ($this->addressJustSelected) {
            $this->addressJustSelected = false;
            return;
        }

        if (strlen($value) < 3) {
            $this->address_suggestions = [];
            return;
        }

        $this->address_suggestions = $this->geoapifyService->getAutocompleteSuggestions($value);
    }

    public function selectSuggestion(string $placeId): void
    {
        if (empty($placeId)) {
            return;
        }

        $address_details = $this->geoapifyService->getPlaceDetails($placeId);

        if ($address_details) {
            $this->address_1 = trim(($address_details['street_number'] ?? '') . ' ' . ($address_details['route'] ?? ''));
            $this->city = $address_details['locality'] ?? null;
            $this->state = $address_details['administrative_area_level_1'] ?? null;
            $this->zip_code = $address_details['postal_code'] ?? null;
        }

        $this->addressJustSelected = true;
        $this->address_query = null;
        $this->address_suggestions = [];
    }
}
