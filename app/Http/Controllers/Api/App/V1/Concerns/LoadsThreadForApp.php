<?php

namespace App\Http\Controllers\Api\App\V1\Concerns;

use App\Models\BlockedCaller;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The eager loads and real unread count ThreadResource needs, shared by
 * ThreadController (the Messages tab) and ClientController (a client's
 * "Text" button, which opens the same thread shape from clients/{id}/thread).
 */
trait LoadsThreadForApp
{
    /**
     * @var array<int, string>
     */
    protected const THREAD_RELATIONS = [
        'project:id,address',
        'client',
        'client.users:id,first_name,last_name,nickname,cell_phone',
        'subjectVendor:id,business_name,options',
        'latestMessage.sentByUser:id,first_name,last_name,nickname',
        'threadParticipants:id,thread_id,phone_number,opted_in_at',
    ];

    /**
     * Attach a real unread-inbound-message count to each thread (as a
     * transient attribute ThreadResource reads), computed in one grouped
     * query rather than one query per thread.
     *
     * @param  Collection<int, SmsGroupThread>  $threads
     */
    protected function attachThreadUnreadCounts(Collection $threads, User $user): void
    {
        $threadIds = $threads->pluck('id')->all();

        if ($threadIds === []) {
            return;
        }

        $counts = SmsMessage::query()
            ->selectRaw('thread_id, COUNT(*) as unread_count')
            ->whereIn('thread_id', $threadIds)
            ->where('direction', SmsMessage::DIRECTION_INBOUND)
            ->whereRaw(
                'id > COALESCE((SELECT last_read_message_id FROM sms_thread_reads WHERE sms_thread_reads.thread_id = sms_messages.thread_id AND sms_thread_reads.user_id = ?), 0)',
                [$user->id]
            )
            ->groupBy('thread_id')
            ->pluck('unread_count', 'thread_id');

        $threads->each(function (SmsGroupThread $thread) use ($counts) {
            $thread->unread_count = (int) ($counts[$thread->id] ?? 0);
        });
    }

    /**
     * The withCount()/loadCount() relation for the thread list row's amber
     * clock badge — scheduled, not-yet-sent messages. Shared so index(),
     * show(), store() and assign() all count the same way.
     *
     * @return array<string, \Closure>
     */
    protected function scheduledMessagesCountRelation(): array
    {
        return ['messages as scheduled_messages_count' => fn ($q) => $q->where('status', 'scheduled')];
    }

    /**
     * Flags each thread as spam when any of its participant numbers is
     * blocked — mirrors App\Livewire\Sms\SmsThreadList::threadIsSpam()/
     * phoneMatchCandidates(), computed once for every thread on the page
     * instead of once per thread.
     *
     * @param  Collection<int, SmsGroupThread>  $threads
     */
    protected function attachThreadSpamFlags(Collection $threads): void
    {
        $blockedPhones = BlockedCaller::query()
            ->pluck('phone_number')
            ->filter(fn ($phone) => is_string($phone) && trim($phone) !== '');

        if ($blockedPhones->isEmpty()) {
            $threads->each(fn (SmsGroupThread $thread) => $thread->is_spam = false);

            return;
        }

        $blockedMap = $blockedPhones
            ->flatMap(fn (string $phone) => $this->phoneMatchCandidates($phone))
            ->flip();

        $threads->each(function (SmsGroupThread $thread) use ($blockedMap) {
            $thread->is_spam = $thread->threadParticipants
                ->pluck('phone_number')
                ->filter(fn ($phone) => is_string($phone) && trim($phone) !== '')
                ->contains(fn ($phone) => collect($this->phoneMatchCandidates($phone))->contains(fn ($candidate) => $blockedMap->has($candidate)));
        });
    }

    /**
     * @return array<int, string>
     */
    private function phoneMatchCandidates(string $phone): array
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (! is_string($digits) || $digits === '') {
            return [];
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $ten = substr($digits, 1);

            return array_values(array_unique([$digits, '+1'.$ten, $ten]));
        }

        if (strlen($digits) === 10) {
            return array_values(array_unique([$digits, '1'.$digits, '+1'.$digits]));
        }

        return array_values(array_unique([$digits, '+'.$digits]));
    }
}
