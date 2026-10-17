<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StartThreadRequest;
use App\Http\Resources\Api\App\ThreadResource;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\Sms\AppThreadStarter;
use App\Support\Sms\ConversationPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The app's thread list and new-thread endpoints. Visibility matches the
 * web Messages page exactly via SmsGroupThread::scopeAccessibleTo() — the
 * Hive Contractors app is GS team only, so every caller here is a vendor
 * user (never a client-browsing one), but the scope is reused as-is rather
 * than re-derived.
 */
class ThreadController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $search = trim((string) $request->query('search', ''));

        $threads = SmsGroupThread::query()
            ->accessibleTo($user)
            ->with([
                'project:id,address',
                'client',
                'client.users:id,first_name,last_name,nickname,cell_phone',
                'subjectVendor:id,business_name,options',
                'latestMessage.sentByUser:id,first_name,last_name,nickname',
                'threadParticipants:id,thread_id,phone_number',
            ])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->whereJsonContains('participants', $search)
                        ->orWhereHas('project', fn ($q) => $q->where('address', 'like', "%{$search}%"))
                        ->orWhereHas('client', fn ($q) => $q->where('business_name', 'like', "%{$search}%"))
                        ->orWhereHas('messages', fn ($q) => $q->where('text', 'like', "%{$search}%"));

                    // Phone digit search, mirroring SmsThreadList's SQL fallback.
                    $digits = preg_replace('/\D/', '', $search);
                    if (is_string($digits) && strlen($digits) >= 4) {
                        $inner->orWhereHas('threadParticipants', fn ($q) => $q->where('phone_number', 'like', "%{$digits}%"));
                    }
                });
            })
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE);

        $collection = $threads->getCollection();

        ConversationPresenter::warmPhoneDisplays(
            $collection->flatMap(fn (SmsGroupThread $thread) => $thread->threadParticipants->pluck('phone_number'))
                ->filter()
                ->unique()
        );

        $this->attachUnreadCounts($collection, $user);

        return response()->json([
            'data' => ThreadResource::collection($collection),
            'next_cursor' => $threads->nextCursor()?->encode(),
        ]);
    }

    /**
     * One thread, as the list shows it: what the app needs to open a
     * conversation from a link or a notification tap, not only from the list.
     */
    public function show(Request $request, int $thread): JsonResponse
    {
        $user = $request->user();

        $found = SmsGroupThread::query()->find($thread);
        if (! $found) {
            abort(404);
        }
        if (! SmsGroupThread::query()->accessibleTo($user)->whereKey($thread)->exists()) {
            abort(403, 'You do not have access to this conversation.');
        }

        $found->load([
            'project:id,address',
            'client',
            'client.users:id,first_name,last_name,nickname,cell_phone',
            'subjectVendor:id,business_name,options',
            'latestMessage.sentByUser:id,first_name,last_name,nickname',
            'threadParticipants:id,thread_id,phone_number',
        ]);

        ConversationPresenter::warmPhoneDisplays($found->threadParticipants->pluck('phone_number')->filter()->unique());
        $this->attachUnreadCounts(collect([$found]), $user);

        return response()->json(['data' => new ThreadResource($found)]);
    }

    public function store(StartThreadRequest $request, AppThreadStarter $starter): JsonResponse
    {
        $user = $request->user();
        $clientId = $request->input('client_id');

        $thread = $starter->start(
            (array) $request->input('phones', []),
            $clientId ? (int) $clientId : null,
            (string) $request->input('body'),
            $user,
        );

        $thread->loadMissing([
            'project:id,address',
            'client',
            'client.users:id,first_name,last_name,nickname,cell_phone',
            'subjectVendor:id,business_name,options',
            'latestMessage.sentByUser:id,first_name,last_name,nickname',
            'threadParticipants:id,thread_id,phone_number',
        ]);

        return response()->json(['data' => new ThreadResource($thread)], 201);
    }

    /**
     * Attach a real unread-inbound-message count to each thread (as a
     * transient attribute ThreadResource reads), computed in one grouped
     * query rather than one query per thread.
     *
     * @param  Collection<int, SmsGroupThread>  $threads
     */
    private function attachUnreadCounts(Collection $threads, User $user): void
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
