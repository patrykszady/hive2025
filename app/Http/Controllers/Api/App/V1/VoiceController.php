<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\PlaceAppCallRequest;
use App\Services\Voice\AppVoiceCredentials;
use App\Services\Voice\ClickToCall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        ]);
    }

    /** Place a call: Hive rings the app (or the person's cell), then dials the number. */
    public function store(PlaceAppCallRequest $request, ClickToCall $clickToCall): JsonResponse
    {
        $ringsApp = $request->validated('ring', 'app') !== 'cell';
        $result = $clickToCall->start($request->user(), [$request->validated('to')], ringApp: $ringsApp);

        if (! $result['ok']) {
            return response()->json(['message' => $result['error']], 422);
        }

        return response()->json([
            'call_log_id' => $result['call_log']->id,
            'to' => $result['targets'][0],
            'ring' => $ringsApp ? 'app' : 'cell',
        ], 201);
    }
}
