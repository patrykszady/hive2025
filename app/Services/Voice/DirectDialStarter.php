<?php

namespace App\Services\Voice;

use App\Models\CallLog;
use App\Models\User;
use App\Services\Voice\Concerns\DedupesClickToCallTargets;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;

/**
 * "Direct dial" (2026-11-07): the app places the call itself through
 * Telnyx's own WebRTC SDK and Telnyx parks that leg — it never rings
 * anywhere — instead of Hive ringing the person's phone first (contrast
 * App\Services\Voice\ClickToCall). This only logs the CallLog and hands back
 * the client_state the app's SDK call must carry, so
 * TelnyxWebhookController::handleParkedDirectDialLeg() can find this row
 * again once Telnyx posts call.initiated for the parked leg and dial the
 * target(s) from there. No Telnyx API request is made here.
 *
 * The (user, target) dedupe this needs — so a burst of simultaneous requests
 * can't each create their own CallLog — is shared with ClickToCall via the
 * DedupesClickToCallTargets concern.
 */
class DirectDialStarter
{
    use DedupesClickToCallTargets;

    /**
     * @param  array<int, string|null>  $targetPhones  numbers as typed or stored
     * @return array{ok: bool, error: ?string, call_log: ?CallLog, targets: array<int, string>, client_state: ?string}
     */
    public function start(User $user, array $targetPhones): array
    {
        $targets = $this->normalizedTargets($targetPhones);

        if ($targets->isEmpty()) {
            return $this->failed('No callable number.');
        }

        $dedupeKey = self::dedupeKey($user->id, $targets);

        try {
            return $this->withDedupeLock($dedupeKey, fn () => $this->startLocked($user, $targets, $dedupeKey));
        } catch (LockTimeoutException) {
            return $this->failed('Could not place the call right now — try again in a moment.');
        }
    }

    /**
     * Run while holding the per-(user, target) lock so a burst of
     * simultaneous requests cannot each create their own CallLog — see
     * ClickToCall::startLocked()'s own docblock for the same reasoning.
     *
     * @return array{ok: bool, error: ?string, call_log: ?CallLog, targets: array<int, string>, client_state: ?string}
     */
    protected function startLocked(User $user, Collection $targets, string $dedupeKey): array
    {
        $duplicate = $this->activeDuplicate($dedupeKey);
        if ($duplicate) {
            return [
                'ok' => true,
                'error' => null,
                'call_log' => $duplicate,
                'targets' => $targets->all(),
                'client_state' => self::clientStateFor($duplicate),
            ];
        }

        $primary = $targets->first();

        $callLog = CallLog::create([
            'direction' => 'outgoing',
            'from_number' => config('services.telnyx.from'),
            'to_number' => $primary,
            'status' => CallLog::STATUS_INITIATED,
            'user_id' => $user->id,
            'vendor_id' => $user->vendor?->id,
            'metadata' => [
                'type' => 'direct_dial',
                'target_phone' => $primary,
                'target_phones' => $targets->all(),
                'target_count' => $targets->count(),
                'user_leg' => 'app',
            ],
        ]);

        $this->rememberCallLog($dedupeKey, $callLog);

        return [
            'ok' => true,
            'error' => null,
            'call_log' => $callLog,
            'targets' => $targets->all(),
            'client_state' => self::clientStateFor($callLog),
        ];
    }

    /**
     * The client_state the app's SDK call must carry (clientState on
     * newCall()) so Telnyx echoes it back on the parked leg's call.initiated
     * webhook — the primary way handleParkedDirectDialLeg() correlates that
     * leg to this CallLog.
     */
    public static function clientStateFor(CallLog $callLog): string
    {
        return base64_encode(json_encode([
            'action' => 'direct_dial',
            'call_log_id' => $callLog->id,
        ]));
    }

    /** @return array{ok: bool, error: ?string, call_log: ?CallLog, targets: array<int, string>, client_state: ?string} */
    protected function failed(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'call_log' => null, 'targets' => [], 'client_state' => null];
    }
}
