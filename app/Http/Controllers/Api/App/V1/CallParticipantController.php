<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\AddCallParticipantRequest;
use App\Models\CallLog;
use App\Models\User;
use App\Services\Calls\ConferenceInviter;
use App\Services\Calls\ConferenceParticipantSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Add anyone to a live call — the app's version of the web's "Add
 * Participant" button on the global "In a call" badge
 * (App\Livewire\Sms\CallStatusBadge): search the team, the people tied to
 * the call, anyone else the company knows, or a typed number, then dial
 * one of them straight into the conference. Both steps go through
 * App\Services\Calls\ConferenceParticipantSearch and
 * App\Services\Calls\ConferenceInviter, the same services the web component
 * now delegates to, so the app offers and dials exactly the same people
 * under exactly the same guards.
 */
class CallParticipantController extends Controller
{
    public function search(Request $request, int $call, ConferenceParticipantSearch $search, ConferenceInviter $inviter): JsonResponse
    {
        $user = $request->user();
        $callLog = $this->visibleCall($user, $call);

        if (! $inviter->isOnCall($user, $callLog)) {
            abort(403, 'You are not on this call.');
        }

        return response()->json([
            'data' => $search->search($user, $callLog, (string) $request->query('query', '')),
        ]);
    }

    public function store(AddCallParticipantRequest $request, int $call, ConferenceParticipantSearch $search, ConferenceInviter $inviter): JsonResponse
    {
        $user = $request->user();
        $callLog = $this->visibleCall($user, $call);

        if (! $inviter->isOnCall($user, $callLog)) {
            abort(403, 'You are not on this call.');
        }

        $candidate = $search->resolve($user, $callLog, (string) $request->validated('key'));
        if (! $candidate) {
            return response()->json(['message' => 'That person could not be found.'], 422);
        }

        $result = $inviter->invite($user, $call, $candidate['participant_id'], $candidate['phone'], $candidate['name']);

        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json([
            'invited' => true,
            'participant' => [
                'name' => $candidate['name'],
                'kind' => $candidate['kind'],
                'phone' => $candidate['phone'],
            ],
            'message' => $result['message'],
        ], 201);
    }

    /** A call the person may see: 404 when it doesn't exist, 403 when it isn't theirs to see. Mirrors CallLogController::visibleCall(). */
    protected function visibleCall(User $user, int $callId): CallLog
    {
        abort_unless(CallLog::query()->whereKey($callId)->exists(), 404);

        $callLog = CallLog::query()->visibleToMessagesUser($user)->find($callId);
        abort_unless($callLog, 403, 'You do not have access to this call.');

        return $callLog;
    }
}
