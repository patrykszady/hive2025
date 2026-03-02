<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendInboundSmsBrowserNotifications;
use App\Jobs\SendIncomingCallBrowserNotifications;
use App\Jobs\StoreCallRecording;
use App\Jobs\StoreSmsMedia;
use App\Models\CallLog;
use App\Models\Client;
use App\Models\SmsGroupThread;
use App\Models\SmsLog;
use App\Models\SmsMessage;
use App\Models\SmsThreadParticipant;
use App\Models\User;
use App\Models\Vendor;
use App\Services\GroupSmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelnyxWebhookController extends Controller
{
    public function __construct(protected GroupSmsService $groupSmsService)
    {
    }

    /**
     * Handle incoming Telnyx messaging webhooks.
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        $eventType = $payload['data']['event_type'] ?? null;

        Log::channel('telnyx')->info('Telnyx webhook received', [
            'event_type' => $eventType,
            'message_id' => $payload['data']['payload']['id'] ?? null,
        ]);

        return match ($eventType) {
            'message.sent' => $this->handleMessageSent($payload),
            'message.delivered' => $this->handleMessageDelivered($payload),
            'message.failed' => $this->handleMessageFailed($payload),
            'message.received' => $this->handleMessageReceived($payload),
            'message.finalized' => $this->handleMessageFinalized($payload),
            default => $this->handleUnknownEvent($eventType, $payload),
        };
    }

    /**
     * Handle incoming Telnyx voice/call control webhooks.
     */
    public function handleVoice(Request $request): JsonResponse
    {
        $payload = $request->all();
        $data = $payload['data'] ?? [];
        $eventType = $data['event_type'] ?? null;

        Log::channel('telnyx')->info('Telnyx voice webhook received', [
            'event_type' => $eventType,
            'call_control_id' => $data['payload']['call_control_id'] ?? null,
            'direction' => $data['payload']['direction'] ?? null,
        ]);

        if (($data['record_type'] ?? null) !== 'event') {
            return response()->json(['status' => 'ok']);
        }

        try {
            return match ($eventType) {
                'call.initiated' => $this->handleCallInitiated($data),
                'call.answered' => $this->handleCallAnswered($data),
                'call.hangup' => $this->handleCallHangup($data),
                'call.bridged' => $this->handleCallBridged($data),
                'call.leave' => $this->handleCallLeave($data),
                'call.speak.ended' => $this->handleCallSpeakEnded($data),
                'call.speak.started' => $this->handleCallSpeakStarted($data),
                'call.gather.ended' => $this->handleCallGatherEnded($data),
                'call.recording.saved' => $this->handleCallRecordingSaved($data),
                'call.machine.detection.ended' => $this->handleAmdEnded($data),
                default => $this->handleUnknownVoiceEvent($eventType, $data),
            };
        } catch (\Throwable $e) {
            Log::channel('telnyx')->error('Voice webhook handler exception', [
                'event_type' => $eventType,
                'call_control_id' => $data['payload']['call_control_id'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // Voice Call Control Handlers
    // =========================================================================

    /**
     * Handle call.initiated - answer incoming calls.
     * Flow: answer → play TTS welcome (if known caller) → dial admins → bridge first to answer.
     */
    protected function handleCallInitiated(array $data): JsonResponse
    {
        $payload = $data['payload'] ?? [];
        $direction = $payload['direction'] ?? null;
        $callControlId = $payload['call_control_id'] ?? null;

        // For outbound calls (click-to-call or admin ring legs), just log and return.
        if ($direction === 'outgoing') {
            Log::channel('telnyx')->info('Outbound call initiated', [
                'call_control_id' => $callControlId,
                'to' => $payload['to'] ?? null,
            ]);
            return response()->json(['status' => 'ok']);
        }

        if ($direction !== 'incoming' || !$callControlId) {
            return response()->json(['status' => 'ok']);
        }

        // Ignore loopback legs — when click-to-call dials our own Telnyx number,
        // Telnyx creates a phantom "incoming" leg (from == our number). Also ignore
        // any incoming call that originates from our own number to prevent loops.
        $incomingFrom = $payload['from'] ?? null;
        $telnyxFrom = config('services.telnyx.from');
        if ($incomingFrom && $telnyxFrom && $incomingFrom === $telnyxFrom) {
            Log::channel('telnyx')->info('Ignoring loopback/self-call — "from" is our own number', [
                'call_control_id' => $callControlId,
                'from' => $incomingFrom,
                'to' => $payload['to'] ?? null,
            ]);
            $this->sendCallCommand($callControlId, 'hangup');
            return response()->json(['status' => 'ok']);
        }

        // Also check for active click-to-call targeting this caller's number — prevents
        // loopback via carrier forwarding or voicemail callbacks
        if ($incomingFrom) {
            $recentClickToCall = CallLog::where('to_number', $incomingFrom)
                ->where('direction', 'outgoing')
                ->whereIn('status', [CallLog::STATUS_INITIATED, CallLog::STATUS_ANSWERED, CallLog::STATUS_TRANSFERRED])
                ->where('created_at', '>=', now()->subMinutes(5))
                ->whereNotNull('metadata->type')
                ->where('metadata->type', 'click_to_call')
                ->exists();

            if ($recentClickToCall) {
                Log::channel('telnyx')->info('Ignoring incoming call — active click-to-call exists for this number', [
                    'call_control_id' => $callControlId,
                    'from' => $incomingFrom,
                ]);
                $this->sendCallCommand($callControlId, 'hangup');
                return response()->json(['status' => 'ok']);
            }
        }

        // Deduplicate — Telnyx retries the webhook if we don't respond fast enough
        $existing = CallLog::findByCallControlId($callControlId);
        if ($existing) {
            Log::channel('telnyx')->info('Duplicate call.initiated webhook, skipping', [
                'call_control_id' => $callControlId,
            ]);
            return response()->json(['status' => 'ok']);
        }

        // Log the incoming call
        $callLog = CallLog::create([
            'call_id' => $callControlId,
            'call_control_id' => $callControlId,
            'call_session_id' => $payload['call_session_id'] ?? null,
            'call_leg_id' => $payload['call_leg_id'] ?? null,
            'connection_id' => $payload['connection_id'] ?? null,
            'direction' => 'incoming',
            'from_number' => $payload['from'] ?? 'unknown',
            'to_number' => $payload['to'] ?? config('services.telnyx.from'),
            'status' => CallLog::STATUS_INITIATED,
            'metadata' => [
                'admin_call_control_ids' => [],
                'bridged_admin_call_control_id' => null,
            ],
        ]);

        // Try to match caller to a user
        $user = $callLog->lookUpCaller();
        if ($user) {
            $callLog->update([
                'user_id' => $user->id,
                'caller_name' => $user->full_name,
            ]);
        }

        // Send browser push notification to admins: "{Caller Name} is Calling"
        SendIncomingCallBrowserNotifications::dispatch($callLog->id);

        Log::channel('telnyx')->info('Incoming call - answering', [
            'call_control_id' => $callControlId,
            'from' => $payload['from'] ?? null,
            'to' => $payload['to'] ?? null,
            'caller_user_id' => $user?->id,
            'caller_name' => $user?->full_name,
        ]);

        // Answer the call — on call.answered we'll play TTS or ring admins
        // send_silence_when_idle keeps RTP flowing during silence so carriers
        // don't tear down the media path once participants enter the conference.
        $this->sendCallCommand($callControlId, 'answer', [
            'send_silence_when_idle' => true,
            'client_state' => base64_encode(json_encode([
                'action' => 'welcome_or_ring',
                'call_log_id' => $callLog->id,
                'original_caller' => $payload['from'] ?? null,
                'caller_name' => $user?->first_name,
                'caller_user_id' => $user?->id,
            ])),
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle call.answered - route based on client_state action.
     *
     * Actions:
     *  - welcome_or_ring: Our answer of an incoming call → play TTS or ring admins
     *  - click_to_call: User answered their phone → create conference and dial target
     *  - click_to_call_target_ring: Target answered → play TTS intro then join conference
     *  - admin_ring: Admin answered → bridge with incoming caller
     */
    protected function handleCallAnswered(array $data): JsonResponse
    {
        $payload = $data['payload'] ?? [];
        $callControlId = $payload['call_control_id'] ?? null;
        $clientStateRaw = $payload['client_state'] ?? null;

        // Decode client state to determine what to do
        $clientState = $clientStateRaw
            ? json_decode(base64_decode($clientStateRaw), true)
            : null;

        $action = $clientState['action'] ?? null;

        // ── Click-to-call: user answered their phone → create conference and dial target ──
        if ($action === 'click_to_call') {
            return $this->handleClickToCallAnswered($callControlId, $clientState);
        }

        // ── Click-to-call target answered → play TTS intro then join conference ──
        if ($action === 'click_to_call_target_ring') {
            return $this->handleClickToCallTargetAnswered($callControlId, $clientState);
        }

        // ── Conference invite answered → play TTS intro then join conference ──
        if ($action === 'conference_invite') {
            return $this->handleConferenceInviteAnswered($callControlId, $clientState);
        }

        // ── Welcome or Ring: incoming call answered by us → TTS or ring admins ──
        if ($action === 'welcome_or_ring') {
            return $this->handleIncomingAnswered($callControlId, $clientState);
        }

        // ── Admin Ring: an admin answered → bridge with the incoming caller ──
        if ($action === 'admin_ring') {
            return $this->handleAdminRingAnswered($callControlId, $clientState);
        }

        // Other legs (e.g. transfer destinations) — do nothing
        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle click-to-call user answering their phone — create conference, then dial target.
     *
     * Flow: user answers → joins conference → target is dialed → target hears TTS intro → joins conference.
     */
    protected function handleClickToCallAnswered(string $callControlId, array $clientState): JsonResponse
    {
        $callLogId = $clientState['call_log_id'] ?? null;
        $targetPhone = $clientState['target_phone'] ?? null;
        $callLog = $callLogId ? CallLog::find($callLogId) : null;

        $callLog?->update([
            'status' => CallLog::STATUS_ANSWERED,
            'answered_at' => now(),
        ]);

        if (! $targetPhone) {
            Log::channel('telnyx')->error('Click-to-call: no target phone in client_state');
            $this->sendCallCommand($callControlId, 'hangup');
            $callLog?->update(['status' => CallLog::STATUS_FAILED, 'hangup_cause' => 'no_target_phone']);
            return response()->json(['status' => 'ok']);
        }

        $conferenceName = "outbound-{$callLogId}";

        // Resolve caller display name from user record
        $callerUser = $callLog?->user_id ? User::find($callLog->user_id) : null;
        $callerFirstName = $callerUser?->first_name ?? 'Someone';

        Log::channel('telnyx')->info('Click-to-call: user answered → joining conference and dialing target', [
            'call_control_id' => $callControlId,
            'target_phone' => $targetPhone,
            'call_log_id' => $callLogId,
            'conference_name' => $conferenceName,
        ]);

        // Step 1: Join the user to a conference (they hear ringback while target is dialed)
        $holdAudioUrl = config('services.telnyx.hold_audio_url')
            ?: rtrim(config('app.url'), '/') . '/audio/ringback.wav';

        $this->joinConference($callControlId, [
            'name' => $conferenceName,
            'beep_enabled' => 'never',
            'end_conference_on_exit' => true,
            'start_conference_on_create' => false,
            'hold_audio_url' => $holdAudioUrl,
            'client_state' => base64_encode(json_encode([
                'action' => 'click_to_call_in_conference',
                'call_log_id' => $callLogId,
            ])),
        ], $callLogId);

        // Step 2: Dial the target phone number
        $apiKey = config('services.telnyx.api_key');
        $connectionId = config('services.telnyx.connection_id');
        $from = config('services.telnyx.from');

        try {
            $response = Http::withToken($apiKey)
                ->post('https://api.telnyx.com/v2/calls', [
                    'connection_id' => $connectionId,
                    'to' => $targetPhone,
                    'from' => $from,
                    'from_display_name' => 'GS Construction',
                    'timeout_secs' => (int) config('services.telnyx.voice_timeout', 30),
                    'client_state' => base64_encode(json_encode([
                        'action' => 'click_to_call_target_ring',
                        'call_log_id' => $callLogId,
                        'conference_name' => $conferenceName,
                        'caller_name' => $callerFirstName,
                        'user_call_control_id' => $callControlId,
                    ])),
                    'webhook_url' => rtrim(config('app.url'), '/') . '/webhooks/telnyx/voice',
                ]);

            if ($response->successful()) {
                $data = $response->json('data') ?? [];
                $targetCcId = $data['call_control_id'] ?? null;

                if ($callLog) {
                    $metadata = $callLog->metadata ?? [];
                    $metadata['target_call_control_id'] = $targetCcId;
                    $metadata['conference_name'] = $conferenceName;
                    $callLog->update([
                        'forwarded_to' => $targetPhone,
                        'metadata' => $metadata,
                    ]);
                }

                Log::channel('telnyx')->info('Click-to-call: target dialed', [
                    'target_phone' => $targetPhone,
                    'target_call_control_id' => $targetCcId,
                ]);
            } else {
                Log::channel('telnyx')->error('Click-to-call: failed to dial target', [
                    'status' => $response->status(),
                    'error' => $response->json(),
                ]);
                // Hang up the user since we can't reach the target
                $this->sendCallCommand($callControlId, 'speak', [
                    'payload' => 'Sorry, we could not connect your call. Please try again.',
                    ...$this->ttsVoiceParams(),
                    'client_state' => base64_encode(json_encode([
                        'action' => 'click_to_call_failed_tts',
                        'call_log_id' => $callLogId,
                    ])),
                ]);
                $callLog?->update(['status' => CallLog::STATUS_FAILED]);
            }
        } catch (\Exception $e) {
            Log::channel('telnyx')->error('Click-to-call: exception dialing target', [
                'error' => $e->getMessage(),
            ]);
            $this->sendCallCommand($callControlId, 'hangup');
            $callLog?->update(['status' => CallLog::STATUS_FAILED]);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle click-to-call target answering — play TTS intro then join conference.
     */
    protected function handleClickToCallTargetAnswered(string $callControlId, array $clientState): JsonResponse
    {
        $callLogId = $clientState['call_log_id'] ?? null;
        $conferenceName = $clientState['conference_name'] ?? null;
        $callerName = $clientState['caller_name'] ?? 'Someone';

        // Resolve the target's name from the CallLog forwarded_to phone
        $callLog = $callLogId ? CallLog::find($callLogId) : null;
        $targetPhone = $callLog?->forwarded_to;
        $participantName = $this->resolveNameFromPhone($targetPhone) ?? 'Someone';

        $vendor = Vendor::find(1);
        $shortName = data_get($vendor?->options ?? [], 'short_name') ?: ($vendor?->business_name ?? 'GS Construction');

        $ttsPayload = "{$callerName} from {$shortName} is calling you. He will be on the line shortly.";

        Log::channel('telnyx')->info('Click-to-call: target answered — playing intro TTS', [
            'call_control_id' => $callControlId,
            'conference_name' => $conferenceName,
            'participant_name' => $participantName,
            'tts' => $ttsPayload,
        ]);

        $this->sendCallCommand($callControlId, 'speak', [
            'payload' => $ttsPayload,
            ...$this->ttsVoiceParams(),
            'client_state' => base64_encode(json_encode([
                'action' => 'click_to_call_target_intro_done',
                'call_log_id' => $callLogId,
                'conference_name' => $conferenceName,
                'participant_name' => $participantName,
            ])),
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle click-to-call target not answering (timeout/busy/rejected).
     * Notify the user in the conference that the target didn't answer, then hang up.
     */
    protected function handleClickToCallTargetHangup(string $callControlId, array $clientState, ?string $hangupCause): JsonResponse
    {
        $callLogId = $clientState['call_log_id'] ?? null;
        $userCallControlId = $clientState['user_call_control_id'] ?? null;
        $callLog = $callLogId ? CallLog::find($callLogId) : null;

        Log::channel('telnyx')->info('Click-to-call: target did not answer', [
            'call_control_id' => $callControlId,
            'hangup_cause' => $hangupCause,
            'call_log_id' => $callLogId,
        ]);

        // If we have the user's call control ID, tell them the target didn't answer.
        // Note: Telnyx Call Control v2 has no 'leave' action for calls in conferences.
        // Instead, speak the failure message directly on the user's call, then hang up.
        if ($userCallControlId) {
            $this->sendCallCommand($userCallControlId, 'speak', [
                'payload' => 'The person you called did not answer. Please try again later.',
                ...$this->ttsVoiceParams(),
                'client_state' => base64_encode(json_encode([
                    'action' => 'click_to_call_failed_tts',
                    'call_log_id' => $callLogId,
                ])),
            ]);
        }

        $callLog?->update([
            'status' => CallLog::STATUS_MISSED,
            'hangup_cause' => $hangupCause,
            'ended_at' => now(),
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle conference invite answered — play TTS intro then join existing conference.
     */
    protected function handleConferenceInviteAnswered(string $callControlId, array $clientState): JsonResponse
    {
        $callLogId = $clientState['call_log_id'] ?? null;
        $conferenceName = $clientState['conference_name'] ?? null;
        $callerName = $clientState['caller_name'] ?? 'Someone';

        // Resolve the invited person's name from the webhook call's "to" number
        // The webhook payload isn't available here, so look up from CallLog invited numbers
        $callLog = $callLogId ? CallLog::find($callLogId) : null;
        $participantName = $this->resolveNameFromCallControlId($callControlId, $callLog) ?? 'Someone';

        $vendor = Vendor::find(1);
        $shortName = data_get($vendor?->options ?? [], 'short_name') ?: ($vendor?->business_name ?? 'GS Construction');

        $ttsPayload = "{$callerName} from {$shortName} has invited you to a call. You will be connected shortly.";

        Log::channel('telnyx')->info('Conference invite: participant answered — playing intro TTS', [
            'call_control_id' => $callControlId,
            'conference_name' => $conferenceName,
            'call_log_id' => $callLogId,
            'participant_name' => $participantName,
            'tts' => $ttsPayload,
        ]);

        $this->sendCallCommand($callControlId, 'speak', [
            'payload' => $ttsPayload,
            ...$this->ttsVoiceParams(),
            'client_state' => base64_encode(json_encode([
                'action' => 'conference_invite_intro_done',
                'call_log_id' => $callLogId,
                'conference_name' => $conferenceName,
                'participant_name' => $participantName,
            ])),
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle conference invite not answered (timeout/busy/rejected).
     * Just log it — the conference continues without the invited participant.
     */
    protected function handleConferenceInviteHangup(string $callControlId, array $clientState, ?string $hangupCause): JsonResponse
    {
        $callLogId = $clientState['call_log_id'] ?? null;

        Log::channel('telnyx')->info('Conference invite: participant did not answer', [
            'call_control_id' => $callControlId,
            'hangup_cause' => $hangupCause,
            'call_log_id' => $callLogId,
        ]);

        // No need to tear down the conference — it continues with existing participants

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle incoming call answered by us — play TTS welcome or ring admins.
     * After TTS (or immediately), the caller joins a conference so all admins can join too.
     */
    protected function handleIncomingAnswered(string $callControlId, array $clientState): JsonResponse
    {
        $callLogId = $clientState['call_log_id'] ?? null;
        $callerName = $clientState['caller_name'] ?? null;
        $callerUserId = $clientState['caller_user_id'] ?? null;
        $originalCaller = $clientState['original_caller'] ?? null;
        $callLog = $callLogId ? CallLog::find($callLogId) : null;

        $callLog?->update([
            'status' => CallLog::STATUS_ANSWERED,
            'answered_at' => now(),
        ]);

        // Generate a unique conference name for this call
        $conferenceName = "call-{$callLogId}";

        // Get vendor options for phone system settings
        // TODO: Multi-vendor support — look up vendor by connection_id
        $vendor = Vendor::find(1);
        $vendorOptions = $vendor ? (array) $vendor->options : [];

        // Build welcome TTS payload
        $shortName = data_get($vendorOptions, 'short_name') ?: ($vendor?->business_name ?? 'our team');
        $greeting = $this->buildTimeGreeting();
        $welcomeTemplate = data_get($vendorOptions, 'welcome_message')
            ?: "{greeting} {name}! Thanks for calling {company}. One moment while we connect you.";
        $ttsPayload = str_replace(
            ['{name}', '{company}', '{greeting}'],
            [$callerName ?? '', $shortName, $greeting],
            $welcomeTemplate
        );
        // Clean up extra spaces/punctuation from empty {name}
        $ttsPayload = preg_replace('/\s+/', ' ', trim($ttsPayload));
        $ttsPayload = preg_replace('/\s+([!.?,])/', '$1', $ttsPayload);

        Log::channel('telnyx')->info('Playing welcome TTS and ringing admins simultaneously', [
            'call_control_id' => $callControlId,
            'tts' => $ttsPayload,
            'conference_name' => $conferenceName,
        ]);

        // Play TTS to the caller — when it finishes, caller joins the conference
        $this->sendCallCommand($callControlId, 'speak', [
            'payload' => $ttsPayload,
            ...$this->ttsVoiceParams(),
            'client_state' => base64_encode(json_encode([
                'action' => 'welcome_done_join_conference',
                'call_log_id' => $callLogId,
                'original_caller' => $originalCaller,
                'conference_name' => $conferenceName,
            ])),
        ]);

        // Dial admins at the same time — they join conference when they answer
        $this->dialAdmins($callControlId, $callLogId, $originalCaller, $conferenceName);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Join the incoming caller to a named conference.
     * The caller's leg ends the conference when they hang up.
     */
    protected function joinCallerToConference(string $callControlId, string $conferenceName, ?int $callLogId): void
    {
        Log::channel('telnyx')->info('Joining caller to conference', [
            'call_control_id' => $callControlId,
            'conference_name' => $conferenceName,
        ]);

        $conferenceParams = [
            'name' => $conferenceName,
            'beep_enabled' => 'never',
            'end_conference_on_exit' => true,
            'start_conference_on_create' => false,
            'client_state' => base64_encode(json_encode([
                'action' => 'in_conference',
                'call_log_id' => $callLogId,
                'conference_name' => $conferenceName,
            ])),
        ];

        // Play ringback tone while caller waits for an admin to join
        $holdAudioUrl = config('services.telnyx.hold_audio_url')
            ?: rtrim(config('app.url'), '/') . '/audio/ringback.wav';
        $conferenceParams['hold_audio_url'] = $holdAudioUrl;

        $this->joinConference($callControlId, $conferenceParams, $callLogId);
    }

    /**
     * Handle an admin answering the simultaneous ring — join the conference.
     * Multiple admins can join the same conference for a true multi-party call.
     */
    protected function handleAdminRingAnswered(string $callControlId, array $clientState): JsonResponse
    {
        $callLogId = $clientState['call_log_id'] ?? null;
        $conferenceName = $clientState['conference_name'] ?? null;
        $adminUserId = $clientState['admin_user_id'] ?? null;
        $callLog = $callLogId ? CallLog::find($callLogId) : null;

        if (! $callLog || ! $conferenceName) {
            Log::channel('telnyx')->error('Admin ring answered but missing call log or conference name', [
                'call_control_id' => $callControlId,
                'call_log_id' => $callLogId,
            ]);
            $this->sendCallCommand($callControlId, 'hangup');
            return response()->json(['status' => 'ok']);
        }

        $adminUser = $adminUserId ? User::find($adminUserId) : null;

        Log::channel('telnyx')->info('Admin answered — playing connect message then joining conference', [
            'admin_call_control_id' => $callControlId,
            'conference_name' => $conferenceName,
            'admin_user_id' => $adminUserId,
            'admin_name' => $adminUser?->full_name,
        ]);

        // Track that at least one admin joined
        $metadata = $callLog->metadata ?? [];
        $joinedAdmins = $metadata['joined_admin_ids'] ?? [];
        $joinedAdmins[] = $adminUserId;
        $metadata['joined_admin_ids'] = array_unique($joinedAdmins);

        $callLog->update([
            'status' => CallLog::STATUS_TRANSFERRED,
            'forwarded_to' => $adminUser?->cell_phone,
            'metadata' => $metadata,
        ]);

        // Play a brief TTS to the admin using customizable template, then join conference
        $callerName = $callLog->caller_name ?: 'a caller';
        $adminFirstName = $adminUser?->first_name ?: '';
        $vendor = Vendor::find(1);
        $shortName = data_get($vendor?->options ?? [], 'short_name') ?: ($vendor?->business_name ?? 'our team');
        $connectTemplate = data_get($vendor?->options ?? [], 'admin_connect_message')
            ?: "{greeting}! We're connecting you to {name}.";
        $connectPayload = str_replace(
            ['{name}', '{company}', '{greeting}'],
            [$callerName, $shortName, $this->buildTimeGreeting()],
            $connectTemplate
        );
        $connectPayload = preg_replace('/\s+/', ' ', trim($connectPayload));
        $connectPayload = preg_replace('/\s+([!.?,])/', '$1', $connectPayload);
        // Prepend admin's first name if available
        if ($adminFirstName) {
            $connectPayload = "{$adminFirstName}, {$connectPayload}";
        }
        $this->sendCallCommand($callControlId, 'speak', [
            'payload' => $connectPayload,
            ...$this->ttsVoiceParams(),
            'client_state' => base64_encode(json_encode([
                'action' => 'admin_connect_message_done',
                'call_log_id' => $callLogId,
                'admin_user_id' => $adminUserId,
                'conference_name' => $conferenceName,
            ])),
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle call.hangup - call ended. Also triggers voicemail if all admin rings failed.
     */
    protected function handleCallHangup(array $data): JsonResponse
    {
        $payload = $data['payload'] ?? [];
        $callControlId = $payload['call_control_id'] ?? null;
        $hangupCause = $payload['hangup_cause'] ?? null;
        $clientStateRaw = $payload['client_state'] ?? null;

        $clientState = $clientStateRaw
            ? json_decode(base64_decode($clientStateRaw), true)
            : null;

        $action = $clientState['action'] ?? null;

        // ── Admin ring leg hung up (timeout/no answer) ──
        if ($action === 'admin_ring') {
            return $this->handleAdminRingHangup($callControlId, $clientState, $hangupCause);
        }

        // ── Click-to-call target didn't answer (timeout/busy/rejected) ──
        if ($action === 'click_to_call_target_ring') {
            return $this->handleClickToCallTargetHangup($callControlId, $clientState, $hangupCause);
        }

        // ── Conference invite didn't answer ──
        if ($action === 'conference_invite') {
            return $this->handleConferenceInviteHangup($callControlId, $clientState, $hangupCause);
        }

        // ── Voicemail recording done (caller hung up after record_start) ──
        if ($action === 'voicemail_recording') {
            $callLogId = $clientState['call_log_id'] ?? null;
            $callLog = $callLogId ? CallLog::find($callLogId) : null;

            if ($callLog) {
                $callLog->update([
                    'status' => CallLog::STATUS_MISSED,
                    'has_voicemail' => true,
                    'hangup_cause' => $hangupCause,
                    'ended_at' => now(),
                    'duration_seconds' => $callLog->answered_at
                        ? now()->diffInSeconds($callLog->answered_at)
                        : null,
                ]);
            }

            Log::channel('telnyx')->info('Voicemail call ended', [
                'call_control_id' => $callControlId,
                'call_log_id' => $callLogId,
            ]);

            return response()->json(['status' => 'ok']);
        }

        // ── Standard hangup — update CallLog for the incoming call ──
        $callLog = CallLog::findByCallControlId($callControlId);

        if ($callLog) {
            $wasAnswered = $callLog->answered_at !== null;
            $duration = $callLog->answered_at
                ? now()->diffInSeconds($callLog->answered_at)
                : null;

            $status = match (true) {
                $hangupCause === 'normal_clearing' && $wasAnswered => CallLog::STATUS_COMPLETED,
                $hangupCause === 'timeout' => CallLog::STATUS_MISSED,
                $hangupCause === 'originator_cancel' => CallLog::STATUS_MISSED,
                !$wasAnswered => CallLog::STATUS_MISSED,
                default => CallLog::STATUS_COMPLETED,
            };

            $callLog->update([
                'status' => $status,
                'hangup_cause' => $hangupCause,
                'duration_seconds' => $duration,
                'ended_at' => now(),
            ]);

            Log::channel('telnyx')->info('Call ended', [
                'call_control_id' => $callControlId,
                'status' => $status,
                'hangup_cause' => $hangupCause,
                'duration' => $duration,
            ]);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle an admin ring leg hanging up (timeout or rejection).
     * If all admin legs failed and none joined the conference, trigger voicemail.
     */
    protected function handleAdminRingHangup(string $callControlId, array $clientState, ?string $hangupCause): JsonResponse
    {
        $callLogId = $clientState['call_log_id'] ?? null;
        $incomingCallControlId = $clientState['incoming_call_control_id'] ?? null;
        $callLog = $callLogId ? CallLog::find($callLogId) : null;

        Log::channel('telnyx')->info('Admin ring leg hung up', [
            'admin_call_control_id' => $callControlId,
            'hangup_cause' => $hangupCause,
            'call_log_id' => $callLogId,
        ]);

        if (! $callLog || ! $incomingCallControlId) {
            return response()->json(['status' => 'ok']);
        }

        $metadata = $callLog->metadata ?? [];

        // If at least one admin already joined the conference, this is just another leg timing out — ignore
        if (! empty($metadata['joined_admin_ids'])) {
            return response()->json(['status' => 'ok']);
        }

        // Remove this admin from the pending list
        $adminCallControlIds = $metadata['admin_call_control_ids'] ?? [];
        $metadata['admin_call_control_ids'] = array_values(
            array_filter($adminCallControlIds, fn ($id) => $id !== $callControlId)
        );
        $callLog->update(['metadata' => $metadata]);

        // If all admin legs have ended and none joined → trigger voicemail (or defer if TTS still playing)
        if (empty($metadata['admin_call_control_ids'])) {
            $ttsComplete = $metadata['tts_complete'] ?? false;

            if ($ttsComplete) {
                // Caller is in the conference hearing ringback — trigger voicemail directly.
                // Note: Telnyx Call Control v2 has no 'leave' action. We trigger voicemail
                // on the caller's call while they're still in the conference. The gather/speak
                // command will take over the call's audio context.
                Log::channel('telnyx')->info('All admin legs failed — triggering voicemail directly', [
                    'incoming_call_control_id' => $incomingCallControlId,
                    'call_log_id' => $callLogId,
                ]);
                $this->triggerVoicemail($incomingCallControlId, $callLogId);
            } else {
                // TTS still playing — set flag so voicemail triggers when TTS finishes
                Log::channel('telnyx')->info('All admin legs failed but TTS still playing — deferring voicemail', [
                    'incoming_call_control_id' => $incomingCallControlId,
                    'call_log_id' => $callLogId,
                ]);
                $metadata['all_admins_failed'] = true;
                $callLog->update(['metadata' => $metadata]);
            }
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle call.leave - call left a conference.
     * If action is 'leave_for_voicemail', trigger the IVR voicemail menu.
     */
    protected function handleCallLeave(array $data): JsonResponse
    {
        $payload = $data['payload'] ?? [];
        $callControlId = $payload['call_control_id'] ?? null;
        $clientStateRaw = $payload['client_state'] ?? null;

        $clientState = $clientStateRaw
            ? json_decode(base64_decode($clientStateRaw), true)
            : null;

        $action = $clientState['action'] ?? null;

        Log::channel('telnyx')->info('Call left conference', [
            'call_control_id' => $callControlId,
            'action' => $action,
        ]);

        if ($action === 'leave_for_voicemail') {
            $callLogId = $clientState['call_log_id'] ?? null;

            Log::channel('telnyx')->info('Left conference — triggering voicemail', [
                'call_control_id' => $callControlId,
                'call_log_id' => $callLogId,
            ]);

            $this->triggerVoicemail($callControlId, $callLogId);
        }

        if ($action === 'click_to_call_target_failed_leave') {
            $callLogId = $clientState['call_log_id'] ?? null;

            Log::channel('telnyx')->info('Click-to-call: left conference after target failed — notifying user', [
                'call_control_id' => $callControlId,
                'call_log_id' => $callLogId,
            ]);

            $this->sendCallCommand($callControlId, 'speak', [
                'payload' => 'The person you called did not answer. Please try again later.',
                ...$this->ttsVoiceParams(),
                'client_state' => base64_encode(json_encode([
                    'action' => 'click_to_call_failed_tts',
                    'call_log_id' => $callLogId,
                ])),
            ]);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle call.bridged - two legs connected.
     */
    protected function handleCallBridged(array $data): JsonResponse
    {
        $payload = $data['payload'] ?? [];

        Log::channel('telnyx')->info('Call bridged', [
            'call_control_id' => $payload['call_control_id'] ?? null,
            'call_session_id' => $payload['call_session_id'] ?? null,
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle call.speak.started - TTS started playing.
     */
    protected function handleCallSpeakStarted(array $data): JsonResponse
    {
        $payload = $data['payload'] ?? [];

        Log::channel('telnyx')->info('TTS speak started', [
            'call_control_id' => $payload['call_control_id'] ?? null,
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle call.speak.ended - TTS finished playing.
     * If action is 'ring_admins_after_speak', now dial the admins.
     */
    protected function handleCallSpeakEnded(array $data): JsonResponse
    {
        $payload = $data['payload'] ?? [];
        $callControlId = $payload['call_control_id'] ?? null;
        $clientStateRaw = $payload['client_state'] ?? null;

        $clientState = $clientStateRaw
            ? json_decode(base64_decode($clientStateRaw), true)
            : null;

        $action = $clientState['action'] ?? null;

        Log::channel('telnyx')->info('handleCallSpeakEnded ENTRY', [
            'call_control_id' => $callControlId,
            'action' => $action,
            'client_state' => $clientState,
            'client_state_raw_present' => $clientStateRaw !== null,
        ]);

        if ($action === 'welcome_done_join_conference') {
            $callLogId = $clientState['call_log_id'] ?? null;
            $originalCaller = $clientState['original_caller'] ?? null;
            $conferenceName = $clientState['conference_name'] ?? null;
            $callLog = $callLogId ? CallLog::find($callLogId) : null;

            // Mark TTS as complete so admin hangup handler knows
            if ($callLog) {
                $metadata = $callLog->metadata ?? [];
                $metadata['tts_complete'] = true;
                $callLog->update(['metadata' => $metadata]);
            }

            // Refresh metadata to check if all admin legs already failed during TTS
            $metadata = $callLog ? ($callLog->fresh()->metadata ?? []) : [];
            $allAdminsFailed = ($metadata['all_admins_failed'] ?? false);

            if ($allAdminsFailed) {
                Log::channel('telnyx')->info('TTS completed but all admins already failed — triggering voicemail', [
                    'call_control_id' => $callControlId,
                    'call_log_id' => $callLogId,
                ]);
                $this->triggerVoicemail($callControlId, $callLogId);
            } else {
                Log::channel('telnyx')->info('TTS completed — joining caller to conference', [
                    'call_control_id' => $callControlId,
                    'call_log_id' => $callLogId,
                    'conference_name' => $conferenceName,
                ]);

                if ($conferenceName) {
                    $this->joinCallerToConference($callControlId, $conferenceName, $callLogId);
                }
            }
        } elseif ($action === 'voicemail_prompt_done') {
            // Voicemail prompt finished → start recording
            $callLogId = $clientState['call_log_id'] ?? null;

            Log::channel('telnyx')->info('Voicemail prompt done — starting recording', [
                'call_control_id' => $callControlId,
            ]);

            $this->sendCallCommand($callControlId, 'record_start', [
                'format' => 'mp3',
                'channels' => 'single',
                'play_beep' => true,
                'max_length' => 120, // 2 minutes max
                'timeout_secs' => 5,  // Stop after 5s silence
                'client_state' => base64_encode(json_encode([
                    'action' => 'voicemail_recording',
                    'call_log_id' => $callLogId,
                ])),
            ]);
        } elseif ($action === 'ivr_retry_connect') {
            // Press 1: brief message done → re-dial admins
            $callLogId = $clientState['call_log_id'] ?? null;
            $originalCaller = $clientState['original_caller'] ?? null;
            $conferenceName = "call-{$callLogId}-retry-" . time();

            Log::channel('telnyx')->info('IVR retry — joining conference and ringing admins', [
                'call_control_id' => $callControlId,
                'call_log_id' => $callLogId,
                'conference_name' => $conferenceName,
            ]);

            $this->joinCallerToConference($callControlId, $conferenceName, $callLogId);
            $this->dialAdmins($callControlId, $callLogId, $originalCaller, $conferenceName);
        } elseif ($action === 'ivr_sms_confirmation') {
            // Press 2: SMS confirmation message done → hang up
            $callLogId = $clientState['call_log_id'] ?? null;

            Log::channel('telnyx')->info('IVR SMS confirmation done — hanging up', [
                'call_control_id' => $callControlId,
                'call_log_id' => $callLogId,
            ]);

            $this->sendCallCommand($callControlId, 'hangup');
        } elseif ($action === 'admin_connect_message_done') {
            // Admin heard "We're connecting you to {name}" → join the EXISTING conference
            $callLogId = $clientState['call_log_id'] ?? null;
            $adminUserId = $clientState['admin_user_id'] ?? null;
            $conferenceName = $clientState['conference_name'] ?? null;
            $conferenceId = $this->getConferenceId($callLogId);

            Log::channel('telnyx')->info('Admin connect message done — joining conference', [
                'call_control_id' => $callControlId,
                'conference_name' => $conferenceName,
                'conference_id' => $conferenceId,
                'admin_user_id' => $adminUserId,
            ]);

            if ($conferenceId) {
                $this->addToConference($callControlId, $conferenceId, [
                    'beep_enabled' => 'never',
                    'end_conference_on_exit' => false,
                    'start_conference_on_create' => true,
                    'client_state' => base64_encode(json_encode([
                        'action' => 'admin_in_conference',
                        'call_log_id' => $callLogId,
                        'admin_user_id' => $adminUserId,
                    ])),
                ]);
            } elseif ($conferenceName) {
                // Fallback: create new conference if ID not found (shouldn't happen)
                Log::channel('telnyx')->warning('No conference_id in metadata — falling back to create', [
                    'call_log_id' => $callLogId,
                    'conference_name' => $conferenceName,
                ]);
                $this->joinConference($callControlId, [
                    'name' => $conferenceName,
                    'beep_enabled' => 'never',
                    'end_conference_on_exit' => false,
                    'start_conference_on_create' => true,
                    'client_state' => base64_encode(json_encode([
                        'action' => 'admin_in_conference',
                        'call_log_id' => $callLogId,
                        'admin_user_id' => $adminUserId,
                    ])),
                ], $callLogId);
            }
        } elseif ($action === 'click_to_call_target_intro_done') {
            // Target heard the TTS intro → join the EXISTING conference with the user
            $callLogId = $clientState['call_log_id'] ?? null;
            $conferenceName = $clientState['conference_name'] ?? null;
            $participantName = $clientState['participant_name'] ?? null;
            $conferenceId = $this->getConferenceId($callLogId);

            Log::channel('telnyx')->info('Click-to-call: target intro done — joining conference', [
                'call_control_id' => $callControlId,
                'conference_name' => $conferenceName,
                'conference_id' => $conferenceId,
                'call_log_id' => $callLogId,
                'participant_name' => $participantName,
            ]);

            if ($conferenceId) {
                $this->addToConference($callControlId, $conferenceId, [
                    'beep_enabled' => 'on_enter',
                    'end_conference_on_exit' => false,
                    'start_conference_on_create' => true,
                    'client_state' => base64_encode(json_encode([
                        'action' => 'click_to_call_target_in_conference',
                        'call_log_id' => $callLogId,
                    ])),
                ]);

                // Announce to existing conference participants
                if ($participantName && $participantName !== 'Someone') {
                    $this->announceConferenceJoin($conferenceName, $participantName);
                }
            } elseif ($conferenceName) {
                // Fallback: create new conference if ID not found
                Log::channel('telnyx')->warning('No conference_id in metadata — falling back to create', [
                    'call_log_id' => $callLogId,
                    'conference_name' => $conferenceName,
                ]);
                $this->joinConference($callControlId, [
                    'name' => $conferenceName,
                    'beep_enabled' => 'on_enter',
                    'end_conference_on_exit' => false,
                    'start_conference_on_create' => true,
                    'client_state' => base64_encode(json_encode([
                        'action' => 'click_to_call_target_in_conference',
                        'call_log_id' => $callLogId,
                    ])),
                ], $callLogId);
            }

            $callLog = $callLogId ? CallLog::find($callLogId) : null;
            $callLog?->update(['status' => CallLog::STATUS_TRANSFERRED]);
        } elseif ($action === 'conference_invite_intro_done') {
            // Invited participant heard the TTS intro → join existing conference
            $callLogId = $clientState['call_log_id'] ?? null;
            $conferenceName = $clientState['conference_name'] ?? null;
            $participantName = $clientState['participant_name'] ?? null;
            $conferenceId = $this->getConferenceId($callLogId);

            Log::channel('telnyx')->info('Conference invite: intro done — joining conference', [
                'call_control_id' => $callControlId,
                'conference_name' => $conferenceName,
                'conference_id' => $conferenceId,
                'call_log_id' => $callLogId,
                'participant_name' => $participantName,
            ]);

            if ($conferenceId) {
                $this->addToConference($callControlId, $conferenceId, [
                    'beep_enabled' => 'on_enter',
                    'end_conference_on_exit' => false,
                    'start_conference_on_create' => true,
                    'client_state' => base64_encode(json_encode([
                        'action' => 'conference_invite_in_conference',
                        'call_log_id' => $callLogId,
                    ])),
                ]);

                // Announce to existing conference participants
                if ($participantName && $participantName !== 'Someone') {
                    $this->announceConferenceJoin($conferenceName, $participantName);
                }
            } elseif ($conferenceName) {
                // Fallback
                Log::channel('telnyx')->warning('No conference_id in metadata — falling back to create', [
                    'call_log_id' => $callLogId,
                    'conference_name' => $conferenceName,
                ]);
                $this->joinConference($callControlId, [
                    'name' => $conferenceName,
                    'beep_enabled' => 'on_enter',
                    'end_conference_on_exit' => false,
                    'start_conference_on_create' => true,
                    'client_state' => base64_encode(json_encode([
                        'action' => 'conference_invite_in_conference',
                        'call_log_id' => $callLogId,
                    ])),
                ], $callLogId);
            }
        } elseif ($action === 'click_to_call_failed_tts') {
            // Failed to reach target — TTS error message done → hang up
            $callLogId = $clientState['call_log_id'] ?? null;

            Log::channel('telnyx')->info('Click-to-call: failure TTS done — hanging up', [
                'call_control_id' => $callControlId,
                'call_log_id' => $callLogId,
            ]);

            $this->sendCallCommand($callControlId, 'hangup');
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle call.recording.saved - recording ready.
     */
    protected function handleCallRecordingSaved(array $data): JsonResponse
    {
        $payload = $data['payload'] ?? [];
        $callControlId = $payload['call_control_id'] ?? null;
        $recordingUrls = $payload['recording_urls'] ?? [];
        $clientStateRaw = $payload['client_state'] ?? null;

        $clientState = $clientStateRaw
            ? json_decode(base64_decode($clientStateRaw), true)
            : null;

        $callLogId = $clientState['call_log_id'] ?? null;
        $callLog = $callLogId
            ? CallLog::find($callLogId)
            : CallLog::findByCallControlId($callControlId);

        $recordingUrl = $recordingUrls['mp3'] ?? $recordingUrls['wav'] ?? null;

        if ($callLog && $recordingUrl) {
            $isVoicemail = ($clientState['action'] ?? null) === 'voicemail_recording';

            $callLog->update([
                'recording_url' => $recordingUrl,
                'has_voicemail' => $isVoicemail,
            ]);

            // Download the recording locally before the Telnyx signed URL expires (~10 min)
            StoreCallRecording::dispatch($callLog->id);
        }

        Log::channel('telnyx')->info('Call recording saved', [
            'call_control_id' => $callControlId,
            'recording_urls' => $recordingUrls,
            'is_voicemail' => ($clientState['action'] ?? null) === 'voicemail_recording',
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle answering machine detection result.
     */
    protected function handleAmdEnded(array $data): JsonResponse
    {
        $payload = $data['payload'] ?? [];

        Log::channel('telnyx')->info('AMD ended', [
            'call_control_id' => $payload['call_control_id'] ?? null,
            'result' => $payload['result'] ?? null,
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle unknown voice event.
     */
    protected function handleUnknownVoiceEvent(?string $eventType, array $data): JsonResponse
    {
        Log::channel('telnyx')->info('Unhandled voice event', [
            'event_type' => $eventType,
        ]);

        return response()->json(['status' => 'ok']);
    }

    // =========================================================================
    // Voice Helper Methods
    // =========================================================================

    /**
     * Build a time-of-day greeting (Good morning / afternoon / evening).
     */
    protected function buildTimeGreeting(): string
    {
        // Use vendor timezone if set, otherwise default to America/New_York
        $vendor = Vendor::find(1);
        $tz = $vendor?->timezone ?? 'America/New_York';
        $hour = now($tz)->hour;

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
    }

    /**
     * Dial all selected admin call recipients simultaneously.
     * Each admin gets their own outbound call leg with the conference name in client_state.
     */
    protected function dialAdmins(string $incomingCallControlId, ?int $callLogId, ?string $originalCaller, ?string $conferenceName = null): void
    {
        $callLog = $callLogId ? CallLog::find($callLogId) : null;

        // Get call recipients from vendor options
        // TODO: Multi-vendor support — look up vendor by connection_id
        $vendor = Vendor::find(1);
        $vendorOptions = $vendor ? (array) $vendor->options : [];
        $recipientUserIds = (array) data_get($vendorOptions, 'call_recipients', []);

        // Fall back to config if no recipients configured
        if (empty($recipientUserIds)) {
            $fallback = $this->getForwardingDestination();
            if ($fallback) {
                Log::channel('telnyx')->info('No call recipients configured, using config fallback', [
                    'forward_to' => $fallback,
                ]);
                $this->sendCallCommand($incomingCallControlId, 'transfer', [
                    'to' => $fallback,
                    'from' => $originalCaller ?? config('services.telnyx.from'),
                    'timeout_secs' => (int) config('services.telnyx.voice_timeout', 30),
                    'client_state' => base64_encode(json_encode([
                        'action' => 'transferred',
                        'call_log_id' => $callLogId,
                    ])),
                ]);
                $callLog?->update(['forwarded_to' => $fallback]);
            } else {
                Log::channel('telnyx')->error('No call recipients and no forwarding destination — triggering voicemail');
                $this->triggerVoicemail($incomingCallControlId, $callLogId);
            }
            return;
        }

        // Get admin users with their phone numbers
        $adminUsers = User::whereIn('id', $recipientUserIds)
            ->whereNotNull('cell_phone')
            ->where('cell_phone', '!=', '')
            ->get();

        if ($adminUsers->isEmpty()) {
            Log::channel('telnyx')->error('No valid admin users found for call recipients — triggering voicemail');
            $this->triggerVoicemail($incomingCallControlId, $callLogId);
            return;
        }

        $apiKey = config('services.telnyx.api_key');
        $connectionId = config('services.telnyx.connection_id');
        $telnyxFrom = config('services.telnyx.from');
        $timeout = (int) config('services.telnyx.voice_timeout', 30);
        $adminCallControlIds = [];

        foreach ($adminUsers as $adminUser) {
            $phone = $adminUser->cell_phone;

            // Ensure phone is in E.164 format
            if (! str_starts_with($phone, '+')) {
                $digits = preg_replace('/\D/', '', $phone);
                if (strlen($digits) === 10) {
                    $phone = '+1' . $digits;
                } elseif (strlen($digits) === 11 && str_starts_with($digits, '1')) {
                    $phone = '+' . $digits;
                }
            }

            try {
                $response = Http::withToken($apiKey)
                    ->post('https://api.telnyx.com/v2/calls', [
                        'connection_id' => $connectionId,
                        'to' => $phone,
                        'from' => $telnyxFrom,
                        'from_display_name' => $callLog?->caller_name ?: ($originalCaller ?? 'Incoming Call'),
                        'timeout_secs' => $timeout,
                        'client_state' => base64_encode(json_encode([
                            'action' => 'admin_ring',
                            'call_log_id' => $callLogId,
                            'incoming_call_control_id' => $incomingCallControlId,
                            'conference_name' => $conferenceName,
                            'admin_user_id' => $adminUser->id,
                        ])),
                    ]);

                if ($response->successful()) {
                    $data = $response->json('data') ?? [];
                    $adminCcId = $data['call_control_id'] ?? null;
                    if ($adminCcId) {
                        $adminCallControlIds[] = $adminCcId;
                    }

                    Log::channel('telnyx')->info('Dialed admin', [
                        'admin_user_id' => $adminUser->id,
                        'admin_name' => $adminUser->full_name,
                        'phone' => $phone,
                        'admin_call_control_id' => $adminCcId,
                    ]);
                } else {
                    Log::channel('telnyx')->error('Failed to dial admin', [
                        'admin_user_id' => $adminUser->id,
                        'phone' => $phone,
                        'error' => $response->json(),
                    ]);
                }
            } catch (\Exception $e) {
                Log::channel('telnyx')->error('Exception dialing admin', [
                    'admin_user_id' => $adminUser->id,
                    'phone' => $phone,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Store admin call control IDs and conference name in the call log metadata
        if ($callLog) {
            $metadata = $callLog->metadata ?? [];
            $metadata['admin_call_control_ids'] = $adminCallControlIds;
            if ($conferenceName) {
                $metadata['conference_name'] = $conferenceName;
            }
            $callLog->update(['metadata' => $metadata]);
        }

        // If no admins were successfully dialed, trigger voicemail
        if (empty($adminCallControlIds)) {
            Log::channel('telnyx')->error('Failed to dial any admins — triggering voicemail');
            $this->triggerVoicemail($incomingCallControlId, $callLogId);
        }
    }

    /**
     * Trigger voicemail IVR: play interactive menu with DTMF options.
     * Press 1 = re-dial, Press 2 = send text, Stay on line = voicemail.
     */
    protected function triggerVoicemail(string $callControlId, ?int $callLogId): void
    {
        // Check if voicemail is enabled
        $vendor = Vendor::find(1);
        $voicemailEnabled = (bool) data_get($vendor?->options ?? [], 'voicemail_enabled', true);

        if (! $voicemailEnabled) {
            Log::channel('telnyx')->info('Voicemail disabled — hanging up', [
                'call_control_id' => $callControlId,
            ]);
            $this->sendCallCommand($callControlId, 'hangup');

            if ($callLogId) {
                CallLog::where('id', $callLogId)->update([
                    'status' => CallLog::STATUS_MISSED,
                    'ended_at' => now(),
                ]);
            }
            return;
        }

        $shortName = data_get($vendor?->options ?? [], 'short_name') ?: ($vendor?->business_name ?? 'us');

        // Get caller name from call log for personalization
        $callLog = $callLogId ? CallLog::find($callLogId) : null;
        $callerName = null;
        $isKnownCaller = false;
        if ($callLog?->user_id) {
            $callerUser = User::find($callLog->user_id);
            $callerName = $callerUser?->first_name;
            $isKnownCaller = true;
        }

        // Known callers get full IVR (press 1 re-dial + press 2 SMS), unknown get press 2 + voicemail only
        if ($isKnownCaller) {
            $ivrTemplate = data_get($vendor?->options ?? [], 'voicemail_message')
                ?: "{company} is not available right now. {name}, if this is an emergency, press 1 to re-dial {company}. Press 2 to send a text on your behalf so {company} knows to call you back ASAP. Stay on the line to leave a voicemail.";
            $validDigits = '12';
        } else {
            $ivrTemplate = data_get($vendor?->options ?? [], 'voicemail_message_unknown')
                ?: "{company} is not available right now. Press 2 to send a text on your behalf so {company} knows to call you back ASAP. Stay on the line to leave a voicemail.";
            $validDigits = '2';
        }

        $ivrPrompt = str_replace(
            ['{name}', '{company}', '{greeting}'],
            [$callerName ?? '', $shortName, $this->buildTimeGreeting()],
            $ivrTemplate
        );
        // Clean up extra spaces/punctuation from empty {name}
        $ivrPrompt = preg_replace('/\s+/', ' ', trim($ivrPrompt));
        $ivrPrompt = preg_replace('/\s+([!.?,])/', '$1', $ivrPrompt);

        Log::channel('telnyx')->info('Playing voicemail IVR menu', [
            'call_control_id' => $callControlId,
            'call_log_id' => $callLogId,
            'ivr_prompt' => $ivrPrompt,
            'is_known_caller' => $isKnownCaller,
        ]);

        $this->sendCallCommand($callControlId, 'gather_using_speak', [
            'payload' => $ivrPrompt,
            ...$this->ttsVoiceParams(),
            'valid_digits' => $validDigits,
            'minimum_digits' => 1,
            'maximum_digits' => 1,
            'timeout_millis' => 7000,
            'maximum_tries' => 1,
            'client_state' => base64_encode(json_encode([
                'action' => 'voicemail_ivr_menu',
                'call_log_id' => $callLogId,
                'original_caller' => $callLog?->from_number,
                'is_known_caller' => $isKnownCaller,
            ])),
        ]);
    }

    /**
     * Handle call.gather.ended — IVR menu DTMF result.
     * Digit 1 = re-dial admins, Digit 2 = send SMS, No digit = voicemail recording.
     */
    protected function handleCallGatherEnded(array $data): JsonResponse
    {
        $payload = $data['payload'] ?? [];
        $callControlId = $payload['call_control_id'] ?? null;
        $digits = $payload['digits'] ?? '';
        $clientStateRaw = $payload['client_state'] ?? null;

        $clientState = $clientStateRaw
            ? json_decode(base64_decode($clientStateRaw), true)
            : null;

        $action = $clientState['action'] ?? null;

        if ($action !== 'voicemail_ivr_menu') {
            return response()->json(['status' => 'ok']);
        }

        $callLogId = $clientState['call_log_id'] ?? null;
        $originalCaller = $clientState['original_caller'] ?? null;

        // Resolve caller name for placeholder substitution
        $callLog = $callLogId ? CallLog::find($callLogId) : null;
        $callerName = null;
        if ($callLog?->user_id) {
            $callerUser = User::find($callLog->user_id);
            $callerName = $callerUser?->first_name;
        }

        Log::channel('telnyx')->info('IVR gather ended', [
            'call_control_id' => $callControlId,
            'digits' => $digits,
            'call_log_id' => $callLogId,
        ]);

        $isKnownCaller = $clientState['is_known_caller'] ?? false;

        if ($digits === '1' && $isKnownCaller) {
            // ── Press 1: Re-dial (known callers only) ──
            Log::channel('telnyx')->info('IVR: caller pressed 1 — re-dialing', [
                'call_control_id' => $callControlId,
            ]);

            // Send emergency contact numbers to the caller via SMS
            $this->sendEmergencyContactsSms($originalCaller);

            $vendor = Vendor::find(1);
            $shortName = data_get($vendor?->options ?? [], 'short_name') ?: ($vendor?->business_name ?? 'the team');
            $press1Template = data_get($vendor?->options ?? [], 'ivr_press1_message')
                ?: "{name}, no problem! Let me try connecting you again. I also texted you emergency numbers in case you cannot get through again.";
            $press1Payload = str_replace(
                ['{name}', '{company}', '{greeting}'],
                [$callerName ?? '', $shortName, $this->buildTimeGreeting()],
                $press1Template
            );
            $press1Payload = preg_replace('/\s+/', ' ', trim($press1Payload));
            $press1Payload = preg_replace('/\s+([!.?,])/', '$1', $press1Payload);

            $this->sendCallCommand($callControlId, 'speak', [
                'payload' => $press1Payload,
                ...$this->ttsVoiceParams(),
                'client_state' => base64_encode(json_encode([
                    'action' => 'ivr_retry_connect',
                    'call_log_id' => $callLogId,
                    'original_caller' => $originalCaller,
                ])),
            ]);
        } elseif ($digits === '2') {
            // ── Press 2: Send SMS on caller's behalf ──
            Log::channel('telnyx')->info('IVR: caller pressed 2 — sending SMS', [
                'call_control_id' => $callControlId,
                'caller_number' => $originalCaller,
            ]);

            $this->sendCallbackSms($callLogId, $originalCaller);

            $vendor = Vendor::find(1);
            $shortName = data_get($vendor?->options ?? [], 'short_name') ?: ($vendor?->business_name ?? 'the team');
            $press2Template = data_get($vendor?->options ?? [], 'ivr_press2_message')
                ?: "Got it! We've sent a message to {company} letting them know you called. They should be reaching out to you shortly. Take care!";
            $press2Payload = str_replace(
                ['{name}', '{company}', '{greeting}'],
                [$callerName ?? '', $shortName, $this->buildTimeGreeting()],
                $press2Template
            );
            $press2Payload = preg_replace('/\s+/', ' ', trim($press2Payload));
            $press2Payload = preg_replace('/\s+([!.?,])/', '$1', $press2Payload);

            $this->sendCallCommand($callControlId, 'speak', [
                'payload' => $press2Payload,
                ...$this->ttsVoiceParams(),
                'client_state' => base64_encode(json_encode([
                    'action' => 'ivr_sms_confirmation',
                    'call_log_id' => $callLogId,
                ])),
            ]);

            // Update call log status
            if ($callLogId) {
                CallLog::where('id', $callLogId)->update([
                    'status' => CallLog::STATUS_MISSED,
                    'metadata->ivr_action' => 'sms_callback',
                ]);
            }
        } else {
            // ── No digit / timeout: play voicemail greeting then record ──
            Log::channel('telnyx')->info('IVR: no digit pressed — playing voicemail greeting', [
                'call_control_id' => $callControlId,
            ]);

            $vendor = Vendor::find(1);
            $shortName = data_get($vendor?->options ?? [], 'short_name') ?: ($vendor?->business_name ?? 'us');

            // Look up caller name
            $callerName = null;
            $callLog = $callLogId ? CallLog::find($callLogId) : null;
            if ($callLog?->user_id) {
                $callerUser = User::find($callLog->user_id);
                $callerName = $callerUser?->first_name;
            }

            $greetingTemplate = data_get($vendor?->options ?? [], 'voicemail_greeting')
                ?: "{name}, you've reached {company}. We can't get to the phone right now, but leave us a message after the beep and we'll get back to you shortly.";
            $greetingPayload = str_replace(
                ['{name}', '{company}', '{greeting}'],
                [$callerName ?? '', $shortName, $this->buildTimeGreeting()],
                $greetingTemplate
            );
            $greetingPayload = preg_replace('/\s+/', ' ', trim($greetingPayload));
            $greetingPayload = preg_replace('/\s+([!.?,])/', '$1', $greetingPayload);

            $this->sendCallCommand($callControlId, 'speak', [
                'payload' => $greetingPayload,
                ...$this->ttsVoiceParams(),
                'client_state' => base64_encode(json_encode([
                    'action' => 'voicemail_prompt_done',
                    'call_log_id' => $callLogId,
                ])),
            ]);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Send emergency contact numbers to the caller via SMS (IVR Press 1).
     * Texts the caller a list of enabled call recipient names and phone numbers.
     */
    protected function sendEmergencyContactsSms(?string $callerNumber): void
    {
        if (! $callerNumber) {
            return;
        }

        $vendor = Vendor::find(1);
        $vendorOptions = $vendor ? (array) $vendor->options : [];
        $recipientUserIds = (array) data_get($vendorOptions, 'call_recipients', []);

        if (empty($recipientUserIds)) {
            Log::channel('telnyx')->warning('Cannot send emergency contacts SMS — no call recipients configured');
            return;
        }

        $adminUsers = User::whereIn('id', $recipientUserIds)
            ->whereNotNull('cell_phone')
            ->where('cell_phone', '!=', '')
            ->get();

        if ($adminUsers->isEmpty()) {
            return;
        }

        // Build the contact list
        $contactLines = $adminUsers->map(function ($user) {
            $phone = $user->cell_phone;
            $digits = preg_replace('/\D/', '', $phone);

            // Format as (XXX) XXX-XXXX for readability
            if (strlen($digits) === 10) {
                $formatted = sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6));
            } elseif (strlen($digits) === 11 && str_starts_with($digits, '1')) {
                $formatted = sprintf('(%s) %s-%s', substr($digits, 1, 3), substr($digits, 4, 3), substr($digits, 7));
            } else {
                $formatted = $phone;
            }

            return $user->first_name . ' ' . $formatted;
        })->join("\n");

        $shortName = data_get($vendorOptions, 'short_name') ?: ($vendor?->business_name ?? 'our team');
        $smsText = "EMERGENCY NUMBERS for {$shortName}:\n{$contactLines}";

        $apiKey = config('services.telnyx.api_key');
        $from = config('services.telnyx.from');
        $messagingProfileId = config('services.telnyx.messaging_profile_id');

        if (! $apiKey || ! $from) {
            Log::channel('telnyx')->error('Cannot send emergency contacts SMS — Telnyx SMS not configured');
            return;
        }

        // Ensure caller number is E.164
        $toPhone = $callerNumber;
        if (! str_starts_with($toPhone, '+')) {
            $digits = preg_replace('/\D/', '', $toPhone);
            if (strlen($digits) === 10) {
                $toPhone = '+1' . $digits;
            } elseif (strlen($digits) === 11 && str_starts_with($digits, '1')) {
                $toPhone = '+' . $digits;
            }
        }

        // In dev, redirect to dev number
        if (app()->environment(['local', 'development']) && ($devTo = config('services.telnyx.dev_to'))) {
            Log::channel('telnyx')->info('Dev: redirecting emergency SMS', ['original' => $toPhone, 'redirected_to' => $devTo]);
            $toPhone = $devTo;
        }

        try {
            $payload = [
                'from' => $from,
                'to' => $toPhone,
                'text' => $smsText,
            ];

            if ($messagingProfileId) {
                $payload['messaging_profile_id'] = $messagingProfileId;
            }

            $response = Http::withToken($apiKey)
                ->post('https://api.telnyx.com/v2/messages', $payload);

            if ($response->successful()) {
                Log::channel('telnyx')->info('Emergency contacts SMS sent to caller', [
                    'caller_number' => $toPhone,
                    'contacts_count' => $adminUsers->count(),
                ]);
            } else {
                Log::channel('telnyx')->error('Failed to send emergency contacts SMS', [
                    'caller_number' => $toPhone,
                    'error' => $response->json(),
                ]);
            }
        } catch (\Exception $e) {
            Log::channel('telnyx')->error('Exception sending emergency contacts SMS', [
                'caller_number' => $toPhone,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send SMS to admin call recipients on behalf of the caller (IVR Press 2).
     */
    protected function sendCallbackSms(?int $callLogId, ?string $callerNumber): void
    {
        $vendor = Vendor::find(1);
        $vendorOptions = $vendor ? (array) $vendor->options : [];
        $recipientUserIds = (array) data_get($vendorOptions, 'call_recipients', []);

        if (empty($recipientUserIds) || ! $callerNumber) {
            Log::channel('telnyx')->warning('Cannot send callback SMS — no recipients or caller number', [
                'call_log_id' => $callLogId,
                'caller_number' => $callerNumber,
            ]);
            return;
        }

        // Look up caller name from call log
        $callLog = $callLogId ? CallLog::find($callLogId) : null;
        $callerName = null;
        if ($callLog?->user_id) {
            $callerUser = User::find($callLog->user_id);
            $callerName = $callerUser?->full_name;
        }

        $callerDisplay = $callerName
            ? "{$callerName} ({$callerNumber})"
            : $callerNumber;

        $smsText = "Missed call from {$callerDisplay}. They requested a callback via the phone menu. Please call them back ASAP.";

        $apiKey = config('services.telnyx.api_key');
        $from = config('services.telnyx.from');
        $messagingProfileId = config('services.telnyx.messaging_profile_id');

        if (! $apiKey || ! $from) {
            Log::channel('telnyx')->error('Cannot send callback SMS — Telnyx SMS not configured');
            return;
        }

        $adminUsers = User::whereIn('id', $recipientUserIds)
            ->whereNotNull('cell_phone')
            ->where('cell_phone', '!=', '')
            ->get();

        foreach ($adminUsers as $adminUser) {
            $phone = $adminUser->cell_phone;

            // Ensure E.164
            if (! str_starts_with($phone, '+')) {
                $digits = preg_replace('/\D/', '', $phone);
                if (strlen($digits) === 10) {
                    $phone = '+1' . $digits;
                } elseif (strlen($digits) === 11 && str_starts_with($digits, '1')) {
                    $phone = '+' . $digits;
                }
            }

            // In dev, redirect to dev number
            if (app()->environment(['local', 'development']) && ($devTo = config('services.telnyx.dev_to'))) {
                Log::channel('telnyx')->info('Dev: redirecting callback SMS', ['original' => $phone, 'redirected_to' => $devTo]);
                $phone = $devTo;
            }

            try {
                $payload = [
                    'from' => $from,
                    'to' => $phone,
                    'text' => $smsText,
                ];

                if ($messagingProfileId) {
                    $payload['messaging_profile_id'] = $messagingProfileId;
                }

                $response = Http::withToken($apiKey)
                    ->post('https://api.telnyx.com/v2/messages', $payload);

                if ($response->successful()) {
                    Log::channel('telnyx')->info('Callback SMS sent to admin', [
                        'admin_user_id' => $adminUser->id,
                        'admin_name' => $adminUser->full_name,
                        'phone' => $phone,
                    ]);
                } else {
                    Log::channel('telnyx')->error('Failed to send callback SMS', [
                        'admin_user_id' => $adminUser->id,
                        'phone' => $phone,
                        'error' => $response->json(),
                    ]);
                }
            } catch (\Exception $e) {
                Log::channel('telnyx')->error('Exception sending callback SMS', [
                    'admin_user_id' => $adminUser->id,
                    'phone' => $phone,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Get the forwarding destination phone number (config fallback).
     */
    protected function getForwardingDestination(): ?string
    {
        $destinations = config('services.telnyx.voice_forward_to');

        if (!$destinations) {
            return null;
        }

        // Support comma-separated list — use the first one for simple transfer
        $numbers = array_map('trim', explode(',', $destinations));

        return $numbers[0] ?? null;
    }

    /**
     * Send a call control command to the Telnyx API.
     */
    protected function sendCallCommand(string $callControlId, string $action, array $params = []): void
    {
        $apiKey = config('services.telnyx.api_key');

        if (!$apiKey) {
            Log::channel('telnyx')->error('Telnyx API key not configured for call control');
            return;
        }

        try {
            $response = Http::withToken($apiKey)
                ->post("https://api.telnyx.com/v2/calls/{$callControlId}/actions/{$action}", $params);

            if ($response->successful()) {
                Log::channel('telnyx')->info("Call command sent: {$action}", [
                    'call_control_id' => $callControlId,
                    'result' => $response->json('data.result'),
                ]);
            } else {
                Log::channel('telnyx')->error("Call command failed: {$action}", [
                    'call_control_id' => $callControlId,
                    'status' => $response->status(),
                    'error' => $response->json(),
                ]);
            }
        } catch (\Exception $e) {
            Log::channel('telnyx')->error("Exception sending call command: {$action}", [
                'call_control_id' => $callControlId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create a new conference via POST /v2/conferences and add the call as the first participant.
     * Stores the conference_id in CallLog metadata so subsequent participants can join by ID.
     *
     * @return string|null The conference ID if created successfully
     */
    protected function joinConference(string $callControlId, array $params = [], ?int $callLogId = null): ?string
    {
        Log::channel('telnyx')->info('joinConference CALLED (create new)', [
            'call_control_id' => $callControlId,
            'conference_name' => $params['name'] ?? null,
            'call_log_id' => $callLogId,
        ]);

        $apiKey = config('services.telnyx.api_key');

        if (! $apiKey) {
            Log::channel('telnyx')->error('Telnyx API key not configured for conference');
            return null;
        }

        $body = array_merge($params, [
            'call_control_id' => $callControlId,
        ]);

        try {
            $response = Http::withToken($apiKey)
                ->post('https://api.telnyx.com/v2/conferences', $body);

            if ($response->successful()) {
                $conferenceId = $response->json('data.id');

                Log::channel('telnyx')->info('Conference created', [
                    'call_control_id' => $callControlId,
                    'conference_id' => $conferenceId,
                    'conference_name' => $params['name'] ?? null,
                ]);

                // Store the conference_id in CallLog metadata so other legs can join by ID
                if ($callLogId && $conferenceId) {
                    $callLog = CallLog::find($callLogId);
                    if ($callLog) {
                        $metadata = $callLog->metadata ?? [];
                        $metadata['conference_id'] = $conferenceId;
                        $metadata['conference_name'] = $params['name'] ?? null;
                        $callLog->update(['metadata' => $metadata]);
                    }
                }

                return $conferenceId;
            } else {
                Log::channel('telnyx')->error('Conference create failed', [
                    'call_control_id' => $callControlId,
                    'conference_name' => $params['name'] ?? null,
                    'status' => $response->status(),
                    'error' => $response->json(),
                ]);
            }
        } catch (\Exception $e) {
            Log::channel('telnyx')->error('Conference create exception', [
                'call_control_id' => $callControlId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Add a call to an existing conference via POST /v2/conferences/{id}/actions/join.
     * This is the correct way to add subsequent participants to an already-created conference.
     */
    protected function addToConference(string $callControlId, string $conferenceId, array $params = []): void
    {
        Log::channel('telnyx')->info('addToConference CALLED', [
            'call_control_id' => $callControlId,
            'conference_id' => $conferenceId,
        ]);

        $apiKey = config('services.telnyx.api_key');

        if (! $apiKey) {
            Log::channel('telnyx')->error('Telnyx API key not configured for addToConference');
            return;
        }

        $body = array_merge($params, [
            'call_control_id' => $callControlId,
        ]);

        try {
            $response = Http::withToken($apiKey)
                ->post("https://api.telnyx.com/v2/conferences/{$conferenceId}/actions/join", $body);

            if ($response->successful()) {
                Log::channel('telnyx')->info('Participant added to conference', [
                    'call_control_id' => $callControlId,
                    'conference_id' => $conferenceId,
                ]);
            } else {
                Log::channel('telnyx')->error('addToConference failed', [
                    'call_control_id' => $callControlId,
                    'conference_id' => $conferenceId,
                    'status' => $response->status(),
                    'error' => $response->json(),
                ]);
            }
        } catch (\Exception $e) {
            Log::channel('telnyx')->error('addToConference exception', [
                'call_control_id' => $callControlId,
                'conference_id' => $conferenceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the conference ID from a CallLog's metadata.
     */
    protected function getConferenceId(?int $callLogId): ?string
    {
        if (! $callLogId) {
            return null;
        }

        $callLog = CallLog::find($callLogId);
        return $callLog?->metadata['conference_id'] ?? null;
    }

    /**
     * Announce a participant joining a conference via TTS to all existing participants.
     * Looks up the Telnyx conference by name, then uses the conference speak API.
     */
    protected function announceConferenceJoin(string $conferenceName, string $participantName): void
    {
        $apiKey = config('services.telnyx.api_key');

        if (! $apiKey) {
            return;
        }

        try {
            // Look up the conference by name to get its ID
            $listResponse = Http::withToken($apiKey)
                ->get('https://api.telnyx.com/v2/conferences', [
                    'filter[name]' => $conferenceName,
                ]);

            if (! $listResponse->successful()) {
                Log::channel('telnyx')->warning('Conference join announce: failed to list conferences', [
                    'conference_name' => $conferenceName,
                    'status' => $listResponse->status(),
                ]);
                return;
            }

            $conferences = $listResponse->json('data') ?? [];
            $conference = collect($conferences)->first();

            if (! $conference || ! ($conferenceId = $conference['id'] ?? null)) {
                Log::channel('telnyx')->warning('Conference join announce: conference not found', [
                    'conference_name' => $conferenceName,
                ]);
                return;
            }

            // Speak the join announcement to all conference participants
            $ttsPayload = "{$participantName} has joined the call.";

            $speakResponse = Http::withToken($apiKey)
                ->post("https://api.telnyx.com/v2/conferences/{$conferenceId}/actions/speak", [
                    'payload' => $ttsPayload,
                    ...$this->ttsVoiceParams(),
                ]);

            if ($speakResponse->successful()) {
                Log::channel('telnyx')->info('Conference join announced', [
                    'conference_id' => $conferenceId,
                    'conference_name' => $conferenceName,
                    'participant_name' => $participantName,
                ]);
            } else {
                Log::channel('telnyx')->warning('Conference join announce: speak failed', [
                    'conference_id' => $conferenceId,
                    'status' => $speakResponse->status(),
                    'error' => $speakResponse->json(),
                ]);
            }
        } catch (\Exception $e) {
            Log::channel('telnyx')->error('Conference join announce exception', [
                'conference_name' => $conferenceName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve a person's first name from their phone number.
     */
    protected function resolveNameFromPhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        $user = User::where('cell_phone', 'LIKE', "%{$digits}%")->first();

        return $user?->first_name;
    }

    /**
     * Resolve a person's first name from a call_control_id by looking up the call via Telnyx API.
     * Falls back to checking CallLog metadata for invited participants.
     */
    protected function resolveNameFromCallControlId(string $callControlId, ?CallLog $callLog): ?string
    {
        // Try to get the "to" number from the Telnyx call details
        $apiKey = config('services.telnyx.api_key');

        if ($apiKey) {
            try {
                $response = Http::withToken($apiKey)
                    ->get("https://api.telnyx.com/v2/calls/{$callControlId}");

                if ($response->successful()) {
                    $toNumber = $response->json('data.to') ?? null;
                    if ($toNumber) {
                        $name = $this->resolveNameFromPhone($toNumber);
                        if ($name) {
                            return $name;
                        }
                    }
                }
            } catch (\Exception $e) {
                // Fall through to metadata lookup
            }
        }

        // Fallback: check if we stored the forwarded_to number
        if ($callLog?->forwarded_to) {
            return $this->resolveNameFromPhone($callLog->forwarded_to);
        }

        return null;
    }

    /**
     * Handle message.sent event - message accepted by carrier.
     */
    protected function handleMessageSent(array $payload): JsonResponse
    {
        $data = $payload['data']['payload'] ?? [];
        
        Log::channel('telnyx')->info('Message sent', [
            'id' => $data['id'] ?? null,
            'to' => $data['to'][0]['phone_number'] ?? null,
            'from' => $data['from']['phone_number'] ?? null,
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle message.delivered event - confirmed delivery to handset.
     */
    protected function handleMessageDelivered(array $payload): JsonResponse
    {
        $data = $payload['data']['payload'] ?? [];
        
        Log::channel('telnyx')->info('Message delivered', [
            'id' => $data['id'] ?? null,
            'to' => $data['to'][0]['phone_number'] ?? null,
            'completed_at' => $data['completed_at'] ?? null,
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle message.failed event - delivery failed.
     */
    protected function handleMessageFailed(array $payload): JsonResponse
    {
        $data = $payload['data']['payload'] ?? [];
        $errors = $data['errors'] ?? [];
        
        Log::channel('telnyx')->error('Message delivery failed', [
            'id' => $data['id'] ?? null,
            'to' => $data['to'][0]['phone_number'] ?? null,
            'errors' => $errors,
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle message.received event - inbound SMS.
     */
    protected function handleMessageReceived(array $payload): JsonResponse
    {
        $data = $payload['data']['payload'] ?? [];
        
        $from = $data['from']['phone_number'] ?? null;
        // For Group MMS replies, 'to' contains multiple recipients.
        // Find our Telnyx number among them.
        $allTo = collect($data['to'] ?? [])
            ->pluck('phone_number')
            ->filter()
            ->values()
            ->all();
        $ourNumber = config('services.telnyx.from');
        $to = in_array($ourNumber, $allTo) ? $ourNumber : ($allTo[0] ?? null);

        $text = $data['text'] ?? '';
        $mediaUrls = collect($data['media'] ?? [])
            ->pluck('url')
            ->filter()
            ->values()
            ->all();
        
        Log::channel('telnyx')->info('Inbound SMS received', [
            'id' => $data['id'] ?? null,
            'from' => $from,
            'to' => $to,
            'all_to' => $allTo,
            'text' => $text,
            'media_count' => count($mediaUrls),
            'type' => $data['type'] ?? null,
        ]);

        $normalizedText = strtoupper(trim($text));
        if (in_array($normalizedText, ['START', 'STOP', 'HELP'], true)) {
            Log::channel('telnyx')->info('Inbound compliance keyword received (provider/carrier may auto-respond)', [
                'keyword' => $normalizedText,
                'from' => $from,
                'to' => $to,
            ]);
        }

        // Find the group thread this message belongs to
        $thread = null;
        if ($from && $to) {
            $thread = SmsGroupThread::findByParticipant($to, $from);

            // In dev, outbound SMS is redirected to TELNYX_DEV_TO so replies come
            // from that number instead of the real participant. Fall back to the
            // most-recently-active thread for our Telnyx number when no exact match.
            if (! $thread && app()->environment(['local', 'development'])) {
                $devTo = config('services.telnyx.dev_to');
                if ($devTo && $from === $devTo) {
                    // The thread from_number may be the current or a previous Telnyx number,
                    // so just find the most recently active thread overall.
                    $thread = SmsGroupThread::orderByDesc('last_activity_at')->first();

                    if ($thread) {
                        Log::channel('telnyx')->info('Dev fallback: matched inbound to most recent thread', [
                            'thread_id' => $thread->id,
                            'dev_from' => $from,
                        ]);
                    }
                }
            }
        }

        // Prevent duplicate messages from repeated webhook deliveries
        $providerId = $data['id'] ?? null;
        $existingMessage = $providerId
            ? SmsMessage::where('provider_message_id', $providerId)
                ->where('direction', SmsMessage::DIRECTION_INBOUND)
                ->first()
            : null;

        if ($existingMessage) {
            Log::channel('telnyx')->info('Duplicate inbound SMS webhook detected - skipping', [
                'provider_message_id' => $providerId,
                'from' => $from,
                'to' => $to,
            ]);
            return response()->json(['status' => 'ok']);
        }

        // Store the inbound message
        $message = SmsMessage::create([
            'thread_id' => $thread?->id,
            'provider' => 'telnyx',
            'provider_message_id' => $data['id'] ?? null,
            'direction' => SmsMessage::DIRECTION_INBOUND,
            'from_number' => $from,
            'to_numbers' => $to ? [$to] : [],
            'text' => $text,
            'media_urls' => $mediaUrls ?: null,
            'raw_payload' => $data,
            'status' => 'received',
        ]);

        if (! empty($mediaUrls)) {
            StoreSmsMedia::dispatch($message->id);
        }

        if ($thread) {
            if (GroupSmsService::isStartKeyword($text)) {
                $welcomeSent = $this->groupSmsService->markParticipantOptedInAndSendWelcomeIfReady($thread, $from);

                Log::channel('telnyx')->info('Inbound START processed', [
                    'thread_id' => $thread->id,
                    'from' => $from,
                    'welcome_sent' => $welcomeSent,
                ]);
            }

            $thread->update(['last_activity_at' => now()]);

            try {
                \App\Events\SmsMessageReceived::dispatch($thread->id);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('SMS broadcast failed', [
                    'thread_id' => $thread->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            // Auto-create a new thread for this inbound message
            $thread = $this->createThreadForInboundMessage($from, $to);

            if ($thread) {
                $message->update(['thread_id' => $thread->id]);

                try {
                    \App\Events\SmsMessageReceived::dispatch($thread->id);
                } catch (\Throwable $e) {
                    Log::warning('SMS broadcast failed', [
                        'thread_id' => $thread->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        SendInboundSmsBrowserNotifications::dispatch($message->id);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Create a new thread for an inbound message from an unknown sender.
     *
     * Attempts to match the sender's phone number to a client (via
     * client home_phone or user cell_phone) so the thread is linked.
     */
    protected function createThreadForInboundMessage(string $senderPhone, string $ourNumber): ?SmsGroupThread
    {
        $normalizedSender = GroupSmsService::formatE164($senderPhone);

        // Attempt to match to an existing client
        $clientId = $this->resolveClientIdByPhone($normalizedSender);

        $thread = SmsGroupThread::create([
            'from_number' => $ourNumber,
            'participants' => [$normalizedSender],
            'client_id' => $clientId,
            'last_activity_at' => now(),
        ]);

        SmsThreadParticipant::create([
            'thread_id' => $thread->id,
            'phone_number' => $normalizedSender,
        ]);

        Log::channel('telnyx')->info('Auto-created new thread for inbound message', [
            'thread_id' => $thread->id,
            'from' => $senderPhone,
            'to' => $ourNumber,
            'client_id' => $clientId,
        ]);

        return $thread;
    }

    /**
     * Try to find a client by a phone number (checking client home_phone and user cell_phone).
     *
     * Phone numbers are stored as digits only, so we strip the E.164 prefix to compare.
     */
    protected function resolveClientIdByPhone(string $e164Phone): ?int
    {
        // Strip the leading + and country code for a 10-digit comparison
        $digits = preg_replace('/[^0-9]/', '', $e164Phone);

        // If 11 digits starting with 1 (US), take last 10
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        // Check client home_phone (stored as 10 digits)
        $client = Client::whereRaw("REPLACE(home_phone, '-', '') = ?", [$digits])->first();
        if ($client) {
            return $client->id;
        }

        // Check user cell_phone (stored as digits) — find client via pivot
        $user = User::where('cell_phone', $digits)->first();
        if ($user) {
            $client = $user->clients()->first();
            if ($client) {
                return $client->id;
            }
        }

        return null;
    }

    /**
     * Forward a message to other group participants.
     */
    protected function forwardToGroupParticipants(SmsGroupThread $thread, string $senderPhone, string $text, array $originalData): void
    {
        $otherParticipants = $thread->getOtherParticipants($senderPhone);

        if (empty($otherParticipants)) {
            Log::channel('telnyx')->info('No other participants to forward to', [
                'thread_id' => $thread->id,
                'sender' => $senderPhone,
            ]);
            return;
        }

        // Get sender identifier (last 4 digits of phone)
        $senderLabel = substr($senderPhone, -4);
        $forwardedText = "[{$senderLabel}]: {$text}";

        $apiKey = config('services.telnyx.api_key');
        $messagingProfileId = config('services.telnyx.messaging_profile_id');

        if (!$apiKey) {
            Log::channel('telnyx')->error('Telnyx API key not configured for forwarding');
            return;
        }

        // Check if there's media to forward (MMS)
        $mediaUrls = $originalData['media'] ?? [];

        foreach ($otherParticipants as $recipient) {
            // In dev, redirect to dev number
            if (app()->environment(['local', 'development']) && ($devTo = config('services.telnyx.dev_to'))) {
                Log::channel('telnyx')->info('Dev: redirecting forwarded SMS', ['original' => $recipient, 'redirected_to' => $devTo]);
                $recipient = $devTo;
            }

            try {
                $payload = [
                    'from' => $thread->from_number,
                    'to' => $recipient,
                    'text' => $forwardedText,
                ];

                if ($messagingProfileId) {
                    $payload['messaging_profile_id'] = $messagingProfileId;
                }

                if (!empty($mediaUrls)) {
                    $payload['media_urls'] = array_column($mediaUrls, 'url');
                }

                $response = Http::withToken($apiKey)
                    ->post('https://api.telnyx.com/v2/messages', $payload);

                if ($response->successful()) {
                    Log::channel('telnyx')->info('Forwarded message to group participant', [
                        'thread_id' => $thread->id,
                        'from' => $senderPhone,
                        'to' => $recipient,
                        'message_id' => $response->json('data.id'),
                    ]);
                } else {
                    Log::channel('telnyx')->error('Failed to forward message', [
                        'thread_id' => $thread->id,
                        'to' => $recipient,
                        'error' => $response->json(),
                    ]);
                }
            } catch (\Exception $e) {
                Log::channel('telnyx')->error('Exception forwarding message', [
                    'thread_id' => $thread->id,
                    'to' => $recipient,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Update thread last activity
        $thread->update(['last_activity_at' => now()]);
    }

    /**
     * Handle message.finalized event - final status.
     */
    protected function handleMessageFinalized(array $payload): JsonResponse
    {
        $data = $payload['data']['payload'] ?? [];
        
        Log::channel('telnyx')->info('Message finalized', [
            'id' => $data['id'] ?? null,
            'to' => $data['to'][0]['phone_number'] ?? null,
            'status' => $data['to'][0]['status'] ?? null,
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle unknown event types.
     */
    protected function handleUnknownEvent(?string $eventType, array $payload): JsonResponse
    {
        Log::channel('telnyx')->warning('Unknown Telnyx event type', [
            'event_type' => $eventType,
            'payload' => $payload,
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Get TTS voice parameters for Telnyx speak/gather commands.
     *
     * @return array{voice: string, language: string, payload_type: string, voice_settings: array{type: string}}
     */
    protected function ttsVoiceParams(): array
    {
        return [
            'voice' => config('services.telnyx.tts_voice'),
            'language' => 'en-US',
            'payload_type' => 'text',
            'voice_settings' => [
                'type' => config('services.telnyx.tts_voice_type'),
            ],
        ];
    }
}
