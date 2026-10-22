<?php

namespace Database\Factories;

use App\Models\OutsideCard;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutsideCard>
 */
class OutsideCardFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'belongs_to_vendor_id' => Vendor::factory(),
            'last_four' => (string) fake()->numberBetween(1000, 9999),
        ];
    }
}
