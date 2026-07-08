<?php

namespace App\Livewire\Sms;

use App\Livewire\Sms\Concerns\ResolvesClientDisplayName;
use App\Models\BlockedCaller;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\SmsThreadRead;
use App\Models\User;
use App\Models\Vendor;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Isolate;
use Livewire\Attributes\Reactive;
use Livewire\Component;

#[Isolate]
class SmsThreadList extends Component
{
    use ResolvesClientDisplayName;

    public int $limit = 20;

    #[Reactive]
    public string $search = '';

    /** 'all', 'client', or 'vendor' */
    #[Reactive]
    public string $subjectFilter = 'all';

    public ?int $selectedThreadId = null;

    #[Reactive]
    public bool $isClientUser = false;

    /** @return array<string, string> */
    public function getListeners(): array
    {
        return [
            'echo-private:sms.notifications,SmsMessageReceived' => 'handleNewMessage',
            'sms-thread-read' => '$refresh',
            'sms-schedule-changed' => '$refresh',
            'sms-spam-changed' => '$refresh',
            'sms-refresh-threads' => '$refresh',
        ];
    }

    public function handleNewMessage(): void
    {
        // Inject JS directly into the Livewire response to trigger tab flash / sound
        $this->js("window.dispatchEvent(new CustomEvent('sms-incoming'))");
    }

    public function updating($field): void
    {
        if ($field !== 'limit') {
            $this->limit = 20;
        }
    }

    public function loadMore(): void
    {
        $this->limit += 20;
    }

    #[Computed]
    public function threads()
    {
        $user = auth()->user();
        $search = trim($this->search);

        // Fast path: Meilisearch when there's a query.
        if ($search !== '') {
            try {
                $meili = SmsGroupThread::search($search);

                if (! $user->is_browsing_as_client && $user->vendor?->id) {
                    $meili->where('vendor_visibility_ids', (int) $user->vendor->id);
                }
                // Scout's builder has no whereNotNull() — filter on indexed 0/1 flags.
                if ($this->subjectFilter === 'client') {
                    $meili->where('has_client', 1);
                } elseif ($this->subjectFilter === 'vendor') {
                    $meili->where('has_subject_vendor', 1);
                }

                $ids = $meili->take(max($this->limit, 50))->keys()->all();

                // Also match on the dedicated message index so searches hit
                // body text anywhere in a thread's history (the thread index
                // only embeds its most recent messages).
                try {
                    $messageIds = SmsMessage::search($search)->take(max($this->limit, 50))->keys()->all();
                    if (! empty($messageIds)) {
                        $messageThreadIds = SmsMessage::query()
                            ->whereIn('id', $messageIds)
                            ->whereNotNull('thread_id')
                            ->orderByDesc('created_at')
                            ->pluck('thread_id')
                            ->map(fn ($id) => (int) $id)
                            ->all();

                        $ids = array_merge(array_map('intval', $ids), $messageThreadIds);
                    }
                } catch (\Throwable $e) {
                    report($e);
                }

                $ids = array_values(array_unique($ids));

                if (empty($ids)) {
                    return collect();
                }

                $orderedIds = implode(',', array_map('intval', $ids));

                $threads = SmsGroupThread::query()
                    ->with([
                        'project:id,address',
                        'client',
                        'client.users:id,first_name,last_name,nickname,cell_phone',
                        'ownerVendor:id,business_name,options',
                        'subjectVendor',
                        'latestMessage.sentByUser:id,first_name,nickname',
                        'threadParticipants:id,thread_id,phone_number',
                    ])
                    ->withCount(['messages as scheduled_messages_count' => fn ($q) => $q->where('status', 'scheduled')])
                    ->whereIn('id', $ids)
                    ->when($user->is_browsing_as_client, function ($query) use ($user) {
                        $clientIds = $user->clients()->pluck('clients.id');
                        $participantPhones = $this->clientUserParticipantPhones($user);

                        $query->whereIn('client_id', $clientIds)
                            ->whereHas('threadParticipants', fn ($participantQuery) => $participantQuery->whereIn('phone_number', $participantPhones));
                    })
                    ->when(! $user->is_browsing_as_client, function ($query) use ($user) {
                        $vendorId = $user->vendor?->id;
                        if ($vendorId) {
                            $query->visibleToVendor($vendorId);
                        }
                    })
                    ->limit($this->limit)
                    ->get();

                // Preserve Meili relevance ordering across DB drivers (MySQL FIELD()
                // is not portable to SQLite). Sort the in-memory collection instead.
                $position = array_flip($ids);

                return $threads
                    ->sortBy(fn ($t) => $position[$t->id] ?? PHP_INT_MAX)
                    ->values();
            } catch (\Throwable $e) {
                report($e);
                // Fall through to SQL fallback below.
            }
        }

        return SmsGroupThread::query()
            ->with([
                'project:id,address',
                'client',
                'client.users:id,first_name,last_name,nickname,cell_phone',
                'ownerVendor:id,business_name,options',
                'subjectVendor',
                'latestMessage.sentByUser:id,first_name,nickname',
                'threadParticipants:id,thread_id,phone_number',
            ])
            ->withCount(['messages as scheduled_messages_count' => fn ($q) => $q->where('status', 'scheduled')])
            ->when($user->is_browsing_as_client, function ($query) use ($user) {
                $clientIds = $user->clients()->pluck('clients.id');
                $participantPhones = $this->clientUserParticipantPhones($user);

                $query->whereIn('client_id', $clientIds)
                    ->whereHas('threadParticipants', fn ($participantQuery) => $participantQuery->whereIn('phone_number', $participantPhones));
            })
            ->when(! $user->is_browsing_as_client, function ($query) use ($user) {
                $vendorId = $user->vendor?->id;
                if ($vendorId) {
                    $query->visibleToVendor($vendorId);
                }
            })
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->whereJsonContains('participants', $search)
                        ->orWhereHas('project', fn ($pq) => $pq->where('address', 'like', "%{$search}%"))
                        ->orWhereHas('messages', fn ($mq) => $mq->where('text', 'like', "%{$search}%"));

                    // Phone digit search — match participants by trailing digits
                    // (e.g. "6349" matches +18473376349).
                    $digits = preg_replace('/\D/', '', $search);
                    if (is_string($digits) && strlen($digits) >= 4) {
                        $q->orWhereHas('threadParticipants', fn ($pq) => $pq
                            ->where('phone_number', 'like', "%{$digits}%"));
                    }
                });
            })
            ->when($this->subjectFilter === 'client', fn ($q) => $q->whereNotNull('client_id'))
            ->when($this->subjectFilter === 'vendor', fn ($q) => $q->whereNotNull('subject_vendor_id'))
            ->orderByDesc('last_activity_at')
            ->limit($this->limit)
            ->get();
    }

    /**
     * @return array<int, string>
     */
    protected function clientUserParticipantPhones(User $user): array
    {
        $rawPhone = $user->routeNotificationForTelnyx();

        if (! is_string($rawPhone) || $rawPhone === '') {
            return [];
        }

        $digits = preg_replace('/\D/', '', $rawPhone);
        if (! is_string($digits) || $digits === '') {
            return [];
        }

        if (strlen($digits) === 10) {
            return array_values(array_unique([$rawPhone, '+1' . $digits, '1' . $digits, $digits]));
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $tenDigit = substr($digits, 1);

            return array_values(array_unique([$rawPhone, '+' . $digits, $digits, '+1' . $tenDigit, $tenDigit]));
        }

        return array_values(array_unique([$rawPhone, '+' . $digits, $digits]));
    }

    #[Computed]
    public function unreadThreadIds(): array
    {
        $latestInboundByThread = $this->threads
            ->mapWithKeys(fn ($thread) => [$thread->id => $thread->latestMessage])
            ->filter(fn ($message) => $message && $message->isInbound());

        if ($latestInboundByThread->isEmpty()) {
            return [];
        }

        $threadIds = $latestInboundByThread->keys()->values()->all();

        $readMap = SmsThreadRead::query()
            ->where('user_id', auth()->id())
            ->whereIn('thread_id', $threadIds)
            ->pluck('last_read_message_id', 'thread_id');

        return $latestInboundByThread
            ->filter(fn ($message, $threadId) => (int) ($readMap[$threadId] ?? 0) < $message->id)
            ->keys()
            ->map(fn ($threadId) => (int) $threadId)
            ->values()
            ->all();
    }

    /**
     * Kept as a safety net for stale Livewire snapshots that still reference
     * wire:click="select(...)".  New template dispatches via Alpine instead.
     */
    public function select(int $threadId): void
    {
        $this->dispatch('threadSelected', threadId: $threadId)->to(SmsIndex::class);
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function blockedPhones(): array
    {
        return BlockedCaller::query()
            ->pluck('phone_number')
            ->filter(fn ($phone) => is_string($phone) && trim($phone) !== '')
            ->values()
            ->all();
    }

    public function threadIsSpam(SmsGroupThread $thread): bool
    {
        $blockedMap = collect($this->blockedPhones)
            ->flatMap(fn (string $phone) => $this->phoneMatchCandidates($phone))
            ->flip();

        if ($blockedMap->isEmpty()) {
            return false;
        }

        $participantPhones = $thread->threadParticipants
            ->pluck('phone_number')
            ->filter(fn ($phone) => is_string($phone) && trim($phone) !== '')
            ->values();

        foreach ($participantPhones as $phone) {
            foreach ($this->phoneMatchCandidates($phone) as $candidate) {
                if ($blockedMap->has($candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    protected function phoneMatchCandidates(string $phone): array
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (! is_string($digits) || $digits === '') {
            return [];
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $ten = substr($digits, 1);

            return array_values(array_unique([
                $digits,
                '+1' . $ten,
                $ten,
            ]));
        }

        if (strlen($digits) === 10) {
            return array_values(array_unique([
                $digits,
                '1' . $digits,
                '+1' . $digits,
            ]));
        }

        return array_values(array_unique([
            $digits,
            '+' . $digits,
        ]));
    }

    /**
     * Per-render cache of phone → display-name lookups, populated lazily and
     * batched in {@see warmPhoneCache()} to eliminate N+1 user/vendor queries.
     *
     * @var array<string, string>
     */
    protected array $phoneNameCache = [];

    /**
     * Pre-fetch user + vendor names for every phone number that will be
     * rendered, avoiding per-row queries from {@see resolvePhoneDisplay()}.
     *
     * @param  iterable<string>  $phones
     */
    protected function warmPhoneCache(iterable $phones): void
    {
        $normalized = [];
        $last10s = [];

        foreach ($phones as $phone) {
            if (! is_string($phone) || $phone === '' || isset($this->phoneNameCache[$phone])) {
                continue;
            }
            $digits = preg_replace('/[^0-9]/', '', $phone);
            $n = (strlen($digits) === 11 && str_starts_with($digits, '1')) ? substr($digits, 1) : $digits;
            $last10 = strlen($digits) > 10 ? substr($digits, -10) : $digits;
            $normalized[$phone] = ['n' => $n, 'd' => $digits, 'l' => $last10];
            $last10s[] = $last10;
        }

        if (empty($normalized)) {
            return;
        }

        $needles = collect($normalized)
            ->flatMap(fn ($v) => [$v['n'], '1' . $v['n'], $v['d'], $v['l']])
            ->unique()
            ->values()
            ->all();

        $users = User::whereIn('cell_phone', $needles)
            ->get(['id', 'first_name', 'last_name', 'nickname', 'cell_phone'])
            ->keyBy(fn ($u) => preg_replace('/[^0-9]/', '', (string) $u->cell_phone));

        $vendors = Vendor::whereIn('business_phone', $needles)
            ->get(['id', 'business_name', 'business_phone', 'options'])
            ->keyBy(fn ($v) => preg_replace('/[^0-9]/', '', (string) $v->business_phone));

        foreach ($normalized as $phone => $parts) {
            $candidateKeys = array_unique([$parts['n'], '1' . $parts['n'], $parts['d'], $parts['l']]);

            $name = null;
            foreach ($candidateKeys as $key) {
                if ($user = $users->get($key)) {
                    $firstName = trim((string) ($user->nickname ?? '')) !== ''
                        ? trim((string) $user->nickname)
                        : trim((string) ($user->first_name ?? ''));
                    $full = trim($firstName . ' ' . ($user->last_name ?? ''));
                    if ($full !== '') {
                        $name = $full;
                        break;
                    }
                }
            }

            if (! $name) {
                foreach ($candidateKeys as $key) {
                    if ($vendor = $vendors->get($key)) {
                        $vendorName = $vendor->name ?: $vendor->business_name;
                        if ($vendorName) {
                            $name = $vendorName;
                            break;
                        }
                    }
                }
            }

            if (! $name) {
                $display10 = strlen($parts['n']) === 10 ? $parts['n'] : $parts['l'];
                $name = strlen($display10) === 10
                    ? '(' . substr($display10, 0, 3) . ') ' . substr($display10, 3, 3) . '-' . substr($display10, 6)
                    : $phone;
            }

            $this->phoneNameCache[$phone] = $name;
        }
    }

    /**
     * Resolve a display name for an E.164 phone number.
     * Returns user name, or formatted 10-digit number as fallback.
     */
    public function resolvePhoneDisplay(string $e164): string
    {
        if (isset($this->phoneNameCache[$e164])) {
            return $this->phoneNameCache[$e164];
        }

        // Cold path: warm the cache for this single number (rare — most are
        // pre-warmed in render()).
        $this->warmPhoneCache([$e164]);

        return $this->phoneNameCache[$e164] ?? $e164;
    }

    public function resolvePreviewSender(?string $fromNumber, ?SmsGroupThread $thread = null): ?string
    {
        if (! is_string($fromNumber) || $fromNumber === '') {
            return null;
        }

        $normalizedFrom = preg_replace('/[^0-9]/', '', $fromNumber);
        if (strlen($normalizedFrom) === 11 && str_starts_with($normalizedFrom, '1')) {
            $normalizedFrom = substr($normalizedFrom, 1);
        }

        if ($thread?->client) {
            $users = $thread->client->relationLoaded('users')
                ? $thread->client->users
                : $thread->client->users()->get();

            foreach ($users as $user) {
                $cellPhone = preg_replace('/[^0-9]/', '', (string) $user->getRawOriginal('cell_phone'));

                if ($cellPhone === '') {
                    continue;
                }

                if (strlen($cellPhone) === 11 && str_starts_with($cellPhone, '1')) {
                    $cellPhone = substr($cellPhone, 1);
                }

                if ($cellPhone === $normalizedFrom && is_string($user->first_name) && trim($user->first_name) !== '') {
                    return trim($user->first_name);
                }
            }
        }

        $display = $this->resolvePhoneDisplay($fromNumber);

        if (preg_match('/^[A-Za-z]/', $display) === 1) {
            return (string) explode(' ', trim($display))[0];
        }

        return $display;
    }

    public function render()
    {
        // Warm phone-name cache once per render so the per-row helpers stay O(1).
        $phones = collect();
        foreach ($this->threads as $thread) {
            $phones = $phones->merge($thread->threadParticipants->pluck('phone_number'));
            if ($thread->latestMessage?->from_number) {
                $phones->push($thread->latestMessage->from_number);
            }
        }
        $this->warmPhoneCache($phones->filter()->unique());

        return view('livewire.sms.thread-list');
    }

    public function placeholder()
    {
        return view('livewire.sms.thread-list-placeholder');
    }
}
