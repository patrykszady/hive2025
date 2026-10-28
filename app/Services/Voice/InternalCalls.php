<?php

namespace App\Services\Voice;

use App\Jobs\Voice\InternalCallTimeout;
use App\Jobs\Voice\SendInternalCallPush;
use App\Models\CallLog;
use App\Models\SmsGroupThread;
use App\Models\User;
use App\Services\GroupSmsService;
use App\Services\WebPushService;
use App\Support\Team\TeamRoster;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Calls between team members that stay inside Hive (Patryk, 2026-10-27):
 * Greg calls Patryk from the dashboard, Patryk's Hive rings — a push with
 * Answer on every phone and tablet that has Hive, a banner in every open
 * tab — and the two talk through their Hive calling logins, recorded like a
 * business call. Nobody's cell is dialed and no business number is used.
 *
 * Built on click-to-call's recorded conference: the caller's Hive login is
 * rung first (their browser answers its own call), then the callee's login
 * is the "target" (a SIP address instead of a phone number — the webhook
 * dials it without machine detection or the recording disclosure). A
 * closed Hive cannot ring, so the push's Answer opens Hive, which asks to
 * be rung again (answer()) and joins the waiting caller. No answer in
 * RING_SECONDS: the caller hears so, the callee gets a missed-call note in
 * their team thread. No fallback to the cell — Patryk's call.
 *
 * A crew call (startCrew) rings every other roster member the same way:
 * the first to answer opens the conference, the rest join as they pick up,
 * a decline only takes that person out, and nobody answering leaves one
 * note in the crew thread.
 */
class InternalCalls
{
    public const RING_SECONDS = 30;

    public const TYPE = 'internal_call';

    public function __construct(protected AppVoiceCredentials $appVoice) {}

    /** @return array{ok: bool, error: ?string, call_log: ?CallLog} */
    public function start(User $caller, User $callee): array
    {
        if ($caller->id === $callee->id || ! $this->areTeammates($caller, $callee)) {
            return $this->failed('You can only call people on your team.');
        }

        return $this->begin($caller, collect([$callee]), crew: false);
    }

    /** Ring the whole crew (everyone else on the roster). @return array{ok: bool, error: ?string, call_log: ?CallLog} */
    public function startCrew(User $caller): array
    {
        if (! TeamRoster::isMember($caller)) {
            return $this->failed('You can only call people on your team.');
        }
        $callees = TeamRoster::teammatesOf($caller);
        if ($callees->isEmpty()) {
            return $this->failed('Nobody else is on the crew yet.');
        }

        return $this->begin($caller, $callees, crew: true);
    }

    /**
     * @param  Collection<int, User>  $callees
     * @return array{ok: bool, error: ?string, call_log: ?CallLog}
     */
    protected function begin(User $caller, Collection $callees, bool $crew): array
    {
        if (! $this->appVoice->enabled()) {
            return $this->failed('Calling in Hive is not set up yet.');
        }

        try {
            $callerUri = $this->appVoice->credentialFor($caller)->sipUri();
        } catch (\Throwable $e) {
            Log::channel('telnyx')->error('Internal call: no calling login', ['caller' => $caller->id, 'error' => $e->getMessage()]);

            return $this->failed('Calling in Hive is not set up for you yet.');
        }

        /** @var array<int, string> $calleeUris user id => SIP address */
        $calleeUris = [];
        foreach ($callees as $callee) {
            try {
                $calleeUris[(int) $callee->id] = $this->appVoice->credentialFor($callee)->sipUri();
            } catch (\Throwable $e) {
                Log::channel('telnyx')->error('Internal call: no calling login', ['callee' => $callee->id, 'error' => $e->getMessage()]);
                if (! $crew) {
                    return $this->failed('Calling in Hive is not set up for one of you yet.');
                }
            }
        }
        if ($calleeUris === []) {
            return $this->failed('Nobody on the crew can be rung yet.');
        }
        ksort($calleeUris);

        $single = $crew ? null : $callees->first();
        $label = $crew ? 'Crew' : self::displayName($single);
        $uris = array_values($calleeUris);

        $callLog = CallLog::create([
            'direction' => 'outgoing',
            'from_number' => (string) config('services.telnyx.from'),
            'to_number' => $single ? (ClickToCall::normalize($single->cell_phone) ?: $uris[0]) : $uris[0],
            'status' => CallLog::STATUS_INITIATED,
            'user_id' => $caller->id,
            'vendor_id' => $caller->vendor?->id,
            'caller_name' => $label.' (Hive)',
            'metadata' => [
                'type' => self::TYPE,
                'internal' => true,
                'crew' => $crew,
                'caller_user_id' => $caller->id,
                'target_user_id' => $single?->id,
                'target_user_ids' => array_keys($calleeUris),
                'target_phone' => $uris[0],
                'target_phones' => $uris,
                'target_count' => count($uris),
                'user_phone' => ClickToCall::normalize($caller->cell_phone),
                'user_leg' => 'app',
            ],
        ]);

        $clientState = [
            'action' => 'click_to_call',
            'target_phone' => $uris[0],
            'target_phones' => $uris,
            'call_log_id' => $callLog->id,
            'internal' => true,
        ];

        $response = $this->dial([
            'to' => $callerUri,
            'from_display_name' => self::telnyxName($label),
            'timeout_secs' => 20,
            'custom_headers' => [['name' => 'X-Hive-Outbound-Call', 'value' => (string) $callLog->id]],
            'client_state' => base64_encode(json_encode($clientState)),
        ]);

        if (! $response || ! $response->successful()) {
            $callLog->update(['status' => CallLog::STATUS_FAILED]);
            Log::channel('telnyx')->error('Internal call: could not ring the caller\'s Hive', ['call_log_id' => $callLog->id, 'status' => $response?->status(), 'error' => $response?->json()]);

            return $this->failed('Your Hive could not be rung. Reload and try again.', $callLog);
        }

        $metadata = $callLog->metadata;
        $metadata['user_call_control_id'] = $response->json('data.call_control_id');
        $callLog->update([
            'call_control_id' => $response->json('data.call_control_id'),
            'call_session_id' => $response->json('data.call_session_id'),
            'call_leg_id' => $response->json('data.call_leg_id'),
            'metadata' => $metadata,
        ]);

        SendInternalCallPush::dispatch($callLog->id);
        InternalCallTimeout::dispatch($callLog->id)->delay(now()->addSeconds(self::RING_SECONDS));

        Log::channel('telnyx')->info('Internal call started', ['call_log_id' => $callLog->id, 'caller' => $caller->id, 'callees' => array_keys($calleeUris), 'crew' => $crew]);

        return ['ok' => true, 'error' => null, 'call_log' => $callLog];
    }

    /**
     * The callee pressed Answer (in a tab, or on the push after opening
     * Hive): ring their Hive login now, into the waiting call. The browser
     * answers its own ring (X-Hive-Outbound-Call) and the webhook joins it.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function answer(CallLog $callLog, User $callee): array
    {
        if (! $this->isCalleeOf($callLog, $callee)) {
            return ['ok' => false, 'error' => 'That call is not for you.'];
        }
        if ($this->hasEnded($callLog)) {
            return ['ok' => false, 'error' => 'That call has ended.'];
        }

        $metadata = $callLog->metadata ?? [];
        $userCallControlId = $metadata['user_call_control_id'] ?? $callLog->call_control_id;
        if (! $userCallControlId || $callLog->status === CallLog::STATUS_INITIATED) {
            return ['ok' => false, 'error' => 'not_ready'];
        }

        $caller = User::withoutGlobalScopes()->find($metadata['caller_user_id'] ?? $callLog->user_id);
        try {
            $calleeUri = $this->appVoice->credentialFor($callee)->sipUri();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Calling in Hive is not set up for you yet.'];
        }

        $response = $this->dial([
            'to' => $calleeUri,
            'from_display_name' => self::telnyxName(self::displayName($caller)),
            'timeout_secs' => 20,
            'custom_headers' => [
                ['name' => 'X-Hive-Outbound-Call', 'value' => (string) $callLog->id],
                ['name' => 'X-Hive-Call-Log', 'value' => (string) $callLog->id],
            ],
            'client_state' => base64_encode(json_encode([
                'action' => 'click_to_call_target_ring',
                'call_log_id' => $callLog->id,
                'caller_name' => $caller ? self::displayName($caller) : 'Someone',
                'target_phone' => $calleeUri,
                'target_phones' => (array) ($metadata['target_phones'] ?? [$calleeUri]),
                'user_call_control_id' => $userCallControlId,
                'internal' => true,
            ])),
        ]);

        if (! $response || ! $response->successful()) {
            Log::channel('telnyx')->error('Internal call: could not ring the callee\'s Hive for Answer', ['call_log_id' => $callLog->id, 'status' => $response?->status()]);

            return ['ok' => false, 'error' => 'Your Hive could not be rung. Try again.'];
        }

        $metadata['target_call_control_ids'] = array_values(array_unique(array_filter([...(array) ($metadata['target_call_control_ids'] ?? []), $response->json('data.call_control_id')])));
        $metadata['target_call_control_id'] = $response->json('data.call_control_id');
        $metadata['answer_requested_at'] = now()->toIso8601String();
        $metadata['answered_user_ids'] = array_values(array_unique([...(array) ($metadata['answered_user_ids'] ?? []), (int) $callee->id]));
        $callLog->update(['metadata' => $metadata]);

        return ['ok' => true, 'error' => null];
    }

    /**
     * The callee declined. One person called: the caller hears so and the
     * call ends with a missed-call note. The crew: only that person is out,
     * until everyone has declined.
     */
    public function decline(CallLog $callLog, User $callee): bool
    {
        if (! $this->isCalleeOf($callLog, $callee) || $this->hasEnded($callLog)) {
            return false;
        }

        if (! $this->isCrew($callLog)) {
            $this->endUnanswered($callLog, 'declined', self::displayName($callee).' declined the call.');

            return true;
        }

        $metadata = $callLog->metadata ?? [];
        $metadata['declined_user_ids'] = array_values(array_unique([...(array) ($metadata['declined_user_ids'] ?? []), (int) $callee->id]));
        $callLog->update(['metadata' => $metadata]);

        $everyoneDeclined = collect($this->calleeIds($callLog))->diff($metadata['declined_user_ids'])->isEmpty();
        if ($everyoneDeclined && ! $this->hasConnected($callLog)) {
            $this->endUnanswered($callLog, 'declined', 'Everyone declined the call.');
        }

        return true;
    }

    /** RING_SECONDS passed: a call nobody took ends with a word to the caller and a note to the callee. */
    public function timeOut(CallLog $callLog): void
    {
        $callLog->refresh();
        if ($this->hasConnected($callLog)) {
            return;
        }

        if (! $this->hasEnded($callLog)) {
            $callee = $this->isCrew($callLog) ? null : User::withoutGlobalScopes()->find($callLog->metadata['target_user_id'] ?? null);
            $said = $this->isCrew($callLog) ? 'Nobody picked up.' : ($callee ? self::displayName($callee) : 'They')." didn't pick up.";
            $this->endUnanswered($callLog, 'no_answer', $said);
        } else {
            $this->leaveMissedCallNote($callLog);
        }

        $this->pushMissedCall($callLog);
    }

    protected function endUnanswered(CallLog $callLog, string $cause, string $sayToCaller): void
    {
        $metadata = $callLog->metadata ?? [];
        $userCallControlId = $metadata['user_call_control_id'] ?? $callLog->call_control_id;

        $callLog->update(['status' => CallLog::STATUS_MISSED, 'hangup_cause' => $cause, 'ended_at' => now()]);

        if ($userCallControlId) {
            // Spoken on the caller's leg; the existing click_to_call_failed_tts
            // handler hangs the leg up when the words end.
            $this->command($userCallControlId, 'speak', [
                'payload' => $sayToCaller,
                'voice' => (string) config('services.telnyx.tts_voice'),
                'voice_settings' => array_filter(['type' => config('services.telnyx.tts_voice_type')]),
                'client_state' => base64_encode(json_encode(['action' => 'click_to_call_failed_tts', 'call_log_id' => $callLog->id])),
            ]);
        }

        foreach ((array) ($metadata['target_call_control_ids'] ?? []) as $legId) {
            if (is_string($legId) && $legId !== '') {
                $this->command($legId, 'hangup');
            }
        }

        $this->leaveMissedCallNote($callLog);
    }

    /** "Missed call from Patryk" in the two people's team thread (the crew thread for a crew call), once per call. */
    protected function leaveMissedCallNote(CallLog $callLog): void
    {
        $metadata = $callLog->metadata ?? [];
        if (! empty($metadata['missed_call_note_posted'])) {
            return;
        }

        $caller = User::withoutGlobalScopes()->find($metadata['caller_user_id'] ?? $callLog->user_id);
        if (! $caller || ! $caller->vendor) {
            return;
        }

        if ($this->isCrew($callLog)) {
            $thread = TeamRoster::groupThread($caller->vendor);
            $text = '📞 Missed crew call from '.self::displayName($caller);
        } else {
            $callee = User::withoutGlobalScopes()->find($metadata['target_user_id'] ?? null);
            if (! $callee) {
                return;
            }
            $thread = SmsGroupThread::teamThreadFor([$caller->id, $callee->id], (int) $caller->vendor->id);
            $text = '📞 Missed call from '.self::displayName($caller);
        }

        app(GroupSmsService::class)->sendToThread($thread, $text, [], $caller->id, null, ['kind' => 'missed_call', 'call_log_id' => $callLog->id]);

        $metadata['missed_call_note_posted'] = true;
        $callLog->update(['metadata' => $metadata]);
    }

    /** "Missed call from Patryk" on the devices of everyone who neither answered nor declined, once per call. */
    protected function pushMissedCall(CallLog $callLog): void
    {
        $callLog->refresh();
        $metadata = $callLog->metadata ?? [];
        $caller = User::withoutGlobalScopes()->find($metadata['caller_user_id'] ?? $callLog->user_id);
        $declined = array_map('intval', (array) ($metadata['declined_user_ids'] ?? []));
        $calleeIds = array_values(array_diff($this->calleeIds($callLog), $declined));
        if (! $caller || $calleeIds === [] || ! empty($metadata['missed_call_pushed']) || (! $this->isCrew($callLog) && $callLog->hangup_cause === 'declined')) {
            return;
        }

        $metadata['missed_call_pushed'] = true;
        $callLog->update(['metadata' => $metadata]);

        $crew = $this->isCrew($callLog);
        app(WebPushService::class)->sendToUsers($calleeIds, [
            'title' => ($crew ? 'Missed crew call from ' : 'Missed call from ').self::displayName($caller),
            'body' => 'Call back from Hive when you are free.',
            'icon' => '/favicons/icon-192x192.png',
            'badge' => '/favicons/icon-96x96.png',
            'tag' => "internal-call-{$callLog->id}",
            'data' => ['url' => $crew ? '/messages?activeTab=team' : '/messages?callBack='.$caller->id],
            'requireInteraction' => false,
        ], 'telnyx');
    }

    public function isCalleeOf(CallLog $callLog, User $user): bool
    {
        return ($callLog->metadata['type'] ?? null) === self::TYPE && in_array((int) $user->id, $this->calleeIds($callLog), true);
    }

    public function isCrew(CallLog $callLog): bool
    {
        return ! empty($callLog->metadata['crew']);
    }

    /** Everyone the call rings. @return list<int> */
    public function calleeIds(CallLog $callLog): array
    {
        $metadata = $callLog->metadata ?? [];
        $ids = (array) ($metadata['target_user_ids'] ?? array_filter([$metadata['target_user_id'] ?? null]));

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Over for good. A pair call is over once the two are talking (nobody
     * else can join); a crew call in progress still takes late joins and
     * declines, so "transferred" (talking) is not over for the crew.
     */
    public function hasEnded(CallLog $callLog): bool
    {
        if (in_array($callLog->status, [CallLog::STATUS_COMPLETED, CallLog::STATUS_MISSED, CallLog::STATUS_FAILED], true)) {
            return true;
        }

        return $callLog->status === CallLog::STATUS_TRANSFERRED && ! $this->isCrew($callLog);
    }

    public function hasConnected(CallLog $callLog): bool
    {
        return ! empty($callLog->metadata['joined_target_call_control_ids']) || in_array($callLog->status, [CallLog::STATUS_TRANSFERRED, CallLog::STATUS_COMPLETED], true);
    }

    public function areTeammates(User $a, User $b): bool
    {
        $ids = TeamRoster::memberIds($a->vendor);

        return in_array((int) $a->id, $ids, true) && in_array((int) $b->id, $ids, true) && $a->id !== $b->id;
    }

    /** A signed link the push's Decline button can hit with Hive closed. */
    public static function declineUrl(CallLog $callLog, User $callee): string
    {
        return URL::temporarySignedRoute('internal-calls.decline', now()->addMinutes(5), ['call' => $callLog->id, 'user' => $callee->id]);
    }

    public static function displayName(?User $user): string
    {
        return $user ? (trim((string) ($user->nickname ?: $user->first_name)) ?: 'Someone') : 'Someone';
    }

    /** Letters, digits, spaces and -_~!.+ only — what Telnyx accepts as a caller name. */
    public static function telnyxName(string $name): string
    {
        return Str::limit(trim(preg_replace('/[^A-Za-z0-9 \-_~!.+]/', '', $name) ?: 'Hive'), 60, '');
    }

    /** @param  array<string, mixed>  $params */
    protected function dial(array $params): ?\Illuminate\Http\Client\Response
    {
        $apiKey = config('services.telnyx.api_key');
        $connectionId = config('services.telnyx.connection_id');
        if (! $apiKey || ! $connectionId) {
            return null;
        }

        try {
            return Http::withToken($apiKey)->timeout(15)->post('https://api.telnyx.com/v2/calls', array_filter([
                'connection_id' => $connectionId,
                'from' => (string) config('services.telnyx.from'),
                'preferred_codecs' => config('services.telnyx.preferred_codecs'),
                'webhook_url' => ClickToCall::webhookUrl(),
            ] + $params, fn ($value) => $value !== null));
        } catch (\Throwable $e) {
            Log::channel('telnyx')->error('Internal call: dial exception', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** @param  array<string, mixed>  $params */
    protected function command(string $callControlId, string $action, array $params = []): void
    {
        $apiKey = config('services.telnyx.api_key');
        if (! $apiKey) {
            return;
        }

        try {
            Http::withToken($apiKey)->timeout(15)->post("https://api.telnyx.com/v2/calls/{$callControlId}/actions/{$action}", $params + ['command_id' => (string) Str::uuid()]);
        } catch (\Throwable $e) {
            Log::channel('telnyx')->warning('Internal call: command failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    /** @return array{ok: bool, error: ?string, call_log: ?CallLog} */
    protected function failed(string $error, ?CallLog $callLog = null): array
    {
        return ['ok' => false, 'error' => $error, 'call_log' => $callLog];
    }
}
