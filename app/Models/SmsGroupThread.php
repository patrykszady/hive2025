<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

class SmsGroupThread extends Model
{
    use Searchable;
    /** A thread of texts with people outside Hive — the default. */
    public const KIND_SMS = 'sms';

    /**
     * A thread between GS team members that lives in Hive only: its messages
     * are never sent as texts (Patryk, 2026-10-27: calling and texting the
     * team "that stay within hive.contractors and don't go to their text
     * messages"). Members are member_user_ids; participants holds their
     * cells so names resolve like everywhere else.
     */
    public const KIND_TEAM = 'team';

    protected $fillable = [
        'kind',
        'member_user_ids',
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
            'member_user_ids' => 'array',
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

    /**
     * Scope threads the given user may see — the single source of truth for
     * SMS thread visibility (list, conversation, read-beacon, offline cache).
     *
     * Client users: threads on one of their clients where their own phone is a
     * participant. Vendor users: the existing visibleToVendor rules.
     */
    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        if ($user->is_browsing_as_client) {
            $clientIds = $user->clients()->pluck('clients.id');
            $participantPhones = $user->smsParticipantPhoneVariants();

            return $query
                ->whereIn('client_id', $clientIds)
                ->whereHas('threadParticipants', fn (Builder $participantQuery) => $participantQuery->whereIn('phone_number', $participantPhones));
        }

        $vendorId = $user->vendor?->id;

        if (! $vendorId) {
            // Vendor user without a vendor context can see nothing.
            return $query->whereRaw('1 = 0');
        }

        return $query->visibleToVendor($vendorId)->forTeamMember((int) $user->id);
    }

    /** Team threads are seen only by their members; every other thread passes. */
    /** Only the Hive-only team conversations (the Team tab). */
    public function scopeTeamThreads(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_TEAM);
    }

    /** Only real text threads (the Messages tab): team conversations never mix in. */
    public function scopeTextThreads(Builder $query): Builder
    {
        return $query->where('kind', '!=', self::KIND_TEAM);
    }

    public function scopeForTeamMember(Builder $query, ?int $userId): Builder
    {
        return $query->where(function (Builder $scope) use ($userId): void {
            $scope->where('kind', '!=', self::KIND_TEAM);
            if ($userId) {
                $scope->orWhereJsonContains('member_user_ids', $userId);
            }
        });
    }

    public function isTeam(): bool
    {
        return $this->kind === self::KIND_TEAM;
    }

    /** @return list<int> */
    public function memberIds(): array
    {
        return array_values(array_map('intval', (array) ($this->member_user_ids ?? [])));
    }

    public function hasMember(?int $userId): bool
    {
        return $userId !== null && in_array($userId, $this->memberIds(), true);
    }

    /** @return Collection<int, User> */
    public function members(): Collection
    {
        $ids = $this->memberIds();

        return $ids === [] ? collect() : User::withoutGlobalScopes()->whereIn('id', $ids)->get()->sortBy(fn (User $u) => array_search($u->id, $ids, true))->values();
    }

    /**
     * The team thread for exactly these people (a pair, or the whole team),
     * made on first use. One per set of members per company.
     *
     * @param  list<int>  $userIds
     */
    public static function teamThreadFor(array $userIds, int $vendorId, ?string $name = null): self
    {
        $ids = collect($userIds)->map(fn ($id) => (int) $id)->filter()->unique()->sort()->values()->all();

        $existing = static::query()
            ->where('kind', self::KIND_TEAM)
            ->where('vendor_id', $vendorId)
            ->get()
            ->first(fn (self $thread) => collect($thread->memberIds())->sort()->values()->all() === $ids);

        if ($existing) {
            return $existing;
        }

        $cells = User::withoutGlobalScopes()->whereIn('id', $ids)->pluck('cell_phone')
            ->map(fn ($cell) => $cell ? \App\Services\GroupSmsService::formatE164((string) $cell) : null)
            ->filter()->unique()->values()->all();

        return static::create([
            'kind' => self::KIND_TEAM,
            'member_user_ids' => $ids,
            'name' => $name,
            'from_number' => (string) config('services.telnyx.from'),
            'participants' => $cells,
            'vendor_id' => $vendorId,
            'last_activity_at' => now(),
        ]);
    }

    /** "Greg" for a pair, "Greg, Cezary" for a group, or the thread's own name. */
    public function teamTitleFor(?User $viewer): string
    {
        if ($this->name) {
            return $this->name;
        }

        $others = $this->members()->reject(fn (User $u) => $viewer && $u->id === $viewer->id);
        if ($others->isEmpty()) {
            return 'Just you';
        }

        return $others->map(fn (User $u) => trim((string) ($u->nickname ?: $u->first_name)))->filter()->implode(', ');
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
        // Team threads never text anyone, so there is nothing to opt in to.
        return ! $this->isTeam() && ! $this->allParticipantsOptedIn();
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

    /**
     * Threads whose latest message is inbound and newer than the user's
     * last-read marker — the same definition as the unread dot in the list.
     */
    public function scopeUnreadForUser($query, int $userId)
    {
        // Unread = the latest message is from someone else: inbound on a text
        // thread; on a team thread, sent by another member.
        return $query->whereHas('latestMessage', fn ($messageQuery) => $messageQuery
            ->where(function ($from) use ($userId): void {
                $from->where(fn ($sms) => $sms->where('direction', SmsMessage::DIRECTION_INBOUND)
                        ->whereExists(fn ($t) => $t->selectRaw('1')->from('sms_group_threads as t')->whereColumn('t.id', 'sms_messages.thread_id')->where('t.kind', '!=', self::KIND_TEAM)))
                    ->orWhere(fn ($team) => $team->where('sent_by_user_id', '!=', $userId)
                        ->whereExists(fn ($t) => $t->selectRaw('1')->from('sms_group_threads as t')->whereColumn('t.id', 'sms_messages.thread_id')->where('t.kind', self::KIND_TEAM)));
            })
            ->whereRaw(
                'sms_messages.id > COALESCE((select last_read_message_id from sms_thread_reads where sms_thread_reads.thread_id = sms_messages.thread_id and sms_thread_reads.user_id = ?), 0)',
                [$userId]
            ));
    }

    /**
     * Count unread THREADS for a user — same definition as the unread dot and
     * the Unread filter, so the sidebar badge always agrees with the list.
     */
    public static function unreadCountForUser(int $userId, ?int $vendorId = null): int
    {
        return static::query()
            ->when($vendorId, fn ($q) => $q->visibleToVendor($vendorId))
            ->forTeamMember($userId)
            ->unreadForUser($userId)
            ->count();
    }

    /**
     * Count unread threads for a user, scoped to specific client IDs.
     *
     * @param  array<int>  $clientIds
     */
    public static function unreadCountForUserInClients(int $userId, array $clientIds): int
    {
        if (empty($clientIds)) {
            return 0;
        }

        return static::query()
            ->whereIn('client_id', $clientIds)
            ->unreadForUser($userId)
            ->count();
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
            'client.users:id,first_name,last_name,nickname,cell_phone',
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
            ? $this->client->users->map(fn ($u) => trim(($u->first_name ?? '') . ' ' . ($u->nickname ? $u->nickname . ' ' : '') . ($u->last_name ?? '')))
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
