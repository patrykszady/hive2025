<?php

namespace App\Livewire\Sms;

use App\Models\SmsGroupThread;
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
                if ($this->subjectFilter === 'client') {
                    $meili->whereNotNull('client_id');
                } elseif ($this->subjectFilter === 'vendor') {
                    $meili->whereNotNull('subject_vendor_id');
                }

                $ids = $meili->take(max($this->limit, 50))->keys()->all();

                if (empty($ids)) {
                    return collect();
                }

                $orderedIds = implode(',', array_map('intval', $ids));

                $threads = SmsGroupThread::query()
                    ->with([
                        'project:id,address',
                        'client',
                        'client.users:id,first_name,last_name,cell_phone',
                        'subjectVendor',
                        'latestMessage.sentByUser:id,first_name',
                        'threadParticipants:id,thread_id,phone_number',
                    ])
                    ->withCount(['messages as scheduled_messages_count' => fn ($q) => $q->where('status', 'scheduled')])
                    ->whereIn('id', $ids)
                    ->when($user->is_browsing_as_client, function ($query) use ($user) {
                        $clientIds = $user->clients()->pluck('clients.id');
                        $query->whereIn('client_id', $clientIds);
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
                'client.users:id,first_name,last_name,cell_phone',
                'subjectVendor',
                'latestMessage.sentByUser:id,first_name',
                'threadParticipants:id,thread_id,phone_number',
            ])
            ->withCount(['messages as scheduled_messages_count' => fn ($q) => $q->where('status', 'scheduled')])
            ->when($user->is_browsing_as_client, function ($query) use ($user) {
                $clientIds = $user->clients()->pluck('clients.id');
                $query->whereIn('client_id', $clientIds);
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
            ->get(['id', 'first_name', 'last_name', 'cell_phone'])
            ->keyBy(fn ($u) => preg_replace('/[^0-9]/', '', (string) $u->cell_phone));

        $vendors = Vendor::whereIn('business_phone', $needles)
            ->get(['id', 'business_name', 'business_phone', 'options'])
            ->keyBy(fn ($v) => preg_replace('/[^0-9]/', '', (string) $v->business_phone));

        foreach ($normalized as $phone => $parts) {
            $candidateKeys = array_unique([$parts['n'], '1' . $parts['n'], $parts['d'], $parts['l']]);

            $name = null;
            foreach ($candidateKeys as $key) {
                if ($user = $users->get($key)) {
                    $full = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
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
