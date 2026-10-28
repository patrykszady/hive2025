<?php

namespace App\Livewire\Sms;

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
        $user = auth()->user();
        $teammates = $this->teammates;
        if (! $user || $teammates->isEmpty()) {
            return [];
        }

        $threads = SmsGroupThread::query()
            ->teamThreads()
            ->where('vendor_id', $user->vendor->id)
            ->forTeamMember((int) $user->id)
            ->with('latestMessage')
            ->get();
        $unreadIds = SmsGroupThread::query()
            ->teamThreads()
            ->forTeamMember((int) $user->id)
            ->unreadForUser((int) $user->id)
            ->pluck('id')
            ->all();

        $threadFor = function (array $memberIds) use ($threads): ?SmsGroupThread {
            $wanted = collect($memberIds)->sort()->values()->all();

            return $threads->first(fn (SmsGroupThread $t) => collect($t->memberIds())->sort()->values()->all() === $wanted);
        };
        $row = function (string $key, ?int $userId, string $title, bool $isGroup, ?SmsGroupThread $thread) use ($unreadIds): array {
            $latest = $thread?->latestMessage;

            return [
                'key' => $key,
                'user_id' => $userId,
                'thread_id' => $thread?->id,
                'title' => $title,
                'initial' => mb_strtoupper(mb_substr($title, 0, 1)),
                'preview' => $latest?->text,
                'at' => $latest?->shown_at,
                'unread' => $thread && in_array($thread->id, $unreadIds, true),
                'is_group' => $isGroup,
            ];
        };

        $rows = [$row('group', null, $this->groupName(), true, $threadFor($teammates->pluck('id')->push((int) $user->id)->all()))];
        foreach ($teammates as $mate) {
            $rows[] = $row('user-'.$mate->id, (int) $mate->id, trim((string) ($mate->nickname ?: $mate->first_name)), false, $threadFor([(int) $user->id, (int) $mate->id]));
        }

        return $rows;
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

    protected function groupName(): string
    {
        $vendor = auth()->user()?->vendor;

        return $vendor ? \App\Support\Team\TeamRoster::groupName($vendor) : 'Team';
    }

    public function render()
    {
        return view('livewire.sms.team-list');
    }
}
