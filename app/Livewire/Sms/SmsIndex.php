<?php

namespace App\Livewire\Sms;

use App\Models\SmsGroupThread;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

// Child components driven via events for snappy thread switching:
// - SmsConversation listens for 'loadThread' to swap threads without re-mounting

class SmsIndex extends Component
{
    #[Url(except: '')]
    public string $search = '';

    #[Url(except: null)]
    public ?int $threadId = null;

    #[Url(except: 'messages')]
    public string $activeTab = 'messages';

    #[Url(except: 'all')]
    public string $subjectFilter = 'all';

    public bool $isClientUser = false;

    protected $listeners = [
        'threadCreated' => 'selectThread',
        'threadSelected' => 'selectThread',
        'threadDeleted' => 'handleThreadDeleted',
        'switchToThread' => 'switchToThread',
    ];

    public function mount(): void
    {
        $user = auth()->user();
        $this->isClientUser = (bool) $user->is_browsing_as_client;

        // Client users must have a client with a thread; non-admin non-client users cannot access
        if (! $this->isClientUser && $user->vendor_role !== 'Admin') {
            abort(403);
        }

        // Restore activeTab from session when not explicitly set in URL
        if (! request()->has('activeTab')) {
            $this->activeTab = session('sms_active_tab', 'messages');
        }

        // Strip query params that don't belong to the active tab so the URL
        // stays clean on initial load. The view's x-init also strips the
        // browser URL via replaceState.
        if ($this->activeTab === 'calls' && $this->threadId !== null) {
            $this->threadId = null;
        }
        if ($this->activeTab !== 'calls') {
            // CallList holds selectedCallId; nothing to clear here. The view
            // strips ?callId= client-side on initial load.
        }
    }

    public function updatedActiveTab(string $value): void
    {
        session(['sms_active_tab' => $value]);

        if ($value === 'calls') {
            // Drop ?threadId=... from the URL when leaving the messages tab.
            $this->threadId = null;
            $this->dispatch('calls-tab-opened')->to(CallList::class);
        } else {
            // Drop ?callId=... from the URL when leaving the calls tab.
            $this->dispatch('messages-tab-opened')->to(CallList::class);
        }
    }

    public function updatedSubjectFilter(string $value): void
    {
        // Tell the thread list to scroll to the top whenever the filter changes.
        $this->dispatch('sms-thread-filter-changed');

        // When the subject filter changes on DESKTOP, jump to the latest thread that
        // matches it so the open conversation reflects the current filter. On MOBILE
        // we don't want to surprise the user by opening a thread (which would push
        // them off the thread list into the conversation panel) — so the client-side
        // dispatches `sms-subject-filter-changed` and only calls
        // `autoSelectLatestForFilter` when on desktop.
        $this->dispatch('sms-subject-filter-changed');
    }

    public function autoSelectLatestForFilter(): void
    {
        // Never auto-open a thread when filtering to Unread — opening marks it
        // read, which would immediately drop it out of the filtered list.
        if ($this->subjectFilter === 'unread') {
            return;
        }

        $latestThreadId = $this->latestAccessibleThreadId();

        if ($latestThreadId !== null && $latestThreadId !== $this->threadId) {
            $this->selectThread($latestThreadId);
        } elseif ($latestThreadId === null) {
            $this->clearThread();
        }
    }

    public function selectThread(int|null $threadId, bool $skipConversationLoad = false): void
    {
        // Tapping the already-open thread is a no-op (avoids a wasted roundtrip + re-render).
        if ($threadId !== null && $threadId === $this->threadId) {
            return;
        }

        // Client users may only view threads belonging to their client
        if ($this->isClientUser && $threadId !== null) {
            $user = auth()->user();
            $clientIds = $user->clients()->pluck('clients.id');
            $participantPhones = $this->clientUserParticipantPhones($user);
            $allowed = SmsGroupThread::where('id', $threadId)
                ->whereIn('client_id', $clientIds)
                ->whereHas('threadParticipants', fn ($participantQuery) => $participantQuery->whereIn('phone_number', $participantPhones))
                ->exists();

            if (! $allowed) {
                return;
            }
        } elseif (! $this->isClientUser && $threadId !== null) {
            $vendorId = auth()->user()->vendor?->id;
            $allowed = $vendorId && SmsGroupThread::where('id', $threadId)
                ->visibleToVendor($vendorId)
                ->exists();

            if (! $allowed) {
                return;
            }
        }

        $this->threadId = $threadId;

        // Notify conversation directly so it can swap threads without re-mounting.
        // When the thread row is tapped, the browser already dispatches `loadThread`
        // to the (isolated) SmsConversation in parallel with this request, so we skip
        // the redundant server-side re-dispatch to avoid an extra roundtrip.
        if (! $skipConversationLoad) {
            $this->dispatch('loadThread', threadId: $threadId)->to(SmsConversation::class);
        }

        // Browser event for Alpine-driven thread highlighting (avoids full child re-render)
        $this->js("window.dispatchEvent(new CustomEvent('thread-selected', { detail: { threadId: ".json_encode($threadId)." } }))");
    }

    public function switchToThread(int $threadId): void
    {
        $this->activeTab = 'messages';
        session(['sms_active_tab' => 'messages']);
        $this->selectThread($threadId);
    }

    public function clearThread(): void
    {
        $this->threadId = null;
        $this->dispatch('loadThread', threadId: null)->to(SmsConversation::class);
        $this->js("window.dispatchEvent(new CustomEvent('thread-selected', { detail: { threadId: null } }))");
    }

    public function handleThreadDeleted(): void
    {
        $this->threadId = null;
        $this->js("window.dispatchEvent(new CustomEvent('thread-selected', { detail: { threadId: null } }))");
    }

    public function autoSelectLatestDesktopThread(): void
    {
        if ($this->threadId !== null || $this->subjectFilter === 'unread') {
            return;
        }

        $latestThreadId = $this->latestAccessibleThreadId();

        if ($latestThreadId !== null) {
            $this->selectThread($latestThreadId);
        }
    }

    public function autoSelectSingleThreadIfOnlyOne(): void
    {
        if ($this->threadId !== null || $this->subjectFilter === 'unread') {
            return;
        }

        $threadIds = $this->accessibleThreadsQuery()
            ->orderByDesc('last_activity_at')
            ->limit(2)
            ->pluck('id');

        if ($threadIds->count() === 1) {
            $this->selectThread((int) $threadIds->first());
        }
    }

    protected function latestAccessibleThreadId(): ?int
    {
        return $this->accessibleThreadsQuery()
            ->orderByDesc('last_activity_at')
            ->value('id');
    }

    protected function accessibleThreadsQuery()
    {
        return SmsGroupThread::query()
            ->when($this->isClientUser, function ($query) {
                $user = auth()->user();
                $clientIds = $user->clients()->pluck('clients.id');
                $participantPhones = $this->clientUserParticipantPhones($user);

                $query->whereIn('client_id', $clientIds)
                    ->whereHas('threadParticipants', fn ($participantQuery) => $participantQuery->whereIn('phone_number', $participantPhones));
            })
            ->when(! $this->isClientUser, function ($query) {
                $vendorId = auth()->user()->vendor?->id;

                if ($vendorId) {
                    $query->visibleToVendor($vendorId);
                }
            })
            ->when($this->subjectFilter === 'client', fn ($q) => $q->whereNotNull('client_id'))
            ->when($this->subjectFilter === 'vendor', fn ($q) => $q->whereNotNull('subject_vendor_id'))
            ->when($this->subjectFilter === 'unread', fn ($q) => $q->unreadForUser((int) auth()->id()));
    }

    /**
     * @return array<int, string>
     */
    protected function clientUserParticipantPhones($user): array
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

    #[Title('Messages')]
    public function render()
    {
        return view('livewire.sms.index')->layout('components.layouts.app', [
            'fullscreenClasses' => '!p-0 h-full overflow-hidden flex flex-col',
        ]);
    }
}
