<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmsGroupThread extends Model
{
    protected $fillable = [
        'name',
        'name_data',
        'from_number',
        'participants',
        'project_id',
        'client_id',
        'vendor_id',
        'telnyx_message_id',
        'last_activity_at',
        'opt_in_prompt_sent_at',
        'welcome_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'participants' => 'array',
            'name_data' => 'array',
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

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Scope threads visible to a vendor.
     *
     * Includes:
     * - Threads explicitly assigned via sms_group_threads.vendor_id
     * - Legacy threads without vendor_id that can be tied to this vendor
     *   via project ownership or client-vendor linkage.
     */
    public function scopeVisibleToVendor(Builder $query, int $vendorId): Builder
    {
        return $query->where(function (Builder $scope) use ($vendorId): void {
            $scope->where('vendor_id', $vendorId)
                ->orWhere(function (Builder $legacyScope) use ($vendorId): void {
                    $legacyScope->whereNull('vendor_id')
                        ->where(function (Builder $legacyRelationScope) use ($vendorId): void {
                            $legacyRelationScope->whereHas('project', function (Builder $projectQuery) use ($vendorId): void {
                                $projectQuery->where('belongs_to_vendor_id', $vendorId);
                            })->orWhereHas('client', function (Builder $clientQuery) use ($vendorId): void {
                                $clientQuery->where('vendor_id', $vendorId)
                                    ->orWhereHas('vendors', function (Builder $vendorQuery) use ($vendorId): void {
                                        $vendorQuery->where('vendors.id', $vendorId);
                                    });
                            });
                        });
                });
        });
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
        return $this->messages()->one()->latestOfMany('created_at');
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
        return ! $this->allParticipantsOptedIn();
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

    public static function unreadCountForUser(int $userId, ?int $vendorId = null): int
    {
        $query = SmsMessage::query()
            ->join('sms_group_threads', 'sms_group_threads.id', '=', 'sms_messages.thread_id')
            ->leftJoin('sms_thread_reads as thread_reads', function ($join) use ($userId) {
                $join->on('thread_reads.thread_id', '=', 'sms_messages.thread_id')
                    ->where('thread_reads.user_id', '=', $userId);
            })
            ->whereNotNull('sms_messages.thread_id')
            ->where('sms_messages.direction', SmsMessage::DIRECTION_INBOUND)
            ->where(function ($query) {
                $query->whereNull('thread_reads.last_read_message_id')
                    ->orWhereColumn('sms_messages.id', '>', 'thread_reads.last_read_message_id');
            });

        if ($vendorId) {
            $query->where(function ($q) use ($vendorId) {
                $q->where('sms_group_threads.vendor_id', $vendorId)
                    ->orWhere(function ($legacyScope) use ($vendorId) {
                        $legacyScope->whereNull('sms_group_threads.vendor_id')
                            ->where(function ($rel) use ($vendorId) {
                                $rel->whereIn('sms_group_threads.project_id', function ($sub) use ($vendorId) {
                                    $sub->select('id')->from('projects')->where('belongs_to_vendor_id', $vendorId);
                                })->orWhereIn('sms_group_threads.client_id', function ($sub) use ($vendorId) {
                                    $sub->select('clients.id')->from('clients')
                                        ->where('clients.vendor_id', $vendorId)
                                        ->orWhereIn('clients.id', function ($pivot) use ($vendorId) {
                                            $pivot->select('client_id')->from('client_vendor')->where('vendor_id', $vendorId);
                                        });
                                });
                            });
                    });
            });
        }

        return $query->count('sms_messages.id');
    }

    /**
     * Count unread messages for a user, scoped to specific client IDs.
     *
     * @param  array<int>  $clientIds
     */
    public static function unreadCountForUserInClients(int $userId, array $clientIds): int
    {
        if (empty($clientIds)) {
            return 0;
        }

        return SmsMessage::query()
            ->join('sms_group_threads', 'sms_group_threads.id', '=', 'sms_messages.thread_id')
            ->leftJoin('sms_thread_reads as thread_reads', function ($join) use ($userId) {
                $join->on('thread_reads.thread_id', '=', 'sms_messages.thread_id')
                    ->where('thread_reads.user_id', '=', $userId);
            })
            ->whereNotNull('sms_messages.thread_id')
            ->whereIn('sms_group_threads.client_id', $clientIds)
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
     * Find a thread by a participant phone.
     *
     * Matches any of our Telnyx numbers so inbound replies to a
     * previous number still route to the correct thread.
     */
    public static function findByParticipant(string $fromNumber, string $participantPhone): ?self
    {
        return static::whereIn('from_number', config('services.telnyx.numbers'))
            ->whereJsonContains('participants', $participantPhone)
            ->orderByDesc('last_activity_at')
            ->first();
    }

    /**
     * Find a thread whose participants match the given set exactly.
     *
     * Used for multi-party group MMS where the full participant list
     * (all external phones) is known from the Telnyx all_to + sender.
     *
     * @param  array<string>  $participantPhones  E.164 phones (excluding our number)
     */
    public static function findByParticipantGroup(string $fromNumber, array $participantPhones): ?self
    {
        $sorted = collect($participantPhones)->sort()->values()->all();

        $query = static::whereIn('from_number', config('services.telnyx.numbers'));

        // Every participant must be present
        foreach ($sorted as $phone) {
            $query->whereJsonContains('participants', $phone);
        }

        // And the count must match (no extra participants)
        return $query->whereJsonLength('participants', count($sorted))
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
