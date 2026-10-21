<?php

namespace Database\Factories;

use App\Models\ForwardedReceiptMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ForwardedReceiptMessage>
 */
class ForwardedReceiptMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_email_id' => fake()->numberBetween(1, 50),
            'message_id' => fake()->unique()->sha1(),
            'forwarded_message_id' => fake()->sha1(),
        ];
    }
}
