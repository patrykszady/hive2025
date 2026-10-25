<?php

namespace Database\Factories;

use App\Models\MeetingRecording;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeetingRecording>
 */
class MeetingRecordingFactory extends Factory
{
    protected $model = MeetingRecording::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'belongs_to_vendor_id' => 1,
            'recorded_by_user_id' => 1,
            'status' => MeetingRecording::STATUS_RECORDING,
            'consent_confirmed_at' => now(),
            'started_at' => now(),
            'mime_type' => 'audio/webm',
        ];
    }

    public function ready(array $actionItems = []): static
    {
        return $this->state(fn () => [
            'status' => MeetingRecording::STATUS_READY,
            'ended_at' => now(),
            'duration_seconds' => 1800,
            'transcript' => 'Speaker A: Let us go over the kitchen.',
            'summary' => 'Walked the kitchen with the homeowner.',
            'action_items' => $actionItems,
            'summarized_at' => now(),
        ]);
    }
}
