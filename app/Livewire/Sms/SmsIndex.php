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

    /** The Team tab (Hive-only conversations and calls with colleagues): roster members only. */
    public bool $showTeam = false;

    /**
     * A roster member who is not an admin (Czarek): the page is the Team tab
     * alone — no client texts, no calls — so a team message's link works.
     */
    public bool $teamOnly = false;

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

        $this->showTeam = \App\Support\Team\TeamRoster::isMember($user);
        $this->teamOnly = ! $this->isClientUser && $user->vendor_role !== 'Admin' && $this->showTeam;

        // Client users must have a client with a thread; a non-admin company
        // user gets in only as a team member, and only to the Team tab.
        if (! $this->isClientUser && $user->vendor_role !== 'Admin' && ! $this->teamOnly) {
            abort(403);
        }

        // Restore activeTab from session when not explicitly set in URL
        if (! request()->has('activeTab')) {
            $this->activeTab = session('sms_active_tab', 'messages');
        }
        if ($this->activeTab === 'team' && ! $this->showTeam) {
            $this->activeTab = 'messages';
        }
        if ($this->teamOnly) {
            $this->activeTab = 'team';
            $this->subjectFilter = 'all';
            $this->search = '';
        }

        // Strip query params that don't belong to the active tab so the URL
        // stays clean on initial load. The view's x-init also strips the
        // browser URL via replaceState.
        if ($this->activeTab === 'calls' && $this->threadId !== null) {
            $this->threadId = null;
        }
        // A thread link lands on the tab that lists it: a team conversation
        // (a push, the bell) opens under Team, a text under Messages.
        if ($this->threadId !== null && $this->activeTab !== 'calls') {
            $this->activeTab = $this->tabForThread($this->threadId);
            if ($this->teamOnly && $this->activeTab !== 'team') {
                $this->threadId = null;
                $this->activeTab = 'team';
            }
        }
        if ($this->activeTab !== 'calls') {
            // CallList holds selectedCallId; nothing to clear here. The view
            // strips ?callId= client-side on initial load.
        }
    }

    public function updatedActiveTab(string $value): void
    {
        if ($this->teamOnly && $value !== 'team') {
            $this->activeTab = 'team';

            return;
        }
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

        // Users may only view threads they can access (client or vendor rules;
        // a team-only member sees team conversations alone).
        if ($threadId !== null) {
            $allowed = SmsGroupThread::query()
                ->accessibleTo(auth()->user())
                ->when($this->teamOnly, fn ($q) => $q->teamThreads())
                ->whereKey($threadId)
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
        if ($this->teamOnly && $this->tabForThread($threadId) !== 'team') {
            return;
        }
        $this->activeTab = $this->tabForThread($threadId);
        session(['sms_active_tab' => $this->activeTab]);
        $this->selectThread($threadId);
    }

    /** 'team' for a Hive-only team conversation, 'messages' for a text thread. */
    protected function tabForThread(int $threadId): string
    {
        $isTeam = $this->showTeam && SmsGroupThread::query()->whereKey($threadId)->teamThreads()->exists();

        return $isTeam ? 'team' : 'messages';
    }

    /** Team conversations with something unread, for the dot on the Team tab. */
    public function teamUnreadCount(): int
    {
        if (! $this->showTeam) {
            return 0;
        }

        return SmsGroupThread::query()
            ->teamThreads()
            ->forTeamMember((int) auth()->id())
            ->unreadForUser((int) auth()->id())
            ->count();
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
        // One caveat vs the old inline logic: a vendor user WITHOUT a vendor
        // used to see every thread here (no scope applied); accessibleTo
        // correctly returns none — that state cannot reach this page anyway
        // (mount() aborts non-admin, and admins always have a vendor).
        return SmsGroupThread::query()
            ->accessibleTo(auth()->user())
            // Each tab auto-opens its own kind of thread, never the other's.
            ->when($this->activeTab === 'team' || $this->teamOnly, fn ($q) => $q->teamThreads(), fn ($q) => $q->textThreads())
            ->when($this->subjectFilter === 'client', fn ($q) => $q->whereNotNull('client_id'))
            ->when($this->subjectFilter === 'vendor', fn ($q) => $q->whereNotNull('subject_vendor_id'))
            ->when($this->subjectFilter === 'unread', fn ($q) => $q->unreadForUser((int) auth()->id()));
    }

    #[Title('Messages')]
    public function render()
    {
        return view('livewire.sms.index')->layout('components.layouts.app', [
            'fullscreenClasses' => '!p-0 h-full overflow-hidden flex flex-col',
        ]);
    }
}
