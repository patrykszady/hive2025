<?php

namespace Database\Factories;

use App\Models\DeliveryText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryText>
 */
class DeliveryTextFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sms_message_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'belongs_to_vendor_id' => 1,
            'outcome' => DeliveryText::OUTCOME_NOT_DELIVERY,
        ];
    }
}
