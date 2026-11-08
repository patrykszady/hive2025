<?php

namespace Database\Factories;

use App\Models\PushToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PushToken>
 */
class PushTokenFactory extends Factory
{
    protected $model = PushToken::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token' => 'ExponentPushToken['.fake()->unique()->regexify('[A-Za-z0-9_-]{22}').']',
            'platform' => PushToken::PLATFORM_IOS,
            'device_name' => fake()->word().'\'s iPhone',
            'last_seen_at' => now(),
        ];
    }
}
