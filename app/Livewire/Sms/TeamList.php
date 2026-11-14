<?php

namespace App\Livewire\Sms;

use App\Livewire\Sms\Concerns\MarksThreadsRead;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\User;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Isolate;
use Livewire\Component;

/**
 * The Team tab of /messages: everyone employed by the company, plus the whole
 * team as one row. Each row is a Hive-only conversation (SmsGroupThread::KIND_TEAM)
 * made on first use, with a Call button that rings the teammate's Hive
 * (TeamPhone listens for the `team-call` window event). Nothing here leaves
 * hive.contractors as a text.
 */
#[Isolate]
class TeamList extends Component
{
    use MarksThreadsRead;

    public ?int $selectedThreadId = null;

    /** @return array<string, string> */
    public function getListeners(): array
    {
        return [
            'echo-private:sms.notifications,SmsMessageReceived' => '$refresh',
            'sms-thread-read' => '$refresh',
            'sms-refresh-threads' => '$refresh',
        ];
    }

    /** Whether the viewer can ring a teammate's Hive (TeamPhone) — hides the Call buttons otherwise. */
    #[Computed]
    public function canCall(): bool
    {
        return \App\Livewire\TeamPhone::enabledFor(auth()->user());
    }

    /**
     * The team without the viewer (TeamRoster: picked on /phone-system).
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function teammates(): Collection
    {
        return \App\Support\Team\TeamRoster::teammatesOf(auth()->user());
    }

    /**
     * One row per conversation: the whole team first, then each teammate.
     * Threads that do not exist yet render with no preview; they are made
     * when the row is opened.
     *
     * @return list<array{key: string, user_id: ?int, thread_id: ?int, title: string, initial: string, preview: ?string, at: ?\Carbon\CarbonInterface, unread: bool, is_group: bool}>
     */
    #[Computed]
    public function rows(): array
    {
        return \App\Support\Team\TeamRows::for(auth()->user());
    }

    /** Open (making it on first use) the Hive-only thread with one teammate. */
    public function openTeammate(int $userId): void
    {
        $user = auth()->user();
        abort_unless($user && $this->teammates->contains('id', $userId), 403);

        $thread = SmsGroupThread::teamThreadFor([(int) $user->id, $userId], (int) $user->vendor->id);
        $this->open($thread);
    }

    /** Open the whole team's Hive-only thread. */
    public function openGroup(): void
    {
        $user = auth()->user();
        abort_unless($user && ! $user->is_browsing_as_client && $user->vendor, 403);

        $this->open(\App\Support\Team\TeamRoster::groupThread($user->vendor));
    }

    protected function open(SmsGroupThread $thread): void
    {
        unset($this->rows);
        $this->selectedThreadId = $thread->id;
        $this->dispatch('threadSelected', threadId: $thread->id)->to(SmsIndex::class);
    }

    public function render()
    {
        return view('livewire.sms.team-list');
    }
}
