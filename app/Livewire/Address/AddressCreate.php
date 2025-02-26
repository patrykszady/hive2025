<?php

namespace App\Livewire\Address;

use Livewire\Component;

class AddressCreate extends Component
{
    public $address = NULL;
    public $address_selection = NULL;
    public $addresses = [];

    public $address_1 = NULL;
    public $address_2 = NULL;
    public $city = NULL;
    public $state = NULL;
    public $zip_code = NULL;

    public function rules()
    {
        return [
            'address' => 'nullable',
            'address_1' => 'nullable',
            'address_2' => 'nullable',
            'city' => 'nullable',
            'state' => 'nullable',
            'zip_code' => 'nullable',
            'address_selection' => 'nullable',
        ];
    }

    public function updated($field, $value)
    {
        if ($field === 'address'){
            $response = \GoogleMaps::load('geocoding')
                ->setParam(['address' => $value])
                ->get();

            $result = collect(json_decode($response))->toArray();

            if(!empty($result)){
                $this->addresses = $result['results'];
            }
        }

        if ($field === 'address_selection'){
            $address = collect($this->addresses[$value]->address_components);
            // Define the types you want to filter by
            $typesToFilter = ['street_number', 'route', 'locality', 'administrative_area_level_1', 'postal_code'];

            $address_array = [];

            $address->each(function ($item) use ($typesToFilter, &$address_array) {
                foreach ($item->types as $type) {
                    if (in_array($type, $typesToFilter)) {
                        $address_array[$type] = $item->short_name;
                    }
                }
            });

            $this->address_1 = $address_array['street_number'] . ' ' . $address_array['route'];
            $this->city = $address_array['locality'];
            $this->state = $address_array['administrative_area_level_1'];
            $this->zip_code = $address_array['postal_code'];
        }
    }

    public function render()
    {
        return view('livewire.address.form');
    }
}
