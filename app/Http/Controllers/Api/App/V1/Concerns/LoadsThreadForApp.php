<?php

namespace App\Http\Controllers\Api\App\V1\Concerns;

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
        'threadParticipants:id,thread_id,phone_number',
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
}
