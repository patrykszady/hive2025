<?php

namespace App\Support\Sms;

use App\Models\CallLog;
use App\Models\Client;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\User;
use App\Models\Vendor;
use App\Services\SmsTranslationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Single source of truth for how an SMS conversation is prepared for display:
 * message loading, tapback/edit processing, viewer-language translation,
 * phone→name resolution, and the header title.
 *
 * Extracted (move-only) from SmsConversation so the same pipeline renders both
 * the live Livewire conversation AND the offline cached fragment
 * (SmsOfflineController) without duplicating any logic.
 */
class ConversationPresenter
{
    /** @var Collection<int, SmsMessage>|null */
    private ?Collection $messages = null;

    /** @var array<string, string>|null */
    private ?array $phoneNameMap = null;

    /** @var array{visible: Collection, scheduled: Collection, reactions: array}|null */
    private ?array $processedMessages = null;

    private ?bool $threadHasMixedNumbers = null;

    /** @var array{title: string, parts: ?array, participantPhones: Collection}|null */
    private ?array $header = null;

    public function __construct(
        public readonly ?SmsGroupThread $thread,
        // Nullable to mirror the old auth()->user() call sites (unit contexts
        // construct components without an authenticated user).
        public readonly ?User $viewer,
        public readonly int $messageLimit = 30,
    ) {
    }

    /**
     * Load a thread with every relation the conversation display needs.
     * Shared by SmsConversation::thread() and the offline fragment endpoint.
     */
    public static function loadThreadForDisplay(?int $threadId): ?SmsGroupThread
    {
        if (! $threadId) {
            return null;
        }

        $thread = SmsGroupThread::with([
            'project:id,address',
            'client',
            'client.users:id,first_name,last_name,nickname,preferred_language,cell_phone',
            'ownerVendor:id,business_name,options',
            'subjectVendor:id,business_name,options',
            'subjectVendor.users:id,first_name,last_name,nickname,preferred_language,cell_phone',
            'threadParticipants:id,thread_id,phone_number',
        ])->find($threadId);

        if (! $thread) {
            return null;
        }

        // The client may be hidden by a global scope in the current vendor
        // context — the thread still needs it for names/labels.
        if (! $thread->client && $thread->client_id) {
            $client = Client::withoutGlobalScopes()
                ->with('users:id,first_name,last_name,nickname,preferred_language,cell_phone')
                ->find($thread->client_id);

            if ($client) {
                $thread->setRelation('client', $client);
            }
        }

        // Resolve every participant name in one batch — the blade asks for
        // these per participant and per sender while rendering the thread.
        static::warmPhoneDisplays(collect($thread->participants ?? [])
            ->merge($thread->threadParticipants->pluck('phone_number'))
            ->filter()
            ->unique());

        return $thread;
    }

    /**
     * Fingerprints for a set of threads in ONE grouped query. Formula must
     * stay identical to the per-thread poll fingerprint (count:maxId:maxUpdatedAt)
     * so the offline manifest and pollForUpdates() agree on "changed".
     *
     * @param  array<int, int>  $threadIds
     * @return array<int, string>
     */
    public static function fingerprintsForThreads(array $threadIds): array
    {
        if (empty($threadIds)) {
            return [];
        }

        $rows = SmsMessage::query()
            ->whereIn('thread_id', $threadIds)
            ->groupBy('thread_id')
            ->selectRaw('thread_id, COUNT(*) as total, COALESCE(MAX(id), 0) as max_id, COALESCE(MAX(updated_at), "") as last_update')
            ->get();

        $fingerprints = [];
        foreach ($threadIds as $id) {
            // Threads with zero messages produce no grouped row; the single-thread
            // aggregate query yields "0:0:" for them, so mirror that here.
            $fingerprints[(int) $id] = '0:0:';
        }
        foreach ($rows as $row) {
            $fingerprints[(int) $row->thread_id] = $row->total . ':' . $row->max_id . ':' . $row->last_update;
        }

        return $fingerprints;
    }

    /**
     * The most recent messages of the thread, oldest→newest.
     *
     * @return Collection<int, SmsMessage>
     */
    public function messages(): Collection
    {
        if ($this->messages !== null) {
            return $this->messages;
        }

        if (! $this->thread) {
            return $this->messages = collect();
        }

        return $this->messages = SmsMessage::where('thread_id', $this->thread->id)
            ->select(['id', 'thread_id', 'direction', 'from_number', 'to_numbers', 'text', 'media_urls', 'raw_payload', 'status', 'scheduled_at', 'created_at', 'sent_by_user_id'])
            ->with('sentByUser:id,first_name,last_name,nickname')
            ->orderByDesc('created_at')
            ->limit($this->messageLimit)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * Build phone number → display name lookup for all inbound senders.
     * Merges client user first names with resolvePhoneDisplay fallback.
     *
     * @return array<string, string>
     */
    public function phoneNameMap(): array
    {
        if ($this->phoneNameMap !== null) {
            return $this->phoneNameMap;
        }

        $map = $this->messages()
            ->where('direction', 'inbound')
            ->pluck('from_number')
            ->unique()
            ->filter()
            ->mapWithKeys(fn (string $number) => [$number => static::resolvePhoneDisplay($number)])
            ->all();

        // Client user first names take precedence
        if ($this->thread?->client) {
            foreach ($this->threadClientUsersFor($this->thread->client) as $user) {
                $telnyx = $user->routeNotificationForTelnyx();
                if ($telnyx) {
                    $map[$telnyx] = static::preferredUserDisplayName($user, false);
                }
            }
            $rawHome = $this->thread->client->getRawOriginal('home_phone');
            if ($rawHome) {
                $formatted = \App\Services\GroupSmsService::formatE164($rawHome);
                if (! isset($map[$formatted])) {
                    $map[$formatted] = $this->thread->client->name;
                }
            }
        }

        return $this->phoneNameMap = $map;
    }

    /**
     * Whether the loaded messages involve both phone numbers (4439 and 4200).
     * When true, we show a small badge on each message indicating which number was used.
     */
    public function threadHasMixedNumbers(): bool
    {
        if ($this->threadHasMixedNumbers !== null) {
            return $this->threadHasMixedNumbers;
        }

        $numbers = config('services.telnyx.numbers', []);

        if (count($numbers) < 2) {
            return $this->threadHasMixedNumbers = false;
        }

        $found = $this->messages()
            ->map(fn (SmsMessage $msg) => $msg->isOutbound()
                ? $msg->from_number
                : collect($msg->to_numbers)->first(fn ($n) => in_array($n, $numbers))
            )
            ->filter()
            ->unique()
            ->values();

        return $this->threadHasMixedNumbers = $found->count() > 1;
    }

    /**
     * Parse tapback reactions and build:
     * - visibleMessages: messages with tapbacks filtered out
     * - reactionsMap: message ID → [emoji => [sender_name, ...]]
     *
     * @return array{visible: Collection, scheduled: Collection, reactions: array}
     */
    public function processedMessages(): array
    {
        if ($this->processedMessages !== null) {
            return $this->processedMessages;
        }

        $allMessages = $this->messages();
        $phoneNameMap = $this->phoneNameMap();
        $tapbackIds = collect();
        $reactionsMap = [];

        // Remote edits (iMessage "Edited to …" SMS fallback): apply the new text
        // to the original message and hide the notification, so the thread reads
        // like iMessage — one bubble with an "Edited" marker. Runs before the
        // tapback pass so reactions match against the edited text.
        $editIds = collect();

        foreach ($allMessages as $msg) {
            $editedText = $msg->parseRemoteEdit();
            if ($editedText === null) {
                continue;
            }

            $editedNormalized = $this->normalizeTapbackMatchText($editedText);
            if ($editedNormalized === '') {
                continue;
            }

            // Original candidates: same sender & direction, sent before the edit,
            // not itself an edit notification. Pick the most similar text.
            $matched = $allMessages
                ->filter(fn ($candidate) => $candidate->id !== $msg->id
                    && ! $editIds->contains($candidate->id)
                    && $candidate->direction === $msg->direction
                    && $candidate->from_number === $msg->from_number
                    && ($candidate->created_at === null || $msg->created_at === null || $candidate->created_at->lte($msg->created_at))
                    && trim((string) $candidate->display_text) !== ''
                    && $candidate->parseRemoteEdit() === null)
                ->map(function ($candidate) use ($editedNormalized) {
                    similar_text($this->normalizeTapbackMatchText((string) $candidate->display_text), $editedNormalized, $percent);

                    return ['message' => $candidate, 'percent' => $percent];
                })
                ->sortByDesc('percent')
                ->first();

            // Only merge when we're confident which message was edited —
            // otherwise leave the notification visible rather than lose it.
            if (! $matched || $matched['percent'] < 40) {
                continue;
            }

            $editIds->push($msg->id);

            $original = $matched['message'];
            // In-memory only: display_text derives from text, so downstream
            // rendering/translation picks up the edited body. Never persisted.
            $original->text = $editedText;
            $original->was_edited = true;
        }

        $allMessages = $allMessages->reject(fn ($m) => $editIds->contains($m->id))->values();

        foreach ($allMessages as $msg) {
            $tapback = $msg->parseTapback();
            if (! $tapback || ! $tapback['emoji']) {
                continue;
            }

            $quotedNormalized = $this->normalizeTapbackMatchText((string) ($tapback['quoted'] ?? ''));
            $quotedLen = mb_strlen($quotedNormalized);
            $matched = $allMessages
                ->filter(function ($candidate) use ($quotedNormalized, $msg) {
                    if ($candidate->id === $msg->id) {
                        return false;
                    }
                    $candidateText = $candidate->display_text;
                    if (! $candidateText) {
                        return false;
                    }
                    $candidateNormalized = $this->normalizeTapbackMatchText((string) $candidateText);

                    if ($candidateNormalized === '' || $quotedNormalized === '') {
                        return false;
                    }

                    return str_contains($candidateNormalized, $quotedNormalized)
                        || str_contains($quotedNormalized, $candidateNormalized);
                })
                ->sortBy(fn ($c) => abs(mb_strlen($this->normalizeTapbackMatchText((string) $c->display_text)) - $quotedLen))
                ->first();

            // Generic (strict) tapbacks are only processed when the quoted text
            // actually matches a message in the thread — avoids hiding normal messages.
            if (($tapback['strict'] ?? false) && ! $matched) {
                continue;
            }

            $tapbackIds->push($msg->id);

            if ($matched) {
                $senderName = $phoneNameMap[$msg->from_number] ?? substr($msg->from_number, -4);
                $reactionsMap[$matched->id][$tapback['emoji']][] = $senderName;
            }
        }

        $withoutTapbacks = $allMessages->reject(fn ($m) => $tapbackIds->contains($m->id));

        $viewerLanguage = $this->preferredLanguageForUser($this->viewer);
        $translator = app(SmsTranslationService::class);
        $withoutTapbacks->each(function (SmsMessage $message) use ($viewerLanguage, $translator): void {
            $message->translated_display_text = $this->messageDisplayTextForViewer($message, $viewerLanguage, $translator);
            $message->original_display_text = $this->messageOriginalTextForViewer($message);

            $languageMeta = $this->messageLanguageMetaForViewer(
                $message,
                $viewerLanguage,
                (string) ($message->translated_display_text ?? ''),
                (string) ($message->original_display_text ?? '')
            );

            $message->language_badge = $languageMeta['badge'];
            $message->show_original_toggle = $languageMeta['show_original_toggle'];
        });

        return $this->processedMessages = [
            'visible' => $withoutTapbacks->where('status', '!=', 'scheduled')->values(),
            // Scheduled messages render in a flex-col-reverse container, so the
            // first item in DOM is visually at the bottom. Sort descending by
            // send time (and creation time as a tie-breaker) so the earliest
            // scheduled message stays on top and later ones stack below it.
            'scheduled' => $withoutTapbacks
                ->where('status', 'scheduled')
                ->sortByDesc(fn ($m) => [
                    optional($m->scheduled_at)->getTimestamp() ?? 0,
                    $m->created_at?->getTimestamp() ?? 0,
                ])
                ->values(),
            'reactions' => $reactionsMap,
        ];
    }

    /* ─── Header title ────────────────────────────────────────────── */

    /**
     * The conversation header title (previously inlined in the blade @php
     * block). `parts` is set when the header should render each participant
     * individually so only client-user portions link to the client.
     */
    public function headerTitle(): string
    {
        return $this->header()['title'];
    }

    /** @return array<int, array{label: string, linkToClient: bool}>|null */
    public function headerParts(): ?array
    {
        return $this->header()['parts'];
    }

    /** @return Collection<int, string> */
    public function participantPhones(): Collection
    {
        return $this->header()['participantPhones'];
    }

    /** @return array{title: string, parts: ?array, participantPhones: Collection} */
    private function header(): array
    {
        if ($this->header !== null) {
            return $this->header;
        }

        $thread = $this->thread;

        if (! $thread) {
            return $this->header = ['title' => '', 'parts' => null, 'participantPhones' => collect()];
        }

        $participantPhones = $thread->threadParticipants->pluck('phone_number')->filter()->values();
        $vendorLabel = trim((string) (
            $thread->ownerVendor?->short_name
            ?: $thread->ownerVendor?->business_name
            ?: $thread->subjectVendor?->short_name
            ?: $thread->subjectVendor?->business_name
            ?: ''
        ));
        $headerTitle = 'Group Message';
        // When set, the header renders these parts individually so only the
        // client-user portions are wrapped in the client link.
        $headerParts = null;
        if ($thread->client) {
            $clientUserByPhone = $thread->client->users
                ->mapWithKeys(fn ($u) => [$u->routeNotificationForTelnyx() => $u])
                ->filter(fn ($u, $phone) => is_string($phone) && $phone !== '');
            $participantsMatchClientUsers = $participantPhones->count() === $clientUserByPhone->count()
                && $participantPhones->diff($clientUserByPhone->keys())->isEmpty();

            if ($participantsMatchClientUsers) {
                $headerTitle = $this->clientDisplayNameForThread($thread);
            } else {
                $headerParts = $participantPhones->map(function ($phone) use ($clientUserByPhone) {
                    $user = $clientUserByPhone->get($phone);

                    return [
                        'label' => $user
                            ? trim(($user->nickname ?: $user->first_name) . ' ' . $user->last_name)
                            : static::resolvePhoneDisplay($phone),
                        'linkToClient' => (bool) $user,
                    ];
                })->values()->all();

                if ($vendorLabel !== '' && collect($headerParts)->where('label', $vendorLabel)->isEmpty()) {
                    $headerParts[] = [
                        'label' => $vendorLabel,
                        'linkToClient' => false,
                    ];
                }
            }

            if ($vendorLabel !== '' && is_string($headerTitle) && ! str_contains($headerTitle, $vendorLabel)) {
                $headerTitle .= ', ' . $vendorLabel;
            }
        } elseif ($thread->name) {
            $headerTitle = $thread->name;
        } elseif ($thread->subjectVendor) {
            $headerTitle = $thread->subjectVendor->short_name ?: $thread->subjectVendor->name;
        } elseif ($thread->project) {
            $headerTitle = $thread->project->address;
        } else {
            if ($participantPhones->isNotEmpty()) {
                $headerTitle = $participantPhones
                    ->map(fn ($p) => static::resolvePhoneDisplay($p))
                    ->implode(', ');
            }
        }

        return $this->header = [
            'title' => (string) $headerTitle,
            'parts' => $headerParts,
            'participantPhones' => $participantPhones,
        ];
    }

    /* ─── Media ───────────────────────────────────────────────────── */

    /**
     * Convert a media URL to the proper public streaming URL.
     * Handles both old /storage/... paths and new relative paths.
     */
    /**
     * $thumb asks for the small cached copy instead of the original — for
     * grids, where a tile is a fraction of the photo's size. An external
     * absolute URL has no thumbnail; it comes back untouched either way.
     */
    public static function mediaUrl(string $url, bool $thumb = false): string
    {
        // If it's already an absolute HTTP URL, return as-is
        if (str_starts_with($url, 'http')) {
            return $url;
        }

        $extra = $thumb ? ['thumb' => 1] : [];

        // If it's an old /storage/sms-media/... path, extract just the path after the prefix
        if (str_starts_with($url, '/storage/sms-media/')) {
            $path = substr($url, strlen('/storage/sms-media/'));

            return route('sms.media', ['filename' => $path] + $extra);
        }

        if (str_starts_with($url, '/storage/sms-attachments/')) {
            $path = substr($url, strlen('/storage/sms-attachments/'));

            return route('sms.media', ['filename' => 'sms-attachments/' . $path] + $extra);
        }

        // If it's a relative path starting with sms-media/ or sms-attachments/, use as-is
        if (str_starts_with($url, 'sms-media/') || str_starts_with($url, 'sms-attachments/')) {
            return route('sms.media', ['filename' => $url] + $extra);
        }

        // Otherwise assume it's a bare filename that goes in sms-media/
        return route('sms.media', ['filename' => 'sms-media/' . $url] + $extra);
    }

    /* ─── Names & contacts ────────────────────────────────────────── */

    public function threadClientUsersFor(?Client $client): Collection
    {
        if (! $client || ! $this->thread) {
            return collect();
        }

        $participantPhones = $this->thread->threadParticipants
            ->pluck('phone_number')
            ->filter();

        $users = $client->relationLoaded('users')
            ? $client->users
            : $client->users()->get(['users.id', 'first_name', 'last_name', 'cell_phone']);

        return static::filterClientUsersToThreadParticipants($users, $participantPhones);
    }

    public static function filterClientUsersToThreadParticipants(iterable $users, iterable $participantPhones): Collection
    {
        $participantPhoneMap = collect($participantPhones)
            ->filter()
            ->flip();

        return collect($users)
            ->filter(function (User $user) use ($participantPhoneMap): bool {
                $e164 = $user->routeNotificationForTelnyx();

                return is_string($e164) && $participantPhoneMap->has($e164);
            })
            ->values();
    }

    public function clientDisplayNameForThread(?SmsGroupThread $thread): string
    {
        if (! $thread?->client) {
            return '';
        }

        if (trim((string) $thread->client->business_name) !== '') {
            return (string) $thread->client->name;
        }

        $users = $this->threadClientUsersFor($thread->client);

        if ($users->isEmpty()) {
            return (string) $thread->client->name;
        }

        if ($users->count() === 1) {
            return static::preferredUserDisplayName($users->first(), true);
        }

        $nameGroups = $users
            ->groupBy(fn (User $user) => trim((string) ($user->last_name ?? '')))
            ->map(function ($lastNameGroup, $lastName) {
                if ($lastNameGroup->count() === 1) {
                    return static::preferredUserDisplayName($lastNameGroup->first(), true);
                }

                $firstNames = $lastNameGroup
                    ->map(fn (User $user) => static::preferredUserDisplayName($user, false))
                    ->filter()
                    ->values()
                    ->all();

                return trim(static::oxfordJoin($firstNames) . ' ' . $lastName);
            })
            ->filter()
            ->values()
            ->all();

        return static::oxfordJoin($nameGroups);
    }

    public static function oxfordJoin(array $items): string
    {
        return collect($items)->filter()->values()->join(', ', ' & ');
    }

    /**
     * Whether the phone belongs to a linked contact (user or vendor). CNAM
     * names from call logs do NOT count — those threads show the number.
     */
    public static function isKnownContact(string $e164): bool
    {
        static $cache = [];

        if (isset($cache[$e164])) {
            return $cache[$e164];
        }

        $digits = preg_replace('/[^0-9]/', '', $e164);

        $normalized = $digits;
        if (strlen($normalized) === 11 && str_starts_with($normalized, '1')) {
            $normalized = substr($normalized, 1);
        }

        $last10 = strlen($digits) > 10 ? substr($digits, -10) : $digits;

        $userExists = User::where('cell_phone', $normalized)
            ->orWhere('cell_phone', '1' . $normalized)
            ->orWhere('cell_phone', $digits)
            ->orWhere('cell_phone', $last10)
            ->exists();

        if ($userExists) {
            return $cache[$e164] = true;
        }

        return $cache[$e164] = Vendor::where('business_phone', $normalized)
            ->orWhere('business_phone', $last10)
            ->orWhere('business_phone', $digits)
            ->exists();
    }

    /**
     * Resolve a display name for an E.164 phone number.
     */
    /** @var array<string, string> Shared with warmPhoneDisplays(). */
    protected static array $phoneDisplayCache = [];

    /**
     * Resolve many numbers up front with 3 queries. A thread's participants
     * and senders each cost up to 3 queries otherwise.
     */
    public static function warmPhoneDisplays(iterable $phones): void
    {
        $pending = [];

        foreach ($phones as $phone) {
            if (! is_string($phone) || $phone === '' || isset(static::$phoneDisplayCache[$phone])) {
                continue;
            }

            $digits = preg_replace('/[^0-9]/', '', $phone);
            $normalized = (strlen($digits) === 11 && str_starts_with($digits, '1')) ? substr($digits, 1) : $digits;
            $last10 = strlen($digits) > 10 ? substr($digits, -10) : $digits;
            $pending[$phone] = array_values(array_unique([$normalized, '1'.$normalized, $digits, $last10]));
        }

        if (empty($pending)) {
            return;
        }

        $needles = collect($pending)->flatten()->unique()->values()->all();

        $usersByDigits = User::whereIn('cell_phone', $needles)
            ->get()
            ->keyBy(fn ($u) => preg_replace('/[^0-9]/', '', (string) $u->cell_phone));

        $vendorsByDigits = Vendor::whereIn('business_phone', $needles)
            ->get()
            ->keyBy(fn ($v) => preg_replace('/[^0-9]/', '', (string) $v->business_phone));

        foreach ($pending as $phone => $variants) {
            $user = collect($variants)->map(fn ($v) => $usersByDigits->get($v))->filter()->first();

            if ($user && trim(static::preferredUserDisplayName($user, true)) !== '') {
                static::$phoneDisplayCache[$phone] = static::preferredUserDisplayName($user, true);
                continue;
            }

            $vendor = collect($variants)->map(fn ($v) => $vendorsByDigits->get($v))->filter()->first();

            if ($vendor && $vendor->short_name) {
                static::$phoneDisplayCache[$phone] = $vendor->short_name;
            }
            // Anything unresolved falls through to resolvePhoneDisplay(), which
            // still does the CNAM lookup for that single number.
        }
    }

    public static function resolvePhoneDisplay(string $e164): string
    {
        $cache = &static::$phoneDisplayCache;

        if (isset($cache[$e164])) {
            return $cache[$e164];
        }

        $digits = preg_replace('/[^0-9]/', '', $e164);

        $normalized = $digits;
        if (strlen($normalized) === 11 && str_starts_with($normalized, '1')) {
            $normalized = substr($normalized, 1);
        }

        $last10 = strlen($digits) > 10 ? substr($digits, -10) : $digits;

        $user = User::where('cell_phone', $normalized)
            ->orWhere('cell_phone', '1' . $normalized)
            ->orWhere('cell_phone', $digits)
            ->orWhere('cell_phone', $last10)
            ->first();

        if ($user && trim(static::preferredUserDisplayName($user, true)) !== '') {
            return $cache[$e164] = static::preferredUserDisplayName($user, true);
        }

        $vendor = Vendor::where('business_phone', $normalized)
            ->orWhere('business_phone', $last10)
            ->orWhere('business_phone', $digits)
            ->first();

        if ($vendor && $vendor->short_name) {
            return $cache[$e164] = $vendor->short_name;
        }

        // Fall back to the latest CNAM captured on a call log, matching the
        // calls tab (HasCallActions::resolvePhoneDisplay).
        $callLogName = CallLog::query()
            ->where(fn ($q) => $q->where('from_number', $e164)->orWhere('to_number', $e164))
            ->whereNotNull('caller_name')
            ->whereNotIn('caller_name', ['Incoming Call', 'Outgoing Call'])
            ->latest()
            ->value('caller_name');

        if (is_string($callLogName) && trim($callLogName) !== '') {
            return $cache[$e164] = CallLog::formatCallerNameForDisplay($callLogName);
        }

        $display10 = strlen($normalized) === 10 ? $normalized : $last10;
        if (strlen($display10) === 10) {
            return $cache[$e164] = '(' . substr($display10, 0, 3) . ') ' . substr($display10, 3, 3) . '-' . substr($display10, 6);
        }

        return $cache[$e164] = $e164;
    }

    public static function preferredUserDisplayName(User $user, bool $includeLastName = true): string
    {
        $first = trim((string) ($user->nickname ?: $user->first_name));

        if (! $includeLastName) {
            return $first;
        }

        return trim($first . ' ' . trim((string) $user->last_name));
    }

    /* ─── Viewer-language translation pipeline ────────────────────── */

    protected function messageDisplayTextForViewer(SmsMessage $message, string $viewerLanguage, SmsTranslationService $translator): ?string
    {
        $displayText = trim((string) $message->display_text);
        if ($displayText === '') {
            return null;
        }

        if ($this->shouldBypassViewerTranslation($message)) {
            return $displayText;
        }

        $rawPayload = is_array($message->raw_payload) ? $message->raw_payload : [];
        $senderLanguage = $this->normalizeLanguage((string) ($rawPayload['sender_language'] ?? ''));
        $originalText = trim((string) ($rawPayload['original_text'] ?? ''));

        // An English rendering translated once by sms:backfill-english wins
        // over anything inferred here. The fallback below has to GUESS the
        // source language from the text, and that guess is a keyword
        // heuristic which reads unaccented Spanish ("Cuando nos vemos no hay
        // prisa") as English and leaves it untranslated. Where the cached
        // value exists it is both cheaper and right.
        $cachedEnglish = trim((string) ($rawPayload['english_text'] ?? ''));

        if ($cachedEnglish !== '') {
            return $cachedEnglish;
        }

        // A stored body that is really a leaked translation prompt: fall back
        // to the original and put THAT into English.
        if ($originalText !== '' && $this->looksLikeTranslationPromptArtifact($displayText)) {
            if ($senderLanguage !== '' && strcasecmp($senderLanguage, 'English') === 0) {
                return $originalText;
            }

            return $translator->translate($originalText, 'English', $senderLanguage !== '' ? $senderLanguage : null);
        }

        // Your own outbound message, composed in English and sent translated:
        // show what you actually typed rather than a round-trip back.
        if (
            $message->isOutbound()
            && (int) ($message->sent_by_user_id ?? 0) === (int) $this->viewer?->id
            && $originalText !== ''
            && $senderLanguage !== ''
            && strcasecmp($senderLanguage, 'English') === 0
        ) {
            return $originalText;
        }

        // Hive reads in English, full stop. This used to render every message
        // in the VIEWER's preferred language, which meant the same thread said
        // different things to different colleagues — you could not quote a
        // message to someone else and be sure they saw those words. It also
        // paid for a translation round-trip per message per render.
        //
        // Inbound text in another language is translated INTO English here;
        // a viewer whose own language is not English gets a per-message badge
        // to translate that one message on demand (see the language meta).
        if ($this->viewerAlreadySpeaksMessageLanguage($message, 'English', $displayText)) {
            return $displayText;
        }

        return $translator->translate($displayText, 'English');
    }

    /**
     * Whether a message is already in the viewer's language, so it can be
     * shown verbatim without a (slow) translation round-trip.
     *
     * When the source language can't be determined we assume it already
     * matches the viewer to avoid translating same-language text on every
     * render — the dominant case for English office users.
     */
    protected function viewerAlreadySpeaksMessageLanguage(SmsMessage $message, string $viewerLanguage, string $candidateText): bool
    {
        $sourceLanguage = $this->messageSourceLanguage($message, $candidateText);

        if ($sourceLanguage === null) {
            return true;
        }

        return strcasecmp($sourceLanguage, $viewerLanguage) === 0;
    }

    protected function messageOriginalTextForViewer(SmsMessage $message): ?string
    {
        if ($this->shouldBypassViewerTranslation($message)) {
            $displayText = trim((string) $message->display_text);

            return $displayText !== '' ? $displayText : null;
        }

        $rawPayload = is_array($message->raw_payload) ? $message->raw_payload : [];
        $originalText = trim((string) ($rawPayload['original_text'] ?? ''));

        if ($originalText !== '') {
            return $originalText;
        }

        $displayText = trim((string) $message->display_text);

        return $displayText !== '' ? $displayText : null;
    }

    /**
     * @return array{badge: ?string, show_original_toggle: bool}
     */
    protected function messageLanguageMetaForViewer(
        SmsMessage $message,
        string $viewerLanguage,
        string $translatedText,
        string $originalText,
    ): array {
        if ($this->shouldBypassViewerTranslation($message)) {
            return ['badge' => null, 'show_original_toggle' => false];
        }

        $sourceLanguage = $this->messageSourceLanguage($message, $originalText);

        // The body is always English now, so the badge answers a different
        // question than it used to. It no longer labels the source language —
        // it is the control a non-English reader uses to put THIS message into
        // their own language, and it carries their language code because that
        // is what pressing it produces.
        $viewerBadge = $this->languageBadgeForLanguage($viewerLanguage);

        $showToggle = trim($translatedText) !== ''
            && trim($originalText) !== ''
            && strcmp(trim($translatedText), trim($originalText)) !== 0;

        if ($viewerBadge !== null && strcasecmp($viewerLanguage, 'English') !== 0) {
            return [
                'badge' => $viewerBadge,
                'show_original_toggle' => $showToggle,
            ];
        }

        // English readers keep the old affordance: when a message arrived in
        // another language, the badge reveals what was actually sent.
        $sourceBadge = $this->languageBadgeForLanguage($sourceLanguage);

        if ($sourceBadge === null || $sourceLanguage === null || strcasecmp($sourceLanguage, 'English') === 0) {
            return ['badge' => null, 'show_original_toggle' => false];
        }

        return [
            'badge' => $sourceBadge,
            'show_original_toggle' => $showToggle,
        ];
    }

    protected function shouldBypassViewerTranslation(SmsMessage $message): bool
    {
        $rawPayload = is_array($message->raw_payload) ? $message->raw_payload : [];

        return (string) ($rawPayload['source'] ?? '') === 'send_schedule_modal';
    }

    protected function messageSourceLanguage(SmsMessage $message, string $originalText): ?string
    {
        $rawPayload = is_array($message->raw_payload) ? $message->raw_payload : [];
        $senderLanguage = trim((string) ($rawPayload['sender_language'] ?? ''));

        $inferredLanguage = $this->inferSupportedLanguageFromText($originalText);
        if ($inferredLanguage !== null) {
            return $inferredLanguage;
        }

        if ($senderLanguage !== '') {
            return $this->normalizeLanguage($senderLanguage);
        }

        return null;
    }

    protected function languageBadgeForLanguage(?string $language): ?string
    {
        if ($language === null || $language === '') {
            return null;
        }

        return match ($this->normalizeLanguage($language)) {
            'English' => 'EN',
            'Spanish' => 'ES',
            'Polish' => 'PL',
            default => null,
        };
    }

    protected function inferSupportedLanguageFromText(string $text): ?string
    {
        $normalized = mb_strtolower(trim($text));
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/[ąćęłńóśźż]/u', $normalized) === 1) {
            return 'Polish';
        }

        if (preg_match('/[áéíóúñ¿¡]/u', $normalized) === 1) {
            return 'Spanish';
        }

        $polishHints = ['dziekuje', 'prosze', 'czesc', 'jutro', 'witam', 'tak', 'nie'];
        foreach ($polishHints as $hint) {
            if ($this->containsHintWord($normalized, $hint)) {
                return 'Polish';
            }
        }

        $spanishHints = ['hola', 'gracias', 'manana', 'por favor', 'buenos', 'buenas', 'que tal'];
        foreach ($spanishHints as $hint) {
            if ($this->containsHintWord($normalized, $hint)) {
                return 'Spanish';
            }
        }

        $englishHints = ['hello', 'hi', 'please', 'can', 'you', 'your', 'send', 'project', 'schedule', 'tasks', 'message', 'thanks'];
        $englishHits = 0;

        foreach ($englishHints as $hint) {
            if ($this->containsHintWord($normalized, $hint)) {
                $englishHits++;
            }
        }

        if ($englishHits >= 2) {
            return 'English';
        }

        return null;
    }

    private function containsHintWord(string $normalizedText, string $hint): bool
    {
        $pattern = '/\b' . preg_quote($hint, '/') . '\b/u';

        return preg_match($pattern, $normalizedText) === 1;
    }

    private function looksLikeTranslationPromptArtifact(string $text): bool
    {
        $normalized = strtolower(trim($text));

        return str_contains($normalized, 'please provide')
            && str_contains($normalized, 'translated');
    }

    /**
     * The language THIS viewer reads in. The thread body no longer depends on
     * it (everything renders in English) — it decides whether the per-message
     * translate badge is offered at all, and what pressing it produces.
     */
    public function viewerPreferredLanguage(): string
    {
        return $this->preferredLanguageForUser($this->viewer);
    }

    protected function preferredLanguageForUser(?User $user): string
    {
        return $this->normalizeLanguage((string) ($user?->preferred_language ?: 'English'));
    }

    protected function normalizeLanguage(string $language): string
    {
        return app(SmsTranslationService::class)->normalizeLanguage($language);
    }

    /* ─── Tapback text normalization ──────────────────────────────── */

    /**
     * Normalize text used when matching tapback quoted text to actual thread messages.
     */
    private function normalizeTapbackMatchText(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $text = $this->repairMojibakeForTapbackMatch($text);
        $text = Str::of($text)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\s]/u', ' ')
            ->replaceMatches('/\s+/u', ' ')
            ->trim()
            ->value();

        return $text;
    }

    /**
     * Lightweight mojibake repair for quote/body mismatches during tapback matching.
     */
    private function repairMojibakeForTapbackMatch(string $text): string
    {
        if (! preg_match('/[ÃÂâðÄÅ]/u', $text)) {
            return $text;
        }

        $candidates = [
            $text,
            @mb_convert_encoding($text, 'Windows-1252', 'UTF-8'),
            @mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8'),
        ];

        $score = static function (string $value): int {
            $penalty = preg_match_all('/[ÃÂâðÄÅ]/u', $value, $m);
            $signal = preg_match_all('/["“”„óęąśłżźćń]/u', $value, $m2);

            return (int) $signal - ((int) $penalty * 2);
        };

        $best = $text;
        $bestScore = $score($text);

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            if (! mb_check_encoding($candidate, 'UTF-8')) {
                continue;
            }

            $candidateScore = $score($candidate);
            if ($candidateScore > $bestScore) {
                $best = $candidate;
                $bestScore = $candidateScore;
            }
        }

        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $best);

        return is_string($clean) && $clean !== '' ? $clean : $best;
    }
}
