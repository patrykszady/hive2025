<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\DestroyPushTokenRequest;
use App\Http\Requests\Api\App\StorePushTokenRequest;
use App\Models\PushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Registers and unregisters the Hive Contractors app's Expo push tokens —
 * see the push_tokens migration's docblock for why a row is keyed on
 * `token` rather than `user_id`, and App\Jobs\SendExpoPushForNotification
 * for how a registered token is used.
 */
class PushTokenController extends Controller
{
    /**
     * Register (or re-register) this device's current Expo push token,
     * stamped with the exact Sanctum device token this request
     * authenticated with — see AuthController::logout()'s cleanup.
     */
    public function store(StorePushTokenRequest $request): JsonResponse
    {
        $data = $request->validated();
        $accessToken = $request->user()->currentAccessToken();

        $existing = PushToken::where('token', $data['token'])->first();

        // An Expo token is keyed to the physical device, not to whoever is
        // logged in (see the push_tokens migration's docblock), so the same
        // token legitimately moves to a new owner when a shared device
        // changes hands and the previous user's session ended without
        // deleting its row first. That handoff is allowed, but it must
        // never be silent — this is the exact "can a user register another
        // user's token" gap a review flagged, so it's logged for anyone
        // auditing push delivery, and the stale row is deleted outright
        // rather than updated in place.
        if ($existing && $existing->user_id !== $request->user()->id) {
            Log::warning('Push token reassigned from one user to another', [
                'token' => $data['token'],
                'from_user_id' => $existing->user_id,
                'to_user_id' => $request->user()->id,
            ]);

            $existing->delete();
        }

        PushToken::updateOrCreate(
            ['token' => $data['token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $data['platform'],
                'device_name' => $data['device_name'] ?? null,
                // Only a real, persisted PersonalAccessToken row has an id
                // worth stamping — in a test using Sanctum::actingAs()'s
                // mocked token, ->exists is false and ->getKey() isn't a
                // real row, so it must stay null rather than fail the FK.
                'personal_access_token_id' => $accessToken?->exists ? $accessToken->getKey() : null,
                'last_seen_at' => now(),
            ],
        );

        return response()->json(['registered' => true], 201);
    }

    /**
     * Unregister this device's token — only the caller's own token can be
     * removed this way, same reasoning as every other app endpoint scoping
     * writes to $request->user().
     */
    public function destroy(DestroyPushTokenRequest $request): JsonResponse
    {
        PushToken::where('token', $request->validated('token'))
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['unregistered' => true]);
    }
}
