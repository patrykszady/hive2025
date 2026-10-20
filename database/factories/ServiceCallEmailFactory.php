<?php

namespace Database\Factories;

use App\Models\ServiceCallEmail;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceCallEmail>
 */
class ServiceCallEmailFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_key' => sha1(fake()->unique()->uuid()),
            'from_email' => fake()->safeEmail(),
            'subject' => fake()->sentence(4),
            'outcome' => ServiceCallEmail::OUTCOME_NOT_SERVICE,
            'received_at' => now(),
        ];
    }

    public function opened(): static
    {
        return $this->state(fn () => ['outcome' => ServiceCallEmail::OUTCOME_OPENED, 'confidence' => 0.95]);
    }
}
