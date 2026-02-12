<?php

namespace App\Livewire\Sms;

use App\Models\SmsGroupThread;
use App\Models\SmsThreadRead;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Livewire\WithPagination;

class SmsThreadList extends Component
{
    use WithPagination;

    #[Reactive]
    public string $search = '';

    #[Reactive]
    public ?int $selectedThreadId = null;

    public function updating($field): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function threads()
    {
        return SmsGroupThread::query()
            ->with([
                'project',
                'client.users',
                'messages' => fn ($query) => $query->latest()->limit(1),
                'reads' => fn ($query) => $query
                    ->where('user_id', auth()->id())
                    ->select('id', 'thread_id', 'user_id', 'last_read_message_id'),
            ])
            ->when(trim($this->search), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->whereJsonContains('participants', $search)
                        ->orWhereHas('project', fn ($pq) => $pq->where('address', 'like', "%{$search}%"))
                        ->orWhereHas('messages', fn ($mq) => $mq->where('text', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('last_activity_at')
            ->paginate(20);
    }

    #[Computed]
    public function unreadThreadIds(): array
    {
        $threadCollection = $this->threads->getCollection();

        $latestInboundByThread = $threadCollection
            ->mapWithKeys(fn ($thread) => [$thread->id => $thread->messages->first()])
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

    public function select(int $threadId): void
    {
        $this->dispatch('threadSelected', threadId: $threadId)->to(SmsIndex::class);
    }

    public function render()
    {
        return view('livewire.sms.thread-list');
    }

    public function placeholder()
    {
        return view('livewire.sms.thread-list-placeholder');
    }
}
