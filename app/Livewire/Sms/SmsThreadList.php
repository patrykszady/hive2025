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

    public ?int $selectedThreadId = null;

    #[Reactive]
    public bool $isClientUser = false;

    /** @return array<string, string> */
    public function getListeners(): array
    {
        return [
            'echo-private:sms.notifications,SmsMessageReceived' => 'handleNewMessage',
            'sms-thread-read' => '$refresh',
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

        return SmsGroupThread::query()
            ->with([
                'project:id,address',
                'client',
                'latestMessage',
            ])
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
            ->when(trim($this->search), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->whereJsonContains('participants', $search)
                        ->orWhereHas('project', fn ($pq) => $pq->where('address', 'like', "%{$search}%"))
                        ->orWhereHas('messages', fn ($mq) => $mq->where('text', 'like', "%{$search}%"));
                });
            })
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
     * Resolve a display name for an E.164 phone number.
     * Returns user name, or formatted 10-digit number as fallback.
     */
    public function resolvePhoneDisplay(string $e164): string
    {
        static $cache = [];

        if (isset($cache[$e164])) {
            return $cache[$e164];
        }

        $digits = preg_replace('/[^0-9]/', '', $e164);

        // Normalize: strip leading 1 for 11-digit US numbers
        $normalized = $digits;
        if (strlen($normalized) === 11 && str_starts_with($normalized, '1')) {
            $normalized = substr($normalized, 1);
        }

        // Also extract last 10 digits as fallback (handles non-standard E.164)
        $last10 = strlen($digits) > 10 ? substr($digits, -10) : $digits;

        // Search users by cell_phone (stored as raw digits)
        $user = User::where('cell_phone', $normalized)
            ->orWhere('cell_phone', '1' . $normalized)
            ->orWhere('cell_phone', $digits)
            ->orWhere('cell_phone', $last10)
            ->first();

        if ($user && trim($user->first_name . ' ' . $user->last_name) !== '') {
            return $cache[$e164] = trim($user->first_name . ' ' . $user->last_name);
        }

        // Search vendors by business_phone
        $vendor = Vendor::where('business_phone', $normalized)
            ->orWhere('business_phone', $last10)
            ->orWhere('business_phone', $digits)
            ->first();

        if ($vendor && $vendor->short_name) {
            return $cache[$e164] = $vendor->short_name;
        }

        // Format as (XXX) XXX-XXXX using best 10-digit version
        $display10 = strlen($normalized) === 10 ? $normalized : $last10;
        if (strlen($display10) === 10) {
            return $cache[$e164] = '(' . substr($display10, 0, 3) . ') ' . substr($display10, 3, 3) . '-' . substr($display10, 6);
        }

        return $cache[$e164] = $e164;
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
        return view('livewire.sms.thread-list');
    }

    public function placeholder()
    {
        return view('livewire.sms.thread-list-placeholder');
    }
}
