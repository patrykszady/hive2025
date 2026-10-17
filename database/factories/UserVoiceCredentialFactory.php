<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserVoiceCredential>
 */
class UserVoiceCredentialFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'telnyx_credential_id' => fake()->uuid(),
            'sip_username' => 'gencred'.fake()->unique()->bothify('????????##'),
        ];
    }
}
