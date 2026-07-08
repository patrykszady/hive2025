<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

class SmsGroupThread extends Model
{
    use Searchable;
    protected $fillable = [
        'name',
        'name_data',
        'from_number',
        'participants',
        'project_id',
        'client_id',
        'vendor_id',
        'subject_vendor_id',
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
     * Owning vendor without global scopes for cross-context participant labels.
     */
    public function ownerVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id')->withoutGlobalScopes();
    }

    /**
     * The vendor (sub) this thread is communicating WITH.
     * Distinct from $this->vendor, which is the owning tenant.
     */
    public function subjectVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'subject_vendor_id')->withoutGlobalScopes();
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
            ->whereJsonLength('participants', 1)
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

    /* ─── Scout / Meilisearch ──────────────────────────────────────── */

    public function searchableAs(): string
    {
        return app()->environment('local') ? 'sms_threads_index_dev' : 'sms_threads_index';
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $this->loadMissing([
            'project:id,address,belongs_to_vendor_id',
            'client:id,vendor_id,business_name',
            'client.vendors:id',
            'client.users:id,first_name,last_name,cell_phone',
            'subjectVendor:id,business_name,options',
            'threadParticipants:id,thread_id,phone_number',
            'messages' => fn ($q) => $q->latest('created_at')->limit(15),
        ]);

        // Vendors who can see this thread (mirrors scopeVisibleToVendor logic).
        $vendorVisibility = collect();
        if ($this->vendor_id) {
            $vendorVisibility->push((int) $this->vendor_id);
        } else {
            if ($this->project?->belongs_to_vendor_id) {
                $vendorVisibility->push((int) $this->project->belongs_to_vendor_id);
            }
            if ($this->client?->vendor_id) {
                $vendorVisibility->push((int) $this->client->vendor_id);
            }
            if ($this->client?->relationLoaded('vendors')) {
                foreach ($this->client->vendors as $v) {
                    $vendorVisibility->push((int) $v->id);
                }
            }
        }

        $clientName = trim(
            (string) ($this->client?->first_names ?? '')
            . ' ' . (string) ($this->client?->last_names ?? '')
        );
        if ($clientName === '' && $this->client) {
            $clientName = (string) ($this->client->business_name ?? '');
        }

        $clientUserNames = $this->client?->users
            ? $this->client->users->map(fn ($u) => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')))
                ->filter()
                ->values()
                ->all()
            : [];

        $messageText = $this->messages
            ->pluck('text')
            ->filter()
            ->take(15)
            ->implode(' ');

        // Build phone-digit variants for substring/partial searches
        // (e.g. "6349" should match a participant whose phone ends in 6349).
        $participantDigits = collect(is_array($this->participants) ? $this->participants : [])
            ->flatMap(function ($phone) {
                $digits = preg_replace('/\D/', '', (string) $phone);
                if (! is_string($digits) || $digits === '') {
                    return [];
                }
                $variants = [$digits];
                $len = strlen($digits);
                if ($len > 10) {
                    $variants[] = substr($digits, -10);
                }
                if ($len >= 7) {
                    $variants[] = substr($digits, -7);
                }
                if ($len >= 4) {
                    $variants[] = substr($digits, -4);
                }
                return $variants;
            })
            ->unique()
            ->values()
            ->all();

        return [
            'id' => (int) $this->id,
            'participants' => is_array($this->participants) ? array_values($this->participants) : [],
            'participant_digits' => $participantDigits,
            'project_address' => (string) ($this->project?->address ?? ''),
            'client_name' => $clientName,
            'client_user_names' => $clientUserNames,
            'vendor_name' => (string) ($this->subjectVendor?->name ?? $this->subjectVendor?->business_name ?? ''),
            'last_message_text' => mb_substr($messageText, 0, 1500),
            'vendor_visibility_ids' => $vendorVisibility->unique()->values()->all(),
            'client_id' => $this->client_id ? (int) $this->client_id : null,
            'subject_vendor_id' => $this->subject_vendor_id ? (int) $this->subject_vendor_id : null,
            // 0/1 flags for the thread list subject filter — Scout's builder has
            // no whereNotNull(), so "has a client/vendor" must be a plain where().
            'has_client' => $this->client_id ? 1 : 0,
            'has_subject_vendor' => $this->subject_vendor_id ? 1 : 0,
            'last_activity_at_unix' => optional($this->last_activity_at)->timestamp ?? 0,
        ];
    }
}
