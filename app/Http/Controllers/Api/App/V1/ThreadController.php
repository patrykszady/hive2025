<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Api\App\V1\Concerns\LoadsThreadForApp;
use App\Http\Controllers\Api\App\V1\Concerns\ResolvesAppThread;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\AssignThreadRequest;
use App\Http\Requests\Api\App\StartThreadRequest;
use App\Http\Resources\Api\App\ThreadResource;
use App\Models\SmsGroupThread;
use App\Services\Sms\AppThreadStarter;
use App\Services\Sms\ThreadAdminActions;
use App\Services\Sms\ThreadSpamBlocker;
use App\Support\Sms\ConversationPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's thread list and new-thread endpoints. Visibility matches the
 * web Messages page exactly via SmsGroupThread::scopeAccessibleTo() — the
 * Hive Contractors app is GS team only, so every caller here is a vendor
 * user (never a client-browsing one), but the scope is reused as-is rather
 * than re-derived.
 */
class ThreadController extends Controller
{
    use LoadsThreadForApp;
    use ResolvesAppThread;

    private const PER_PAGE = 20;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $search = trim((string) $request->query('search', ''));
        // Mirrors the web Messages page's subject filter tabs
        // (App\Livewire\Sms\SmsIndex's subjectFilter): all/client/vendor/unread.
        $filter = (string) $request->query('filter', 'all');
        if (! in_array($filter, ['all', 'client', 'vendor', 'unread'], true)) {
            $filter = 'all';
        }

        $threads = SmsGroupThread::query()
            ->accessibleTo($user)
            ->with(self::THREAD_RELATIONS)
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
            ->when($filter === 'client', fn ($q) => $q->whereNotNull('client_id'))
            ->when($filter === 'vendor', fn ($q) => $q->whereNotNull('subject_vendor_id'))
            ->when($filter === 'unread', fn ($q) => $q->unreadForUser((int) $user->id))
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE);

        $collection = $threads->getCollection();

        ConversationPresenter::warmPhoneDisplays(
            $collection->flatMap(fn (SmsGroupThread $thread) => $thread->threadParticipants->pluck('phone_number'))
                ->filter()
                ->unique()
        );

        $this->attachThreadUnreadCounts($collection, $user);

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

        $found->load(self::THREAD_RELATIONS);

        ConversationPresenter::warmPhoneDisplays($found->threadParticipants->pluck('phone_number')->filter()->unique());
        $this->attachThreadUnreadCounts(collect([$found]), $user);

        return response()->json(['data' => new ThreadResource($found)]);
    }

    public function store(StartThreadRequest $request, AppThreadStarter $starter): JsonResponse
    {
        $user = $request->user();
        $clientId = $request->input('client_id');
        $vendorId = $request->input('vendor_id');

        $thread = $starter->start(
            (array) $request->input('phones', []),
            $clientId ? (int) $clientId : null,
            (string) $request->input('body'),
            $user,
            $vendorId ? (int) $vendorId : null,
        );

        $thread->loadMissing(self::THREAD_RELATIONS);

        return response()->json(['data' => new ThreadResource($thread)], 201);
    }

    /**
     * Delete a thread and everything in it — App\Livewire\Sms\SmsConversation::deleteThread().
     */
    public function destroy(Request $request, int $thread, ThreadAdminActions $adminActions): JsonResponse
    {
        $smsThread = $this->resolveAppThread($thread, $request->user());

        $adminActions->delete($smsThread);

        return response()->json(['deleted' => true]);
    }

    /**
     * Assign (or re-assign) this thread to a client or a vendor —
     * App\Livewire\Sms\SmsConversation::assignClient().
     */
    public function assign(AssignThreadRequest $request, int $thread, ThreadAdminActions $adminActions): JsonResponse
    {
        $smsThread = $this->resolveAppThread($thread, $request->user());

        $adminActions->assign(
            $smsThread,
            $request->validated('subject_type'),
            $request->validated('client_id') ? (int) $request->validated('client_id') : null,
            $request->validated('vendor_id') ? (int) $request->validated('vendor_id') : null,
        );

        $smsThread->refresh()->loadMissing(self::THREAD_RELATIONS);
        $this->attachThreadUnreadCounts(collect([$smsThread]), $request->user());

        return response()->json(['data' => new ThreadResource($smsThread)]);
    }

    /**
     * Block every external participant number on this thread —
     * App\Livewire\Sms\SmsConversation::markThreadAsSpam().
     */
    public function markSpam(Request $request, int $thread, ThreadSpamBlocker $spamBlocker): JsonResponse
    {
        $smsThread = $this->resolveAppThread($thread, $request->user());

        $targets = $spamBlocker->targetPhones($smsThread);

        if ($targets === []) {
            return response()->json(['message' => 'No external participant number found for this thread.'], 422);
        }

        $blocked = $spamBlocker->markAsSpam($smsThread, (int) $request->user()->id);

        return response()->json(['blocked_phones' => $blocked]);
    }

    /**
     * Unblock this thread's participant numbers —
     * App\Livewire\Sms\SmsConversation::unblockThreadSpam().
     */
    public function unmarkSpam(Request $request, int $thread, ThreadSpamBlocker $spamBlocker): JsonResponse
    {
        $smsThread = $this->resolveAppThread($thread, $request->user());

        $deleted = $spamBlocker->unblock($smsThread);

        if ($deleted === 0) {
            return response()->json(['message' => 'Thread participant numbers are not currently blocked.'], 422);
        }

        return response()->json(['blocked' => false]);
    }
}
