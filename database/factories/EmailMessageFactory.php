<?php

namespace Database\Factories;

use App\Models\EmailMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailMessage>
 */
class EmailMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $rfc = '<'.fake()->unique()->uuid().'@mail.example.test>';

        return [
            'belongs_to_vendor_id' => 1,
            'message_key' => sha1(strtolower($rfc)),
            'rfc_message_id' => $rfc,
            'direction' => EmailMessage::DIRECTION_INBOUND,
            'from_email' => fake()->safeEmail(),
            'from_name' => fake()->name(),
            'to' => ['office@gs.construction'],
            'cc' => [],
            'subject' => fake()->sentence(4),
            'snippet' => fake()->sentence(10),
            'body_text' => fake()->paragraph(),
            'sent_at' => now()->subHour(),
        ];
    }

    public function outbound(): static
    {
        return $this->state(fn () => ['direction' => EmailMessage::DIRECTION_OUTBOUND]);
    }
}
