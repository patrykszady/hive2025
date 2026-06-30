<?php

namespace App\Livewire\Sms;

use App\Livewire\Sms\Concerns\HasCallActions;
use App\Models\BlockedCaller;
use App\Models\CallLog;
use App\Models\SmsGroupThread;
use App\Models\User;
use App\Models\Vendor;
use App\Services\GroupSmsService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Isolate;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Flux;

#[Isolate]
class CallList extends Component
{
    use HasCallActions;

    public int $limit = 25;

    public string $callFilter = 'all';

    public string $search = '';

    #[Url(as: 'callId', except: null)]
    public ?int $selectedCallId = null;

    public bool $showNewCallModal = false;

    public string $newCallNumber = '';

    public ?int $selectedUserId = null;

    /** @var array<int> Admin user IDs to ring when call is answered (multi-recipient click-to-call) */
    public array $selectedCallRecipients = [];

    public function mount(): void
    {
        $this->normalizeCallFilter();

        // If we landed on this page with ?callId=... in the URL, propagate the
        // selection to the CallDetail right pane and the Alpine store so the
        // call is auto-opened on first paint.
        if ($this->selectedCallId) {
            $this->dispatch('call-selected', callId: $this->selectedCallId);
            $this->js("window.dispatchEvent(new CustomEvent('call-deep-linked', { detail: { callId: " . (int) $this->selectedCallId . " } }))");

            return;
        }

        // Auto-select the most recent call on desktop so the calls tab feels
        // the same as the messages tab (which auto-opens the latest thread).
        // We can't detect viewport width server-side, so dispatch a JS hook
        // that selects on >=lg only.
        $firstId = $this->firstCallId();
        if ($firstId !== null) {
            $this->js(<<<JS
                if (window.matchMedia('(min-width: 1024px)').matches) {
                    Alpine.store('sms').callId = {$firstId};
                    Livewire.dispatch('call-selected', { callId: {$firstId} });
                }
            JS);
        }
    }

    private function firstCallId(): ?int
    {
        $first = $this->calls->first();

        return $first['call']?->id ?? null;
    }

    public function rendered(): void
    {
        $this->dispatch('call-list-rendered');
    }

    #[On('openNewCall')]
    public function openNewCall(): void
    {
        $this->newCallNumber = '';
        $this->selectedUserId = null;
        $this->selectedCallRecipients = [];
        $this->showNewCallModal = true;
    }

    public function updatedSelectedUserId(): void
    {
        if ($this->selectedUserId) {
            $user = User::find($this->selectedUserId);
            if ($user?->cell_phone) {
                $this->newCallNumber = $this->formatPhone($user->routeNotificationForTelnyx() ?? $user->cell_phone);
            }
        }
    }

    public function updatedCallFilter(): void
    {
        $this->normalizeCallFilter();
        $this->limit = 25;
        $this->selectedCallId = null;
    }

    public function updatedSearch(): void
    {
        $this->limit = 25;
        $this->selectedCallId = null;
    }

    #[On('calls-tab-opened')]
    public function callsTabOpened(): void
    {
        $this->callFilter = 'all';
        $this->limit = 25;
        // Don't reset selectedCallId here — it may have just been hydrated
        // from the URL (?callId=...) during the same request.
    }

    #[On('messages-tab-opened')]
    public function messagesTabOpened(): void
    {
        $this->selectedCallId = null;
    }

    /**
     * Keep the URL (?callId=...) in sync when a call row is clicked. The
     * Alpine store is updated client-side; this listener mirrors that into
     * the Livewire property so the URL reflects the selection.
     */
    #[On('call-selected')]
    public function onCallSelected(int $callId): void
    {
        $this->selectedCallId = $callId;
    }

    #[On('call-deselected')]
    public function onCallDeselected(): void
    {
        $this->selectedCallId = null;
    }

    public function loadMore(): void
    {
        $this->limit += 25;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<User>
     */
    #[Computed(cache: true, key: 'call-list-contacts', seconds: 300)]
    public function contactUsers(): mixed
    {
        return User::whereNotNull('cell_phone')
            ->where('cell_phone', '!=', '')
            ->orderBy('first_name')
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<User>
     */
    #[Computed]
    public function callRecipients(): mixed
    {
        $vendor = auth()->user()->vendor;
        if (! $vendor) {
            return collect();
        }

        return $vendor->getAdminUsersWithCellPhones()
            ->sortBy('first_name');
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function blockedNumbers(): array
    {
        return BlockedCaller::pluck('phone_number')->all();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{call: CallLog, count: int}>
     */
    #[Computed]
    public function calls(): mixed
    {
        $user = auth()->user();
        $ourNumbers = config('services.telnyx.numbers', []);

        // Resolve a full-text search to a set of matching call IDs via
        // Meilisearch (covers caller name, phone numbers, notes, and the
        // transcript). Falls back to a SQL LIKE if the index is unavailable.
        $search = trim($this->search);
        $searchIds = null;
        $searchFailed = false;
        if ($search !== '') {
            try {
                $searchIds = CallLog::search($search)
                    ->take(200)
                    ->keys()
                    ->map(fn ($id) => (int) $id)
                    ->all();
            } catch (\Throwable $e) {
                report($e);
                $searchFailed = true;
            }
        }

        $rawCalls = CallLog::query()
            ->with('transcript')
            ->visibleToMessagesUser($user)
            ->when($search !== '' && ! $searchFailed, fn ($q) => $q->whereIn('id', $searchIds ?? []))
            ->when($search !== '' && $searchFailed, fn ($q) => $q->where(function ($inner) use ($search) {
                $like = '%' . $search . '%';
                $inner->where('caller_name', 'like', $like)
                    ->orWhere('from_number', 'like', $like)
                    ->orWhere('to_number', 'like', $like)
                    ->orWhere('notes', 'like', $like);
            }))
            ->when($this->callFilter === 'missed', fn ($q) => $q->where('status', CallLog::STATUS_MISSED))
            ->when($this->callFilter === 'voicemail', fn ($q) => $q->where('has_voicemail', true))
            ->when($this->callFilter === 'blocked', fn ($q) => $q->where('status', CallLog::STATUS_BLOCKED))
            ->when(! in_array($this->callFilter, ['blocked']), fn ($q) => $q->where('status', '!=', CallLog::STATUS_BLOCKED))
            ->when(! empty($ourNumbers), fn ($q) => $q->where(function ($q) use ($ourNumbers) {
                // Exclude phantom/loopback legs where from_number is one of our own Telnyx numbers
                $q->where('direction', '!=', 'incoming')
                    ->orWhereNotIn('from_number', $ourNumbers);
            }))
            ->orderByDesc('created_at')
            ->limit($this->limit)
            ->get();

        // Group consecutive calls from the same number with the same status
        return $this->groupConsecutiveCalls($rawCalls);
    }

    /**
     * Group consecutive calls from the same number with the same effective status.
     *
     * @return \Illuminate\Support\Collection<int, array{call: CallLog, count: int}>
     */
    protected function groupConsecutiveCalls(\Illuminate\Database\Eloquent\Collection $calls): \Illuminate\Support\Collection
    {
        $grouped = collect();
        $prevKey = null;

        foreach ($calls as $call) {
            $otherNumber = $call->direction === 'outgoing' ? $call->to_number : $call->from_number;
            $effectiveStatus = $this->effectiveStatus($call);
            $key = $otherNumber . '|' . $call->direction . '|' . $effectiveStatus;

            if ($key === $prevKey && $grouped->isNotEmpty()) {
                $last = $grouped->last();
                $last['count']++;
                $grouped->put($grouped->count() - 1, $last);
            } else {
                $grouped->push(['call' => $call, 'count' => 1]);
            }

            $prevKey = $key;
        }

        return $grouped;
    }

    /**
     * Determine effective display status, accounting for misclassified blocked calls.
     */
    public function effectiveStatus(CallLog $call): string
    {
        if ($call->status === CallLog::STATUS_BLOCKED) {
            return 'blocked';
        }

        $metadata = is_array($call->metadata) ? $call->metadata : (is_string($call->metadata) ? json_decode($call->metadata, true) : []);
        if (! empty($metadata['blocked_reason'])) {
            return 'blocked';
        }

        return $call->status;
    }

    public function generateDemoCalls(): void
    {
        $userId = auth()->id();

        $examples = [
            [
                'direction' => 'incoming',
                'from_number' => '+18474304439',
                'to_number' => '+12249993880',
                'caller_name' => null,
                'status' => CallLog::STATUS_COMPLETED,
                'duration_seconds' => 187,
                'created_at' => now()->subMinutes(12),
            ],
            [
                'direction' => 'outgoing',
                'from_number' => '+12249993880',
                'to_number' => '+13129092818',
                'caller_name' => null,
                'status' => CallLog::STATUS_COMPLETED,
                'duration_seconds' => 73,
                'created_at' => now()->subMinutes(28),
            ],
            [
                'direction' => 'incoming',
                'from_number' => '+18472123894',
                'to_number' => '+12249993880',
                'caller_name' => null,
                'status' => CallLog::STATUS_MISSED,
                'duration_seconds' => 0,
                'created_at' => now()->subHours(2),
            ],
            [
                'direction' => 'incoming',
                'from_number' => '+12249993881',
                'to_number' => '+12249993880',
                'caller_name' => null,
                'status' => CallLog::STATUS_VOICEMAIL,
                'duration_seconds' => 42,
                'has_voicemail' => true,
                'created_at' => now()->subHours(7),
            ],
            [
                'direction' => 'outgoing',
                'from_number' => '+12249993880',
                'to_number' => '+18474305555',
                'caller_name' => null,
                'status' => CallLog::STATUS_FAILED,
                'duration_seconds' => 0,
                'created_at' => now()->subDay(),
            ],
        ];

        foreach ($examples as $example) {
            $timestamp = $example['created_at'];

            CallLog::create([
                'call_id' => (string) Str::uuid(),
                'direction' => $example['direction'],
                'from_number' => $example['from_number'],
                'to_number' => $example['to_number'],
                'caller_name' => $example['caller_name'],
                'status' => $example['status'],
                'duration_seconds' => $example['duration_seconds'],
                'has_voicemail' => $example['has_voicemail'] ?? false,
                'user_id' => $userId,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }

        $this->callFilter = 'all';
        $this->limit = 25;
        $this->selectedCallId = null;

        Flux::toast(
            variant: 'success',
            heading: 'Demo calls added',
            text: 'Added sample incoming, outgoing, missed, voicemail, and failed calls.'
        );
    }

    private function normalizeCallFilter(): void
    {
        if (! in_array($this->callFilter, ['all', 'missed', 'voicemail', 'blocked'], true)) {
            $this->callFilter = 'all';
        }
    }

    public function selectCall(int $callId): void
    {
        $this->selectedCallId = $this->selectedCallId === $callId ? null : $callId;
    }

    /**
     * Place a new outbound call to the entered number.
     */
    public function placeNewCall(): void
    {
        $phone = trim($this->newCallNumber);

        if (! $phone) {
            Flux::toast(variant: 'danger', heading: 'No Number', text: 'Please enter a phone number.', duration: 3000, position: 'top right');
            return;
        }

        // Normalize to E.164
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 10) {
            $phone = '+1' . $digits;
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $phone = '+' . $digits;
        } elseif (! str_starts_with($phone, '+')) {
            $phone = '+' . $digits;
        }

        $this->showNewCallModal = false;
        $this->newCallNumber = '';
        // Pass selected recipients to the click-to-call handler
        $this->callBack($phone, $this->selectedCallRecipients);
        $this->selectedCallRecipients = [];
    }

    /**
     * Initiate a click-to-call back to a caller's number. (Implementation in HasCallActions trait.)
     */

    public function render()
    {
        return view('livewire.sms.call-list');
    }

    public function placeholder(): \Illuminate\Contracts\View\View
    {
        return view('livewire.sms.call-list-placeholder');
    }
}
