<?php

namespace App\Traits;

use App\Services\GooglePlacesService;
use Illuminate\Database\Eloquent\Casts\Attribute;

trait HasAddress
{
    /**
     * Get the model's full address formatted with HTML breaks.
     */
    protected function fullAddress(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes) {
                if (empty($attributes['address'])) {
                    return null;
                }
                
                if (empty($attributes['address_2'])) {
                    $address1 = $attributes['address'];
                } else {
                    $address1 = $attributes['address'].'<br>'.$attributes['address_2'];
                }
                
                $address2 = $attributes['city'].', '.$attributes['state'].' '.$attributes['zip_code'];
                
                return $address1.'<br>'.$address2;
            }
        );
    }

    /**
     * Get the model's address as a single line with pipe separators.
     */
    protected function oneLineAddress(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes) {
                if (empty($attributes['address'])) {
                    return null;
                }
                
                if (!empty($attributes['address_2'])) {
                    $address = $attributes['address'] . ' | ' . $attributes['address_2'] . ' | ';
                } else {
                    $address = $attributes['address'] . ' | ';
                }
                
                $address .= $attributes['city'] . ', ' . $attributes['state'] . ' ' . $attributes['zip_code'];
                
                return $address;
            }
        );
    }

    /**
     * Format address with title case (First Letter Of Each Word)
     */
    protected function address(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value,
            set: function ($value) {
                if (!$value) {
                    return null;
                }
                
                return ucwords(strtolower($value));
            }
        );
    }

    /**
     * Format address_2 with ALL UPPERCASE letters
     */
    protected function address2(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value,
            set: function ($value) {
                if (!$value) {
                    return null;
                }
                
                return strtoupper($value);
            }
        );
    }

    /**
     * Format city with title case (First Letter Of Each Word)
     */
    protected function city(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value,
            set: function ($value) {
                if (!$value) {
                    return null;
                }
                
                return ucwords(strtolower($value));
            }
        );
    }

    /**
     * Format state with all uppercase letters (CA, TX, NY)
     */
    protected function state(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value,
            set: function ($value) {
                if (!$value) {
                    return null;
                }
                
                return strtoupper($value);
            }
        );
    }

    /**
     * Format zip_code - no specific formatting applied
     */
    protected function zipCode(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value,
            set: fn ($value) => $value
        );
    }

    /**
     * Get a URL to view this address on a map
     *
     * @param string $provider Map provider (google or apple)
     * @return string|null
     */
    public function getAddressMapURI($provider = 'google')
    {
        if (!$this->address || !$this->city || !$this->state || !$this->zip_code) {
            return null;
        }
        
        $query = urlencode("{$this->address}, {$this->city}, {$this->state} {$this->zip_code}");
        
        return $provider === 'apple'
            ? "https://maps.apple.com/?q={$query}"
            : "https://www.google.com/maps/search/?api=1&query={$query}";
    }

    /**
     * Get a Google Maps URL for this address
     *
     * @return string|null
     */
    public function getGoogleMapURI()
    {
        return $this->getAddressMapURI('google');
    }

    /**
     * Get an Apple Maps URL for this address
     *
     * @return string|null
     */
    public function getAppleMapURI()
    {
        return $this->getAddressMapURI('apple');
    }
}