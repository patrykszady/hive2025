<?php

namespace Database\Factories;

use App\Models\CallLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CallLog>
 */
class CallLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'call_id' => fake()->uuid(),
            'direction' => fake()->randomElement(['incoming', 'outgoing']),
            'from_number' => '+1' . fake()->numerify('##########'),
            'to_number' => '+1' . fake()->numerify('##########'),
            'status' => CallLog::STATUS_COMPLETED,
            'duration_seconds' => fake()->numberBetween(0, 600),
            'has_voicemail' => false,
        ];
    }

    public function missed(): static
    {
        return $this->state([
            'status' => CallLog::STATUS_MISSED,
            'duration_seconds' => 0,
        ]);
    }

    public function voicemail(): static
    {
        return $this->state([
            'status' => CallLog::STATUS_VOICEMAIL,
            'has_voicemail' => true,
            'duration_seconds' => fake()->numberBetween(10, 120),
        ]);
    }
}
