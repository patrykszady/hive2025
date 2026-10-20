<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Api\App\V1\Concerns\ResolvesAppThread;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\ForwardMessagesRequest;
use App\Http\Resources\Api\App\ThreadResource;
use App\Models\SmsGroupThread;
use App\Services\GroupSmsService;
use App\Services\Sms\ThreadForwarder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Forward one or more messages from one thread into another —
 * App\Livewire\Sms\SmsConversation::forwardableThreads()/forwardSingleMessage()/
 * openForwardModalWithSelection()/forwardMessages(). The app only ever offers
 * the per-message "Forward" action (one message at a time), which is the
 * same code path the web's multi-select forward bar uses underneath.
 */
class ThreadForwardController extends Controller
{
    use ResolvesAppThread;

    /**
     * Candidate destination threads for a forward — excludes the source
     * thread itself, same visibility and search as the web's picker.
     */
    public function targets(Request $request, int $thread, ThreadForwarder $forwarder): JsonResponse
    {
        $user = $request->user();
        $this->resolveAppThread($thread, $user);

        $threads = $forwarder->candidateThreads(
            $user,
            false, // the Hive Contractors app is GS team only — never a client-browsing user.
            $thread,
            (string) $request->query('search', ''),
        );

        return response()->json(['data' => ThreadResource::collection($threads)]);
    }

    public function store(ForwardMessagesRequest $request, int $thread, ThreadForwarder $forwarder, GroupSmsService $smsService): JsonResponse
    {
        $user = $request->user();
        $sourceThread = $this->resolveAppThread($thread, $user);

        $targetThreadId = (int) $request->validated('target_thread_id');

        if ($targetThreadId === $thread) {
            return response()->json(['message' => 'Pick a different conversation than the current one.'], 422);
        }

        $allowedThreadIds = $forwarder->candidateThreads($user, false, $thread)->pluck('id')->all();
        if (! in_array($targetThreadId, $allowedThreadIds, true)) {
            abort(403, 'You do not have access to the selected conversation.');
        }

        $targetThread = SmsGroupThread::findOrFail($targetThreadId);

        $result = $forwarder->forward(
            $sourceThread,
            $targetThread,
            (array) $request->validated('message_ids'),
            (int) $user->id,
            $smsService,
        );

        if (! $result['ok'] && $result['reason'] === 'pending_opt_in') {
            return response()->json(['message' => 'The target conversation has participants who have not replied START yet.'], 422);
        }

        if (! $result['ok']) {
            return response()->json(['message' => 'The selected messages could not be found.'], 422);
        }

        return response()->json(['forwarded' => $result['count']], 201);
    }
}
