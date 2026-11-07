<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\PlaceAppCallRequest;
use App\Models\CallLog;
use App\Services\Calls\CallHistory;
use App\Services\Voice\AppVoiceCredentials;
use App\Services\Voice\ClickToCall;
use App\Services\Voice\DirectDialStarter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Calling for the Hive Contractors app. The app's Telnyx SDK signs in with
 * the token from here; a call to a homeowner or sub is placed through Hive
 * (ClickToCall): Hive rings the app first, marked X-Hive-Outbound-Call so the
 * app answers it as the call it just started, then dials the number from the
 * business line, recorded and logged like any Hive call.
 */
class VoiceController extends Controller
{
    /** A short-lived sign-in token for the app's calling SDK. */
    public function token(Request $request, AppVoiceCredentials $voice): JsonResponse
    {
        if (! $voice->enabled()) {
            return response()->json(['message' => 'Calling from the app is not set up yet.'], 503);
        }

        $credential = $voice->credentialFor($request->user());

        return response()->json([
            'token' => $voice->tokenFor($request->user()),
            'sip_username' => $credential->sip_username,
            'caller_id' => (string) config('services.telnyx.from'),
            // True only once Patryk has turned the Telnyx side of direct
            // dial on too (outbound.call_parking_enabled on this connection)
            // — see App\Services\Voice\DirectDialStarter.
            'direct_dial' => $voice->directDialEnabled(),
        ]);
    }

    /**
     * The inbound call ringing this person's app right now, for the phone's
     * call screen when the push carried too little (the caller's name and
     * number, and the call log to open). Null once it is answered or gone.
     */
    public function ringing(Request $request, CallHistory $history): JsonResponse
    {
        $ringing = Cache::get("telnyx_app_ringing:{$request->user()->id}");
        $call = is_array($ringing) && ! empty($ringing['call_log_id']) ? CallLog::query()->visibleToMessagesUser($request->user())->find($ringing['call_log_id']) : null;

        if (! $call || in_array($call->status, [CallLog::STATUS_COMPLETED, CallLog::STATUS_MISSED, CallLog::STATUS_FAILED, CallLog::STATUS_BLOCKED], true)) {
            return response()->json(['data' => null]);
        }

        $history->resolveNumbers([$call]);
        $phone = $history->otherPartyNumber($call);

        return response()->json(['data' => [
            'call_log_id' => $call->id,
            'caller' => ['name' => $history->nameFor($phone) ?? $call->caller_name, 'phone' => $phone],
            'started_at' => $call->created_at?->toIso8601String(),
        ]]);
    }

    /**
     * Place a call: Hive rings the app (or the person's cell), then dials
     * the number(s) — a single `to`, or every thread participant at once
     * for the conversation screen's "Call All" (App\Livewire\Sms\
     * SmsConversation::initiateCallAll()).
     */
    public function store(PlaceAppCallRequest $request, ClickToCall $clickToCall, DirectDialStarter $directDial): JsonResponse
    {
        $ring = $request->validated('ring', 'app');
        $targets = $request->validated('targets') ?? [$request->validated('to')];

        if ($ring === 'direct') {
            return $this->storeDirect($request, $directDial, $targets);
        }

        $ringsApp = $ring !== 'cell';
        $recipientIds = array_map('intval', (array) $request->validated('recipient_ids', []));
        $result = $clickToCall->start($request->user(), $targets, $recipientIds, ringApp: $ringsApp);

        if (! $result['ok']) {
            return response()->json(['message' => $result['error']], 422);
        }

        return response()->json([
            'call_log_id' => $result['call_log']->id,
            'to' => $result['targets'][0],
            'targets' => $result['targets'],
            'ring' => $ringsApp ? 'app' : 'cell',
        ], 201);
    }

    /**
     * Direct dial: no Telnyx call is placed here — the app's own SDK parks
     * its own leg at Telnyx, and TelnyxWebhookController::
     * handleParkedDirectDialLeg() dials the target(s) once that leg is
     * confirmed. The app must carry the returned `client_state` on its
     * client.newCall() so that webhook can find this CallLog again.
     *
     * @param  array<int, string|null>  $targets
     */
    protected function storeDirect(PlaceAppCallRequest $request, DirectDialStarter $directDial, array $targets): JsonResponse
    {
        $result = $directDial->start($request->user(), $targets);

        if (! $result['ok']) {
            return response()->json(['message' => $result['error']], 422);
        }

        return response()->json([
            'call_log_id' => $result['call_log']->id,
            'to' => $result['targets'][0],
            'targets' => $result['targets'],
            'ring' => 'direct',
            'client_state' => $result['client_state'],
        ], 201);
    }
}
