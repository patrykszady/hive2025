<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class VendorFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'business_name' => $this->faker->company(),
            'address' => $this->faker->streetAddress(),
            'city' => $this->faker->city(),
            'state' => $this->faker->stateAbbr(),
            'zip_code' => $this->faker->randomNumber($nbDigits = 5, $strict = true),
            'business_type' => $this->faker->randomElement(['Sub', 'Retail', 'W9']),
        ];
    }
}
