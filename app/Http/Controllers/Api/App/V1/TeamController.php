<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\OpenTeamThreadRequest;
use App\Http\Requests\Api\App\PlaceTeamCallRequest;
use App\Models\SmsGroupThread;
use App\Services\Voice\InternalCalls;
use App\Support\Team\TeamRoster;
use App\Support\Team\TeamRows;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Team tab in the Hive Contractors app (2026-11-14, Patryk: "messages
 * and calls should be on the same link, as well as team, to match what we
 * have on the website"): the same rows as the website's Team tab
 * (App\Support\Team\TeamRows) — the whole team, then each teammate — each a
 * Hive-only conversation that the app opens in its usual thread screen.
 */
class TeamController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = collect(TeamRows::for($request->user()))->map(fn (array $row) => [
            'key' => $row['key'],
            'user_id' => $row['user_id'],
            'thread_id' => $row['thread_id'],
            'title' => $row['title'],
            'preview' => $row['preview'],
            'at' => $row['at']?->toIso8601String(),
            'unread' => $row['unread'],
            'is_group' => $row['is_group'],
        ])->values();

        return response()->json(['data' => $rows]);
    }

    /** Open (making it on first use) the conversation with one teammate, or the whole team's. */
    public function open(OpenTeamThreadRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(TeamRoster::isMember($user), 403, 'Only the team has team conversations.');

        $teammateId = $request->validated('user_id');
        if ($teammateId !== null) {
            $teammate = TeamRoster::teammatesOf($user)->firstWhere('id', (int) $teammateId);
            abort_unless($teammate !== null, 403, 'That person is not on your team.');
            $thread = SmsGroupThread::teamThreadFor([(int) $user->id, (int) $teammate->id], (int) $user->vendor->id);
            $title = TeamRows::nameOf($teammate);
        } else {
            $thread = TeamRoster::groupThread($user->vendor);
            $title = TeamRoster::groupName($user->vendor);
        }

        return response()->json(['data' => ['id' => $thread->id, 'title' => $title]]);
    }

    /**
     * Call a teammate (user_id) or the whole crew (none) inside Hive, as the
     * website's Team tab does (InternalCalls): Hive rings this person's own
     * login first, marked X-Hive-Outbound-Call, which the app answers by
     * itself — the same ring-back as any call it places — then the callee.
     * Answers in the shape of POST calls, under a handle the app keeps for
     * the call screen and for a redial from the phone's recent calls.
     */
    public function call(PlaceTeamCallRequest $request, InternalCalls $calls): JsonResponse
    {
        $user = $request->user();
        abort_unless(TeamRoster::isMember($user), 403, 'Only the team can call each other in Hive.');

        $teammateId = $request->validated('user_id');
        if ($teammateId !== null) {
            $teammate = TeamRoster::teammatesOf($user)->firstWhere('id', (int) $teammateId);
            abort_unless($teammate !== null, 403, 'That person is not on your team.');
            $result = $calls->start($user, $teammate);
            $name = InternalCalls::displayName($teammate);
        } else {
            $result = $calls->startCrew($user);
            $name = TeamRoster::groupName($user->vendor);
        }

        if (! $result['ok']) {
            return response()->json(['message' => $result['error']], 422);
        }

        $handle = 'hive-team:'.($teammateId !== null ? (int) $teammateId : 'crew');

        return response()->json([
            'call_log_id' => $result['call_log']->id,
            'to' => $handle,
            'targets' => [$handle],
            'ring' => 'app',
            'display_name' => $name,
        ], 201);
    }
}
