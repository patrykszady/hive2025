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
    }

    public function updatedActiveTab(string $value): void
    {
        session(['sms_active_tab' => $value]);
    }

    public function updatedSubjectFilter(string $value): void
    {
        // Tell the thread list to scroll to the top whenever the filter changes.
        $this->dispatch('sms-thread-filter-changed');

        // When the subject filter changes, jump to the latest thread that matches it
        // so the open conversation reflects the current filter (e.g. switching to
        // "Vendors" should open the most recent vendor thread).
        $latestThreadId = $this->latestAccessibleThreadId();

        if ($latestThreadId !== null && $latestThreadId !== $this->threadId) {
            $this->selectThread($latestThreadId);
        } elseif ($latestThreadId === null) {
            $this->clearThread();
        }
    }

    public function selectThread(int|null $threadId): void
    {
        // Client users may only view threads belonging to their client
        if ($this->isClientUser && $threadId !== null) {
            $clientIds = auth()->user()->clients()->pluck('clients.id');
            $allowed = SmsGroupThread::where('id', $threadId)
                ->whereIn('client_id', $clientIds)
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

        // Notify conversation directly so it can swap threads without re-mounting
        $this->dispatch('loadThread', threadId: $threadId)->to(SmsConversation::class);

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
        if ($this->threadId !== null) {
            return;
        }

        $latestThreadId = $this->latestAccessibleThreadId();

        if ($latestThreadId !== null) {
            $this->selectThread($latestThreadId);
        }
    }

    public function autoSelectSingleThreadIfOnlyOne(): void
    {
        if ($this->threadId !== null) {
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
                $clientIds = auth()->user()->clients()->pluck('clients.id');

                $query->whereIn('client_id', $clientIds);
            })
            ->when(! $this->isClientUser, function ($query) {
                $vendorId = auth()->user()->vendor?->id;

                if ($vendorId) {
                    $query->visibleToVendor($vendorId);
                }
            })
            ->when($this->subjectFilter === 'client', fn ($q) => $q->whereNotNull('client_id'))
            ->when($this->subjectFilter === 'vendor', fn ($q) => $q->whereNotNull('subject_vendor_id'));
    }

    #[Title('Messages')]
    public function render()
    {
        return view('livewire.sms.index')->layout('components.layouts.app', [
            'fullscreenClasses' => '!p-0 h-full overflow-hidden flex flex-col',
        ]);
    }
}
