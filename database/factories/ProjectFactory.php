<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'project_name' => $this->faker->streetName(),
            'address' => $this->faker->streetAddress(),
            'city' => $this->faker->city(),
            'state' => $this->faker->stateAbbr(),
            'zip_code' => $this->faker->randomNumber($nbDigits = 5, $strict = false),
            'created_by_user_id' => 1,
            'belongs_to_vendor_id' => 1,
        ];
    }
}
