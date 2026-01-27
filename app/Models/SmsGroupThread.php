<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsGroupThread extends Model
{
    protected $fillable = [
        'from_number',
        'participants',
        'project_id',
        'telnyx_message_id',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'participants' => 'array',
            'last_activity_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Find a thread by the from number and a participant phone.
     */
    public static function findByParticipant(string $fromNumber, string $participantPhone): ?self
    {
        return static::where('from_number', $fromNumber)
            ->whereJsonContains('participants', $participantPhone)
            ->orderByDesc('last_activity_at')
            ->first();
    }

    /**
     * Get other participants excluding the given phone number.
     *
     * @return array<string>
     */
    public function getOtherParticipants(string $excludePhone): array
    {
        return array_values(array_filter(
            $this->participants,
            fn ($phone) => $phone !== $excludePhone
        ));
    }
}
