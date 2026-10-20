<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Api\App\V1\Concerns\LoadsThreadForApp;
use App\Http\Controllers\Api\App\V1\Concerns\ResolvesAppThread;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\AssignThreadRequest;
use App\Http\Requests\Api\App\StartThreadRequest;
use App\Http\Resources\Api\App\ThreadResource;
use App\Livewire\Sms\SmsNewThread;
use App\Models\Client;
use App\Models\SmsGroupThread;
use App\Models\Vendor;
use App\Services\Sms\AppThreadStarter;
use App\Services\Sms\ThreadAdminActions;
use App\Services\Sms\ThreadSearch;
use App\Services\Sms\ThreadSpamBlocker;
use App\Support\Sms\ConversationPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's thread list and new-thread endpoints. Visibility matches the
 * web Messages page exactly via SmsGroupThread::scopeAccessibleTo() — the
 * Hive Contractors app is GS team only, so every caller here is a vendor
 * user (never a client-browsing one), but the scope is reused as-is rather
 * than re-derived. index()'s search goes through App\Services\Sms\
 * ThreadSearch, the same Meilisearch (+ LIKE fallback) path App\Livewire\
 * Sms\SmsThreadList uses, so the app finds the same threads the web would.
 */
class ThreadController extends Controller
{
    use LoadsThreadForApp;
    use ResolvesAppThread;

    private const PER_PAGE = 20;

    public function index(Request $request, ThreadSearch $threadSearch): JsonResponse
    {
        $user = $request->user();
        $search = trim((string) $request->query('search', ''));
        // Mirrors the web Messages page's subject filter tabs
        // (App\Livewire\Sms\SmsIndex's subjectFilter): all/client/vendor/unread.
        $filter = (string) $request->query('filter', 'all');
        if (! in_array($filter, ['all', 'client', 'vendor', 'unread'], true)) {
            $filter = 'all';
        }

        // Fast path: the same Meilisearch search SmsThreadList uses (App\
        // Services\Sms\ThreadSearch), so the app finds the same threads the
        // web would for the same query. $ids stays null (meaning: no
        // search, or Meilisearch itself is unavailable) to fall back to the
        // LIKE query below — same reasoning as SmsThreadList's own
        // try/catch around its Meilisearch call.
        $ids = null;
        if ($search !== '') {
            try {
                $ids = $threadSearch->matchingThreadIds($search, $user->vendor?->id ? (int) $user->vendor->id : null, $filter, self::PER_PAGE);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $threads = SmsGroupThread::query()
            ->accessibleTo($user)
            ->with(self::THREAD_RELATIONS)
            ->withCount($this->scheduledMessagesCountRelation())
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->when($ids === null && $search !== '', fn ($q) => $threadSearch->applyLikeFallback($q, $search))
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
        $this->attachThreadSpamFlags($collection);

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
        $found->loadCount($this->scheduledMessagesCountRelation());

        ConversationPresenter::warmPhoneDisplays($found->threadParticipants->pluck('phone_number')->filter()->unique());
        $this->attachThreadUnreadCounts(collect([$found]), $user);
        $this->attachThreadSpamFlags(collect([$found]));

        return response()->json(['data' => (new ThreadResource($found))->withCallableContacts()]);
    }

    /**
     * The web's finer per-person recipient picker —
     * App\Livewire\Sms\SmsNewThread::buildRecipientPresetOptions()/
     * buildVendorRecipientPresetOptions() — which household members/numbers
     * of a client or vendor can be texted, instead of defaulting to
     * everyone. SmsNewThread's option-building methods are pure (no Livewire
     * state), so they're called directly here rather than duplicated.
     */
    public function recipientPresets(Request $request): JsonResponse
    {
        $clientId = $request->query('client_id');
        $vendorId = $request->query('vendor_id');

        $composer = new SmsNewThread();

        if ($vendorId) {
            // withoutGlobalScopes(): same reasoning as AppThreadStarter/
            // StartThreadRequest's vendor_id — a vendor not yet related to
            // this tenant is how a new sub relationship starts, so it must
            // still be reachable here.
            $vendor = Vendor::withoutGlobalScopes()->with('users')->find($vendorId);
            abort_unless($vendor, 404);

            $threads = SmsGroupThread::query()->where('subject_vendor_id', $vendor->id)->get(['id', 'participants']);
            $options = $composer->buildVendorRecipientPresetOptions($vendor, $threads);
        } elseif ($clientId) {
            // withoutGlobalScopes(): the client may be hidden by ClientScope
            // in the acting user's vendor context — same reasoning as
            // AppThreadStarter/ConversationPresenter::loadThreadForDisplay().
            $client = Client::withoutGlobalScopes()->with('users')->find($clientId);
            abort_unless($client, 404);

            $threads = SmsGroupThread::query()->where('client_id', $client->id)->get(['id', 'participants']);
            $options = $composer->buildRecipientPresetOptions($client->users, $threads);
        } else {
            abort(422, 'Provide client_id or vendor_id.');
        }

        return response()->json(['data' => array_map(fn (array $option) => [
            'value' => $option['value'],
            'label' => $option['label'],
            'recipients' => $option['recipients'],
            'existing_thread_id' => $option['existingThreadId'],
        ], $options)]);
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
        $thread->loadCount($this->scheduledMessagesCountRelation());
        $this->attachThreadSpamFlags(collect([$thread]));

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
        $smsThread->loadCount($this->scheduledMessagesCountRelation());
        $this->attachThreadUnreadCounts(collect([$smsThread]), $request->user());
        $this->attachThreadSpamFlags(collect([$smsThread]));

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
