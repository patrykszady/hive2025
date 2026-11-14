<?php

namespace App\Services\Voice;

use App\Models\CallLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A business call answered in a browser (the team phone on every page).
 *
 * Browsers no longer sit signed in to Telnyx — one that did took the ring
 * for the person's calling login and their iPhone was never woken (call
 * 3126, 2026-11-14). A browser shows the ring from Hive (TeamPhoneRinging)
 * and signs in only on Answer; Hive then rings that login once more, marked
 * X-Hive-Outbound-Call so the browser that asked answers it by itself. The
 * new leg is one more of the person's legs on the call: answering it stops
 * their cell and the app's ring (hangUpPersonsOtherLegs), and it connects at
 * once like the app's (TelnyxWebhookController::handleAdminRingAnswered).
 */
class BrowserAnswer
{
    public function __construct(private AppVoiceCredentials $appVoice) {}

    /** Whether this business call is ringing $user right now: one of their legs on it is still alive and they have not joined it. */
    public function isRinging(CallLog $callLog, User $user): bool
    {
        if ($callLog->direction !== 'incoming' || in_array($callLog->status, [CallLog::STATUS_COMPLETED, CallLog::STATUS_MISSED, CallLog::STATUS_FAILED, CallLog::STATUS_VOICEMAIL, CallLog::STATUS_BLOCKED], true)) {
            return false;
        }

        if (in_array((int) $user->id, array_map('intval', (array) ($callLog->metadata['joined_admin_ids'] ?? [])), true)) {
            return false;
        }

        return collect((array) Cache::get($this->legsKey($callLog, $user), []))
            ->contains(fn ($leg) => is_string($leg) && $leg !== '' && ! Cache::has("telnyx_admin_dead:{$leg}"));
    }

    /**
     * Ring $user's login into the call, for the browser that just signed in.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function answer(CallLog $callLog, User $user): array
    {
        if (! $this->isRinging($callLog, $user)) {
            return ['ok' => false, 'error' => 'That call has ended.'];
        }

        $sipUri = $this->appVoice->enabled() ? $this->appVoice->sipUriFor($user) : null;
        if (! $sipUri || ! $callLog->call_control_id) {
            return ['ok' => false, 'error' => 'Calling in Hive is not set up for you yet.'];
        }

        try {
            $response = Http::withToken((string) config('services.telnyx.api_key'))->timeout(15)->post('https://api.telnyx.com/v2/calls', [
                'connection_id' => (string) config('services.telnyx.connection_id'),
                'to' => $sipUri,
                'from' => (string) config('services.telnyx.from'),
                'from_display_name' => \App\Http\Controllers\Api\TelnyxWebhookController::telnyxDisplayName($callLog->caller_name ?: $callLog->from_number),
                'timeout_secs' => 20,
                'custom_headers' => [
                    ['name' => 'X-Hive-Outbound-Call', 'value' => (string) $callLog->id],
                    ['name' => 'X-Hive-Call-Log', 'value' => (string) $callLog->id],
                    ['name' => 'X-Hive-Caller-Number', 'value' => (string) (ClickToCall::normalize((string) $callLog->from_number) ?? '')],
                ],
                'client_state' => base64_encode(json_encode([
                    'action' => 'admin_ring',
                    'leg' => 'browser',
                    'call_log_id' => $callLog->id,
                    'incoming_call_control_id' => $callLog->call_control_id,
                    'admin_user_id' => $user->id,
                ])),
                'webhook_url' => ClickToCall::webhookUrl(),
            ]);
        } catch (\Throwable $e) {
            Log::channel('telnyx')->warning('Browser answer: could not ring the browser', ['call_log_id' => $callLog->id, 'user_id' => $user->id, 'error' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'Your browser could not be rung. Try again.'];
        }

        $leg = $response->successful() ? $response->json('data.call_control_id') : null;
        if (! $leg) {
            Log::channel('telnyx')->warning('Browser answer: Telnyx refused the browser leg', ['call_log_id' => $callLog->id, 'user_id' => $user->id, 'status' => $response->status()]);

            return ['ok' => false, 'error' => 'Your browser could not be rung. Try again.'];
        }

        // One more of the person's legs: answering it stops the others, and
        // while it rings the call is not left with nobody (no voicemail when
        // the cell's own ring is stopped or runs out).
        Cache::put($this->legsKey($callLog, $user), array_values(array_unique([...(array) Cache::get($this->legsKey($callLog, $user), []), $leg])), now()->addMinutes(10));
        $callLog->refresh();
        $metadata = $callLog->metadata ?? [];
        $metadata['admin_call_control_ids'] = array_values(array_unique([...(array) ($metadata['admin_call_control_ids'] ?? []), $leg]));
        $callLog->update(['metadata' => $metadata]);

        Log::channel('telnyx')->info('Answered in a browser — ringing the browser into the call', ['call_log_id' => $callLog->id, 'user_id' => $user->id, 'browser_call_control_id' => $leg]);

        return ['ok' => true, 'error' => null];
    }

    /** The name a browser's ring card shows: the caller's, else their number. */
    public static function callerName(CallLog $callLog): string
    {
        return trim((string) $callLog->caller_name) !== '' ? (string) $callLog->caller_name : (string) $callLog->from_number;
    }

    private function legsKey(CallLog $callLog, User $user): string
    {
        return "telnyx_person_legs:{$callLog->id}:{$user->id}";
    }
}
