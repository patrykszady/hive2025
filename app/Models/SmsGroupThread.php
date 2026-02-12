<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmsGroupThread extends Model
{
    protected $fillable = [
        'from_number',
        'participants',
        'project_id',
        'client_id',
        'telnyx_message_id',
        'last_activity_at',
        'opt_in_prompt_sent_at',
        'welcome_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'participants' => 'array',
            'last_activity_at' => 'datetime',
            'opt_in_prompt_sent_at' => 'datetime',
            'welcome_sent_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SmsMessage::class, 'thread_id');
    }

    /**
     * Get the latest message in this thread.
     */
    public function latestMessage(): HasOne
    {
        return $this->messages()->one()->latestOfMany();
    }

    public function reads(): HasMany
    {
        return $this->hasMany(SmsThreadRead::class, 'thread_id');
    }

    public function threadParticipants(): HasMany
    {
        return $this->hasMany(SmsThreadParticipant::class, 'thread_id');
    }

    public function hasPendingOptIn(): bool
    {
        return $this->opt_in_prompt_sent_at !== null && $this->welcome_sent_at === null;
    }

    public function allParticipantsOptedIn(): bool
    {
        $participantCount = $this->threadParticipants()->count();

        if ($participantCount === 0) {
            return false;
        }

        $optedInCount = $this->threadParticipants()
            ->whereNotNull('opted_in_at')
            ->count();

        return $participantCount === $optedInCount;
    }

    public static function unreadCountForUser(int $userId): int
    {
        return SmsMessage::query()
            ->leftJoin('sms_thread_reads as thread_reads', function ($join) use ($userId) {
                $join->on('thread_reads.thread_id', '=', 'sms_messages.thread_id')
                    ->where('thread_reads.user_id', '=', $userId);
            })
            ->whereNotNull('sms_messages.thread_id')
            ->where('sms_messages.direction', SmsMessage::DIRECTION_INBOUND)
            ->where(function ($query) {
                $query->whereNull('thread_reads.last_read_message_id')
                    ->orWhereColumn('sms_messages.id', '>', 'thread_reads.last_read_message_id');
            })
            ->count('sms_messages.id');
    }

    /**
     * Get a display-friendly label for participants.
     *
     * @return string
     */
    public function getParticipantLabelAttribute(): string
    {
        $phones = $this->participants ?? [];

        if (count($phones) === 0) {
            return 'No participants';
        }

        if (count($phones) === 1) {
            return $phones[0];
        }

        return count($phones) . ' participants';
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
