<?php

namespace App\Services\Calls;

use App\Models\CallLog;
use App\Models\User;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The person pressed End in the Hive Contractors app. The app's calling SDK
 * says goodbye to Telnyx over its own socket, and that goodbye can be lost
 * with the socket (call_log 3091, 2026-11-12: the app hung up, Telnyx never
 * heard it, and the person called stayed on a live, recorded line for 72 s).
 * So the app also tells Hive, by call log, and Hive ends the call's legs
 * itself through Call Control.
 *
 * Only the call's legs are touched here: TelnyxWebhookController still owns
 * the CallLog's status, duration and metadata, from the call.hangup webhooks
 * these commands produce. The one exception is a direct-dial call with no
 * leg at Telnyx yet (the person cancelled before their parked leg arrived):
 * no webhook will ever come for it, so it is closed here, which also keeps a
 * parked leg that turns up late from being matched to it and dialing out.
 */
class AppCallHangup
{
    /** The CallLog metadata types the app places itself — see ClickToCall and DirectDialStarter. */
    public const TYPES = ['direct_dial', 'click_to_call', 'click_to_call_multi'];

    /** Whether this person placed this call. */
    public function placedBy(User $user, CallLog $callLog): bool
    {
        return $callLog->direction === 'outgoing' && (int) $callLog->user_id === (int) $user->id;
    }

    /** Whether this is a call the app places through Hive (a click-to-call or a direct dial). */
    public function supports(CallLog $callLog): bool
    {
        return in_array($callLog->metadata['type'] ?? null, self::TYPES, true);
    }

    /**
     * End the call's legs at Telnyx.
     *
     * @return array{status: string, ended: bool, legs: int}
     */
    public function hangUp(User $user, CallLog $callLog, string $reason): array
    {
        $callLog = $callLog->fresh() ?? $callLog;

        if (! in_array($callLog->status, CallLog::ACTIVE_STATUSES, true)) {
            return $this->result($callLog, 0);
        }

        if ($this->cancelBeforeAnyLeg($callLog)) {
            Log::channel('telnyx')->info('App hangup: call cancelled before its leg reached Telnyx', [
                'call_log_id' => $callLog->id,
                'user_id' => $user->id,
                'reason' => $reason,
            ]);

            return $this->result($callLog, 0);
        }

        $legIds = $this->legsToHangUp($callLog);
        $accepted = $this->sendHangups($callLog, $legIds);

        Log::channel('telnyx')->info('App hangup: the app asked Hive to end the call', [
            'call_log_id' => $callLog->id,
            'user_id' => $user->id,
            'reason' => $reason,
            'status' => $callLog->status,
            'legs' => $legIds,
            'accepted' => $accepted,
        ]);

        return $this->result($callLog->fresh() ?? $callLog, count($legIds));
    }

    /**
     * A direct-dial call whose parked leg has not reached Telnyx yet has
     * nothing to hang up and will get no webhook: close the log itself.
     */
    protected function cancelBeforeAnyLeg(CallLog $callLog): bool
    {
        if ($callLog->status !== CallLog::STATUS_INITIATED || filled($callLog->call_control_id)) {
            return false;
        }

        $callLog->update([
            'status' => CallLog::STATUS_MISSED,
            'hangup_cause' => 'originator_cancel',
            'ended_at' => now(),
        ]);

        return true;
    }

    /**
     * The person's own leg, and every dialed leg still ringing or in the
     * conference — unless a teammate they pulled in is still talking to the
     * person called, in which case only their own leg leaves (the same rule
     * as TelnyxWebhookController::hangupConnectedClickToCallTargets()).
     *
     * @return array<int, string>
     */
    protected function legsToHangUp(CallLog $callLog): array
    {
        $metadata = $callLog->metadata ?? [];
        $ownLegId = filled($callLog->call_control_id) ? (string) $callLog->call_control_id : null;

        $teammateStillOnCall = collect($metadata['admin_call_control_ids'] ?? [])
            ->contains(fn ($id) => is_string($id) && $id !== '' && $id !== $ownLegId);

        $dialedLegIds = $teammateStillOnCall ? collect() : collect($metadata['target_call_control_ids'] ?? [])
            ->merge($metadata['joined_target_call_control_ids'] ?? [])
            ->push($metadata['target_call_control_id'] ?? null)
            ->diff($metadata['failed_target_call_control_ids'] ?? []);

        return collect([$ownLegId])
            ->merge($dialedLegIds)
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * One hangup command per leg, all at once. A leg that is already gone
     * answers 90018/90015, which is the normal case when the app's own
     * goodbye did arrive.
     *
     * @param  array<int, string>  $legIds
     * @return int how many commands Telnyx accepted
     */
    protected function sendHangups(CallLog $callLog, array $legIds): int
    {
        $apiKey = config('services.telnyx.api_key');

        if (! $apiKey || $legIds === []) {
            return 0;
        }

        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (string $legId) => $pool->as($legId)
                ->withToken($apiKey)
                ->connectTimeout((int) config('services.telnyx.command_connect_timeout', 5))
                ->timeout((int) config('services.telnyx.command_timeout', 10))
                ->post("https://api.telnyx.com/v2/calls/{$legId}/actions/hangup", ['command_id' => (string) Str::uuid()]),
            $legIds,
        ));

        $accepted = 0;

        foreach ($legIds as $legId) {
            $response = $responses[$legId] ?? null;

            if ($response instanceof Response && $response->successful()) {
                $accepted++;

                continue;
            }

            $code = $response instanceof Response ? (string) data_get($response->json(), 'errors.0.code') : '';
            $alreadyGone = in_array($code, ['90018', '90015'], true);

            Log::channel('telnyx')->{$alreadyGone ? 'info' : 'warning'}('App hangup: leg not hung up', [
                'call_log_id' => $callLog->id,
                'call_control_id' => $legId,
                'status' => $response instanceof Response ? $response->status() : null,
                'error' => $response instanceof Response ? $response->json() : ($response instanceof \Throwable ? $response->getMessage() : 'no response'),
            ]);
        }

        return $accepted;
    }

    /** @return array{status: string, ended: bool, legs: int} */
    protected function result(CallLog $callLog, int $legs): array
    {
        return [
            'status' => (string) $callLog->status,
            'ended' => ! in_array($callLog->status, CallLog::ACTIVE_STATUSES, true),
            'legs' => $legs,
        ];
    }
}
