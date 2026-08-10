<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendInboundSmsBrowserNotifications;
use App\Events\InboundCallJoined;
use App\Jobs\SendCallAnsweredBrowserNotifications;
use App\Jobs\SendIncomingCallBrowserNotifications;
use App\Jobs\SendVoicemailBrowserNotifications;
use App\Jobs\StoreCallRecording;
use App\Jobs\StoreSmsMedia;
use App\Models\BlockedCaller;
use App\Models\CallLog;
use App\Models\CallTranscript;
use App\Models\Client;
use App\Models\SmsGroupThread;
use App\Models\SmsLog;
use App\Models\SmsMessage;
use App\Models\SmsThreadParticipant;
use App\Models\User;
use App\Models\Vendor;
use App\Services\GroupSmsService;
use App\Services\SpamFilterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelnyxWebhookController extends Controller
{
    /**
     * A voicemail menu gather that ran at least this long must have spoken its
     * prompt (the menu is ~14-18s of TTS; a gather that never speaks ends after
     * just its ~2s DTMF timeout).
     */
    private const VOICEMAIL_PROMPT_MIN_SECONDS = 5.0;

    /**
     * Voice events we deliberately do nothing with. Conference bookkeeping is
     * already tracked from the commands we issue (conference id + participants
     * live in call_logs.metadata), and playback.started/hold/unhold carry no
     * information we act on. Listed here rather than falling through to
     * handleUnknownVoiceEvent so they cost one 200 response, not a log pair.
     */
    private const IGNORED_VOICE_EVENTS = [
        'conference.floor.changed',
        'conference.participant.joined',
        'conference.participant.left',
        'conference.created',
        'conference.ended',
        'call.playback.started',
        'call.hold',
        'call.unhold',
    ];

    public function __construct(
        protected GroupSmsService $groupSmsService,
        protected SpamFilterService $spamFilterService,
    ) {
    }

    /**
     * Health check endpoint for monitoring webhook receiver availability.
     * Can be called periodically to ensure the webhook endpoint is responding.
     * No authentication required.
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'healthy',
            'timestamp' => now()->toIso8601String(),
            'signature_verification' => ! empty(config('services.telnyx.public_key')) ? 'enabled' : 'disabled',
        ]);
    }

    /**
     * Handle incoming Telnyx messaging webhooks.
     * Signature verification is handled by the telnyx.signature middleware.
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        $eventType = $payload['data']['event_type'] ?? null;

        Log::channel('telnyx')->info('Telnyx webhook received', [
            'event_type' => $eventType,
            'message_id' => $payload['data']['payload']['id'] ?? null,
        ]);

        if ($this->isDuplicateWebhookEvent($payload['data'] ?? [])) {
            return response()->json(['status' => 'ok', 'message' => 'duplicate event ignored']);
        }

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
     * Signature verification is handled by the telnyx.signature middleware.
     */
    public function handleVoice(Request $request): JsonResponse
    {
        $payload = $request->all();
        $data = $payload['data'] ?? [];
        $eventType = $data['event_type'] ?? null;

        // Telnyx has no per-event webhook filter, so the noisy conference
        // events arrive whether we want them or not — conference.floor.changed
        // alone fires on EVERY speaker change and was 412 of 616 voice webhooks
        // on 2026-07-31 (67%). Drop them before the two log writes and the
        // dedup cache round-trip: each one otherwise occupies a PHP-FPM worker
        // that a real call event could be waiting on.
        if (in_array($eventType, self::IGNORED_VOICE_EVENTS, true)) {
            return response()->json(['status' => 'ok']);
        }

        Log::channel('telnyx')->info('Telnyx voice webhook received', [
            'event_type' => $eventType,
            'call_control_id' => $data['payload']['call_control_id'] ?? null,
            'direction' => $data['payload']['direction'] ?? null,
        ]);

        if (($data['record_type'] ?? null) !== 'event') {
            return response()->json(['status' => 'ok']);
        }

        if ($this->isDuplicateWebhookEvent($data)) {
            return response()->json(['status' => 'ok', 'message' => 'duplicate event ignored']);
        }

        try {
            // Capture webhook receive timestamp for setup timing metrics
            if ($eventType === 'call.initiated') {
                $data['_webhook_received_at'] = now()->timestamp;
            }

            return match ($eventType) {
                'call.initiated' => $this->handleCallInitiated($data),
                'call.answered' => $this->handleCallAnswered($data),
                'call.hangup' => $this->handleCallHangup($data),
                'call.bridged' => $this->handleCallBridged($data),
                'call.leave' => $this->handleCallLeave($data),
                'call.speak.ended' => $this->handleCallSpeakEnded($data),
                'call.speak.started' => $this->handleCallSpeakStarted($data),
                'call.playback.ended' => $this->handleCallPlaybackEnded($data),
                'call.gather.ended' => $this->handleCallGatherEnded($data),
                'call.dtmf.received' => $this->handleCallDtmfReceived($data),
                'call.recording.saved' => $this->handleCallRecordingSaved($data),
                'call.recording.transcription.saved' => $this->handleCallRecordingTranscriptionSaved($data),
                'call.machine.detection.ended' => $this->handleAmdEnded($data),
                'call.machine.premium.detection.ended' => $this->handleAmdEnded($data),
                'call.machine.premium.greeting.ended' => $this->handleAmdGreetingEnded($data),
                default => $this->handleUnknownVoiceEvent($eventType, $data),
            };
        } catch (\Throwable $e) {
            Log::channel('telnyx')->error('Voice webhook handler exception', [
                'event_type' => $eventType,
                'call_control_id' => $data['payload']['call_control_id'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Attempt graceful cleanup: hang up the call to prevent it from getting stuck
            try {
                $callControlId = $data['payload']['call_control_id'] ?? null;
                if ($callControlId && ! empty(config('services.telnyx.api_key'))) {
                    Log::channel('telnyx')->warning('Emergency hangup initiated due to webhook handler exception', [
                        'call_control_id' => $callControlId,
                    ]);
                    $this->telnyxHttp(0)->delete(
                        "https://api.telnyx.com/v2/calls/{$callControlId}"
                    );
                }
            } catch (\Throwable $hangupEx) {
                Log::channel('telnyx')->warning('Emergency hangup failed', [
                    'call_control_id' => $data['payload']['call_control_id'] ?? null,
                    'error' => $hangupEx->getMessage(),
                ]);
            }

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // Voice Call Control Handlers
    // =========================================================================

    /**
     * Handle call.initiated - answer incoming calls.
     * Flow: answer → play TTS welcome → dial admins → bridge first to answer.
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
        if ($incomingFrom && GroupSmsService::isOurNumber($incomingFrom)) {
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

        // ── Spam filter ──────────────────────────────────────────────
        // Check blocked callers list, STIR/SHAKEN attestation, and
        // ipqualityscore spam database before accepting the call.
        $vendor = Vendor::find(1); // TODO: Multi-vendor support
        $vendorOptions = $vendor ? (array) $vendor->options : [];
        $attestation = data_get($payload, 'sip_headers.x-verstat')
            ?? data_get($payload, 'custom_headers.x-verstat')
            ?? data_get($payload, 'stir_shaken.verstat')
            ?? null;
        // Telnyx sends verstat values like "TN-Validation-Passed-A"
        if (is_string($attestation) && preg_match('/Passed-([ABC])$/i', $attestation, $m)) {
            $attestation = strtoupper($m[1]);
        } elseif (! in_array($attestation, ['A', 'B', 'C'], true)) {
            $attestation = null;
        }

        // Pre-check if caller is known (without creating CallLog yet)
        $isKnownCaller = false;
        if ($incomingFrom) {
            $normalizedPhone = preg_replace('/[^0-9]/', '', $incomingFrom);
            $isKnownCaller = User::where('cell_phone', 'LIKE', "%{$normalizedPhone}")
                ->orWhere('cell_phone', 'LIKE', "%{$incomingFrom}")
                ->exists()
                || Client::where('home_phone', 'LIKE', "%{$normalizedPhone}")
                ->orWhere('home_phone', 'LIKE', "%{$incomingFrom}")
                ->exists();
        }

        $spamResult = $this->spamFilterService->evaluate([
            'phone_number' => $incomingFrom ?? 'unknown',
            'vendor_id' => $vendor?->id,
            'is_known_caller' => $isKnownCaller,
            'stir_shaken_attestation' => $attestation,
            'spam_filter_enabled' => true,
            'spam_sensitivity' => 'medium',
        ]);

        if ($spamResult['blocked']) {
            Log::channel('telnyx')->info('Spam call detected — answering with spam voicemail prompt', [
                'call_control_id' => $callControlId,
                'from' => $incomingFrom,
                'reason' => $spamResult['reason'],
                'attestation' => $attestation,
            ]);

            // Log blocked call so it appears in the Calls tab. Even if the
            // caller leaves a voicemail or sends an SMS via Press 2, the
            // STATUS_BLOCKED tag is preserved so legitimate misidentified
            // callers can still get through while spam is logged for review.
            $spamCallLog = CallLog::create([
                'call_id' => $callControlId,
                'call_control_id' => $callControlId,
                'call_session_id' => $payload['call_session_id'] ?? null,
                'call_leg_id' => $payload['call_leg_id'] ?? null,
                'connection_id' => $payload['connection_id'] ?? null,
                'direction' => 'incoming',
                'from_number' => $payload['from'] ?? 'unknown',
                'to_number' => $payload['to'] ?? config('services.telnyx.from'),
                'status' => CallLog::STATUS_BLOCKED,
                'metadata' => [
                    'blocked_reason' => $spamResult['reason'],
                    'attestation' => $attestation,
                ],
            ]);

            // Answer the call so we can play the spam-screening prompt and
            // give misidentified callers a Press 2 / voicemail escape hatch.
            $this->sendCallCommand($callControlId, 'answer', [
                'send_silence_when_idle' => true,
                'preferred_codecs' => config('services.telnyx.preferred_codecs'),
                'client_state' => base64_encode(json_encode([
                    'action' => 'spam_voicemail',
                    'call_log_id' => $spamCallLog->id,
                    'original_caller' => $payload['from'] ?? null,
                ])),
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
                'initiated_at' => now()->toDateTimeString(),
                'webhook_received_at' => $data['_webhook_received_at'] ?? now()->timestamp,
            ],
        ]);

        // Try to match caller to a user
        $user = $callLog->lookUpCaller();
        if ($user) {
            $callLog->update([
                'user_id' => $user->id,
                'caller_name' => $user->full_name,
            ]);
        } else {
            // Unknown caller — attempt Telnyx CNAM reverse lookup for internal records
            // but do NOT use the CNAM name in TTS greetings (it's a carrier-registered
            // name, not appropriate for personalized greetings)
            $cnamName = $callLog->lookUpCallerViaCnam();

            // If CNAM fails, fall back to name from a previous call log for this number
            if (! $cnamName && $callLog->from_number) {
                $cnamName = CallLog::where('from_number', $callLog->from_number)
                    ->where('id', '!=', $callLog->id)
                    ->whereNotNull('caller_name')
                    ->whereNotIn('caller_name', ['Incoming Call', 'Outgoing Call'])
                    ->latest()
                    ->value('caller_name');
            }

            if ($cnamName) {
                $callLog->update(['caller_name' => $cnamName]);
            }
        }

        // Send browser push notification to admins: "{Caller Name} is Calling"
        SendIncomingCallBrowserNotifications::dispatch($callLog->id);

        Log::channel('telnyx')->info('Incoming call - answering', [
            'call_control_id' => $callControlId,
            'from' => $payload['from'] ?? null,
            'to' => $payload['to'] ?? null,
            'caller_user_id' => $user?->id,
            'caller_name' => $callLog->caller_name,
        ]);

        // Answer the call — on call.answered we'll play TTS then ring admins
        // send_silence_when_idle keeps RTP flowing during silence while waiting
        // for an admin to answer and bridge.
        // Only use the caller's first name for TTS if they are a known user in our system.
        // CNAM reverse-lookup names (from carrier registration) should not be used in TTS
        // greetings — they are often formal/all-caps and not appropriate for a personal greeting.
        $callerFirstName = $user ? $user->first_name : null;
        $this->sendCallCommand($callControlId, 'answer', [
            'send_silence_when_idle' => true,
            'preferred_codecs' => config('services.telnyx.preferred_codecs'),
            'client_state' => base64_encode(json_encode([
                'action' => 'welcome_or_ring',
                'call_log_id' => $callLog->id,
                'original_caller' => $payload['from'] ?? null,
                'caller_name' => $callerFirstName,
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
     *  - click_to_call: User answered their phone → play ringback and dial target
     *  - click_to_call_target_ring: Target answered → play TTS intro then bridge
     *  - admin_ring: Admin answered → play TTS then bridge with incoming caller
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

        // ── Spam Voicemail: spam-flagged call answered → play spam prompt + voicemail menu ──
        if ($action === 'spam_voicemail') {
            return $this->handleSpamVoicemailAnswered($callControlId, $clientState);
        }

        // ── Admin Ring: an admin answered → bridge with the incoming caller ──
        if ($action === 'admin_ring') {
            return $this->handleAdminRingAnswered($callControlId, $clientState);
        }

        // Other legs (e.g. transfer destinations) — do nothing
        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle click-to-call user answering their phone — play ringback, then dial target.
     *
     * Flow: user answers → hears ringback → target is dialed → target hears TTS intro → bridge.
     */
    protected function handleClickToCallAnswered(string $callControlId, array $clientState): JsonResponse
    {
        $callLogId = $clientState['call_log_id'] ?? null;
        $callLog = $callLogId ? CallLog::find($callLogId) : null;
        $targetPhones = $this->extractClickToCallTargets($clientState, $callLog);

        // If multi-recipient IDs were selected, add those recipients' phones to the target list
        $metadata = $callLog?->metadata ?? [];
        if (! empty($metadata['click_to_call_recipient_ids'])) {
            $recipientIds = (array) $metadata['click_to_call_recipient_ids'];
            $recipientUsers = User::whereIn('id', $recipientIds)
                ->whereNotNull('cell_phone')
                ->where('cell_phone', '!=', '')
                ->get();

            foreach ($recipientUsers as $user) {
                $phone = $this->normalizeDialPhone($user->cell_phone);
                if ($phone && ! in_array($phone, $targetPhones, true)) {
                    $targetPhones[] = $phone;
                }
            }

            Log::channel('telnyx')->info('Click-to-call: including selected recipients', [
                'recipient_ids' => $recipientIds,
                'recipient_count' => $recipientUsers->count(),
                'total_targets' => count($targetPhones),
            ]);
        }

        $callLog?->update([
            'status' => CallLog::STATUS_ANSWERED,
            'answered_at' => now(),
        ]);

        if (empty($targetPhones)) {
            Log::channel('telnyx')->error('Click-to-call: no target phone(s) in client_state');
            $this->sendCallCommand($callControlId, 'hangup');
            $callLog?->update(['status' => CallLog::STATUS_FAILED, 'hangup_cause' => 'no_target_phone']);
            return response()->json(['status' => 'ok']);
        }

        // Resolve caller display name from user record
        $callerUser = $callLog?->user_id ? User::find($callLog->user_id) : null;
        $callerFirstName = $callerUser?->first_name ?? 'Someone';

        Log::channel('telnyx')->info('Click-to-call: user answered → playing ringback and dialing target(s)', [
            'call_control_id' => $callControlId,
            'target_phones' => $targetPhones,
            'target_count' => count($targetPhones),
            'call_log_id' => $callLogId,
        ]);

        // Play ringback tone to the user while target is being dialed
        $holdAudioUrl = config('services.telnyx.hold_audio_url')
            ?: $this->telnyxBaseUrl() . '/telnyx-audio/ringback.wav';
        $this->sendCallCommand($callControlId, 'playback_start', [
            'audio_url' => $holdAudioUrl,
            'client_state' => base64_encode(json_encode([
                'action' => 'click_to_call_waiting',
                'call_log_id' => $callLogId,
            ])),
        ]);

        // Dial target phone number(s)
        $apiKey = config('services.telnyx.api_key');
        $connectionId = config('services.telnyx.connection_id');
        $from = config('services.telnyx.from');

        $targetCallControlIds = [];

        try {
            foreach ($targetPhones as $targetPhone) {
                $response = $this->telnyxHttp(0)
                    ->post('https://api.telnyx.com/v2/calls', [
                        'connection_id' => $connectionId,
                        'to' => $targetPhone,
                        'from' => $from,
                        'from_display_name' => 'GS Construction',
                        'timeout_secs' => (int) config('services.telnyx.voice_timeout', 30),
                        'preferred_codecs' => config('services.telnyx.preferred_codecs'),
                        'answering_machine_detection' => 'premium',
                        'answering_machine_detection_config' => [
                            'after_greeting_silence_millis' => 800,
                            'total_analysis_time_millis' => 5000,
                        ],
                        'client_state' => base64_encode(json_encode([
                            'action' => 'click_to_call_target_ring',
                            'call_log_id' => $callLogId,
                            'caller_name' => $callerFirstName,
                            'target_phone' => $targetPhone,
                            'target_phones' => $targetPhones,
                            'user_call_control_id' => $callControlId,
                        ])),
                        'webhook_url' => $this->telnyxBaseUrl() . '/webhooks/telnyx/voice',
                    ]);

                if (! $response->successful()) {
                    Log::channel('telnyx')->error('Click-to-call: failed to dial target', [
                        'target_phone' => $targetPhone,
                        'status' => $response->status(),
                        'error' => $response->json(),
                    ]);
                    continue;
                }

                $data = $response->json('data') ?? [];
                $targetCcId = $data['call_control_id'] ?? null;
                if ($targetCcId) {
                    $targetCallControlIds[] = $targetCcId;
                }

                Log::channel('telnyx')->info('Click-to-call: target dialed', [
                    'target_phone' => $targetPhone,
                    'target_call_control_id' => $targetCcId,
                ]);
            }

            if ($callLog) {
                $metadata = $callLog->metadata ?? [];
                $metadata['target_phone'] = $targetPhones[0] ?? null;
                $metadata['target_phones'] = $targetPhones;
                $metadata['target_call_control_id'] = $targetCallControlIds[0] ?? null;
                $metadata['target_call_control_ids'] = $targetCallControlIds;
                $metadata['failed_target_call_control_ids'] = [];
                $callLog->update([
                    'forwarded_to' => $targetPhones[0] ?? null,
                    'metadata' => $metadata,
                ]);
            }

            if (empty($targetCallControlIds)) {
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
     * Handle click-to-call target answering — bridge with user immediately.
     */
    protected function handleClickToCallTargetAnswered(string $callControlId, array $clientState): JsonResponse
    {
        $callLogId = $clientState['call_log_id'] ?? null;
        $userCallControlId = $clientState['user_call_control_id'] ?? null;
        $callLog = $callLogId ? CallLog::find($callLogId) : null;

        Log::channel('telnyx')->info('Click-to-call: target answered — playing disclosure then bridging', [
            'target_call_control_id' => $callControlId,
            'user_call_control_id' => $userCallControlId,
            'call_log_id' => $callLogId,
        ]);

        // Start recording on the target leg without beep (beep will be replaced with
        // verbal disclosure TTS). Dual-channel captures target audio + bridged-in user audio.
        if (config('call_recording.mode') === 'auto' && $callLog) {
            // Temporarily override play_beep to false since we're using verbal disclosure
            $this->sendCallCommand($callControlId, 'record_start', [
                'channels' => config('call_recording.channels', 'dual'),
                'format' => config('call_recording.format', 'wav'),
                'play_beep' => false,  // No beep; using verbal disclosure instead
                'client_state' => base64_encode(json_encode([
                    'action' => 'call_recording',
                    'call_log_id' => $callLog->id,
                ])),
            ]);
            $callLog->update([
                'recording_started_at' => now(),
                'purge_after' => now()->addDays((int) config('call_recording.retention_days', 180)),
            ]);
        }

        // Set the bridged flag IMMEDIATELY so any in-flight `call.playback.ended`
        // webhooks for the user's ringback don't re-loop the ringback audio
        // over the conference. Without this, ringback would keep restarting
        // for ~30s while we created the conference and bridged in, drowning out
        // the target's audio (especially noticeable when the target is voicemail).
        if ($userCallControlId) {
            Cache::put("telnyx_bridged:{$userCallControlId}", true, now()->addMinutes(60));
        }
        Cache::put("telnyx_bridged:{$callControlId}", true, now()->addMinutes(60));

        if ($userCallControlId) {
            if (! $callLog) {
                Log::channel('telnyx')->error('Click-to-call target answered but call log missing', [
                    'target_call_control_id' => $callControlId,
                    'call_log_id' => $callLogId,
                ]);
                $this->sendCallCommand($callControlId, 'hangup');
                return response()->json(['status' => 'ok']);
            }

            if (in_array($callLog->status, [CallLog::STATUS_COMPLETED, CallLog::STATUS_MISSED, CallLog::STATUS_FAILED], true)) {
                Log::channel('telnyx')->info('Click-to-call target answered after call already ended — hanging up target leg', [
                    'target_call_control_id' => $callControlId,
                    'call_log_id' => $callLogId,
                    'status' => $callLog->status,
                ]);
                $this->sendCallCommand($callControlId, 'hangup');
                return response()->json(['status' => 'ok']);
            }

            $metadata = $callLog->metadata ?? [];
            $conferenceId = $metadata['conference_id'] ?? null;

            if (! is_string($conferenceId) || $conferenceId === '') {
                $conferenceLockKey = "telnyx_click_to_call_conference_lock:{$callLogId}";
                if (Cache::add($conferenceLockKey, 'creating', 30)) {
                    $conferenceName = "click_to_call_{$callLogId}_" . substr(md5($userCallControlId), 0, 8);
                    // Stop the ringback on the USER's leg only. The target just
                    // answered and never had playback started, so stopping it
                    // there was a wasted round trip sitting in the connect path
                    // (5 sequential Telnyx calls between answer and audio).
                    $this->sendCallCommand($userCallControlId, 'playback_stop');

                    $conferenceId = $this->createConference($conferenceName, $userCallControlId);

                    if (! $conferenceId) {
                        Log::channel('telnyx')->error('Click-to-call: failed to create conference', [
                            'call_log_id' => $callLogId,
                            'target_call_control_id' => $callControlId,
                        ]);
                        $this->sendCallCommand($callControlId, 'hangup');
                        Cache::forget($conferenceLockKey);
                        $callLog->update(['status' => CallLog::STATUS_FAILED]);
                        return response()->json(['status' => 'ok']);
                    }

                    $metadata['conference_id'] = $conferenceId;
                    $metadata['conference_name'] = $conferenceName;
                    $metadata['joined_target_call_control_ids'] = $metadata['joined_target_call_control_ids'] ?? [];

                    $callLog->update(['metadata' => $metadata]);
                    Cache::forget($conferenceLockKey);
                }

                $callLog = $callLog->fresh();
                $metadata = $callLog?->metadata ?? [];
                $conferenceId = $metadata['conference_id'] ?? null;
            }

            if (! is_string($conferenceId) || $conferenceId === '') {
                Log::channel('telnyx')->error('Click-to-call: no conference available after target answered', [
                    'call_log_id' => $callLogId,
                    'target_call_control_id' => $callControlId,
                ]);
                $this->sendCallCommand($callControlId, 'hangup');
                return response()->json(['status' => 'ok']);
            }

            if (! $this->joinConference($conferenceId, $callControlId)) {
                Log::channel('telnyx')->warning('Click-to-call: target conference join failed', [
                    'call_log_id' => $callLogId,
                    'target_call_control_id' => $callControlId,
                    'conference_id' => $conferenceId,
                ]);
                $this->sendCallCommand($callControlId, 'hangup');
                return response()->json(['status' => 'ok']);
            }

            Cache::put("telnyx_bridged:{$userCallControlId}", true, now()->addMinutes(60));
            Cache::put("telnyx_bridged:{$callControlId}", true, now()->addMinutes(60));

            $joinedTargetCallControlIds = collect($metadata['joined_target_call_control_ids'] ?? [])
                ->filter(fn ($id) => is_string($id) && $id !== '')
                ->push($callControlId)
                ->unique()
                ->values()
                ->all();

            $metadata['joined_target_call_control_ids'] = $joinedTargetCallControlIds;
            $metadata['answered_target_call_control_id'] = $metadata['answered_target_call_control_id'] ?? $callControlId;

            $callLog->update([
                'status' => CallLog::STATUS_TRANSFERRED,
                'metadata' => $metadata,
            ]);

            // Play outbound recording disclosure to the target after they've joined the conference
            if (config('call_recording.disclosure.enabled') && config('call_recording.outbound_disclosure.enabled')) {
                $disclosurePhrase = trim((string) config('call_recording.outbound_disclosure.phrase'));
                if ($disclosurePhrase !== '') {
                    Log::channel('telnyx')->info('Click-to-call: playing outbound recording disclosure', [
                        'target_call_control_id' => $callControlId,
                        'call_log_id' => $callLogId,
                        'disclosure' => $disclosurePhrase,
                    ]);

                    $this->sendCallCommand($callControlId, 'speak', [
                        'payload' => $disclosurePhrase,
                        ...$this->ttsVoiceParams(),
                        'client_state' => base64_encode(json_encode([
                            'action' => 'click_to_call_target_intro_done',
                            'call_log_id' => $callLogId,
                            'user_call_control_id' => $userCallControlId,
                        ])),
                    ]);
                }
            }
        } else {
            Log::channel('telnyx')->error('No user call control ID for bridge', [
                'call_log_id' => $callLogId,
            ]);
            $this->sendCallCommand($callControlId, 'hangup');
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle click-to-call target not answering (timeout/busy/rejected).
     * Notify the user that the target didn't answer, then hang up.
     */
    protected function handleClickToCallTargetHangup(string $callControlId, array $clientState, ?string $hangupCause): JsonResponse
    {
        $callLogId = $clientState['call_log_id'] ?? null;
        $userCallControlId = $clientState['user_call_control_id'] ?? null;
        $callLog = $callLogId ? CallLog::find($callLogId) : null;
        $metadata = $callLog ? ($callLog->metadata ?? []) : [];
        $joinedTargetLegIds = collect($metadata['joined_target_call_control_ids'] ?? [])
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();

        // STATUS_TRANSFERRED or STATUS_COMPLETED means both parties were talking.
        // STATUS_ANSWERED just means the initiating user picked up their phone,
        // NOT that the target answered — so don't treat it as "was connected".
        $wasConnected = $callLog && in_array($callLog->status, [CallLog::STATUS_TRANSFERRED, CallLog::STATUS_COMPLETED]);

        if ($wasConnected && ! in_array($callControlId, $joinedTargetLegIds, true)) {
            Log::channel('telnyx')->info('Click-to-call: non-joined sibling target leg ended while active call continues', [
                'call_control_id' => $callControlId,
                'hangup_cause' => $hangupCause,
                'call_log_id' => $callLogId,
                'joined_target_call_control_ids' => $joinedTargetLegIds,
            ]);

            return response()->json(['status' => 'ok']);
        }

        if ($wasConnected) {
            $remainingJoinedTargetLegIds = collect($joinedTargetLegIds)
                ->reject(fn ($id) => $id === $callControlId)
                ->values()
                ->all();

            if (! empty($remainingJoinedTargetLegIds)) {
                $metadata['joined_target_call_control_ids'] = $remainingJoinedTargetLegIds;
                $callLog?->update(['metadata' => $metadata]);

                Log::channel('telnyx')->info('Click-to-call: one joined target left, other joined targets remain active', [
                    'call_control_id' => $callControlId,
                    'hangup_cause' => $hangupCause,
                    'call_log_id' => $callLogId,
                    'remaining_joined_target_call_control_ids' => $remainingJoinedTargetLegIds,
                ]);

                return response()->json(['status' => 'ok']);
            }

            $duration = $callLog->answered_at
                ? abs(now()->diffInSeconds($callLog->answered_at))
                : null;

            $callLog->update([
                'status' => CallLog::STATUS_COMPLETED,
                'hangup_cause' => $hangupCause,
                'duration_seconds' => $duration,
                'ended_at' => now(),
            ]);

            Log::channel('telnyx')->info('Click-to-call: target hung up after connected call', [
                'call_control_id' => $callControlId,
                'hangup_cause' => $hangupCause,
                'call_log_id' => $callLogId,
                'duration' => $duration,
            ]);

            return response()->json(['status' => 'ok']);
        }

        Log::channel('telnyx')->info('Click-to-call: target did not answer', [
            'call_control_id' => $callControlId,
            'hangup_cause' => $hangupCause,
            'call_log_id' => $callLogId,
        ]);

        if ($callLog && $this->markFailedTargetAndHasRemaining($callLog, $callControlId)) {
            Log::channel('telnyx')->info('Click-to-call: waiting on remaining target legs', [
                'call_log_id' => $callLogId,
                'failed_target_call_control_id' => $callControlId,
            ]);
            return response()->json(['status' => 'ok']);
        }

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
     * Handle incoming call answered by us — play TTS welcome and ring admins.
     * After TTS, caller hears ringback while waiting for an admin to answer and bridge.
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

        // Recording starts AFTER welcome+disclosure TTS finishes (see handleCallSpeakEnded
        // welcome_done branch) so the system prompt isn't captured in the audio.
        // Compliance audit trail is preserved via the recording_disclosure_played flag.

        // Get vendor options for phone system settings
        // TODO: Multi-vendor support — look up vendor by connection_id
        $vendor = Vendor::find(1);
        $vendorOptions = $vendor ? (array) $vendor->options : [];

        // After-hours: skip welcome + admin ring, send straight to voicemail menu.
        if ($vendor && ! $vendor->isWithinBusinessHours()) {
            Log::channel('telnyx')->info('Inbound call outside business hours — routing to voicemail', [
                'call_control_id' => $callControlId,
                'call_log_id' => $callLogId,
                'business_hours' => $vendor->businessHours(),
                'vendor_timezone' => $vendor->timezone,
            ]);
            $afterHoursMessage = data_get($vendorOptions, 'after_hours_message')
                ?: \App\Livewire\Vendors\VendorOptions::DEFAULT_AFTER_HOURS;
            $this->triggerVoicemail($callControlId, $callLogId, $afterHoursMessage);
            return response()->json(['status' => 'ok']);
        }

        // Build welcome TTS payload
        $shortName = data_get($vendorOptions, 'short_name') ?: ($vendor?->business_name ?? 'our team');
        $greeting = $this->buildTimeGreeting();
        $isKnownCaller = ! empty($callerName);
        if ($isKnownCaller) {
            $welcomeTemplate = data_get($vendorOptions, 'welcome_message')
                ?: \App\Livewire\Vendors\VendorOptions::DEFAULT_WELCOME;
        } else {
            $welcomeTemplate = data_get($vendorOptions, 'welcome_message_unknown')
                ?: \App\Livewire\Vendors\VendorOptions::DEFAULT_WELCOME_UNKNOWN;
        }
        // Append recording disclosure after the welcome (kept short to satisfy
        // IL two-party consent). The phrase is captured in the recording
        // itself, providing a defensible audit trail. It is appended to the
        // raw template *before* rendering so the whole prompt is wrapped in a
        // single SSML <speak> envelope (appending after </speak> would emit
        // invalid SSML).
        if (config('call_recording.mode') === 'auto' && config('call_recording.disclosure.enabled')) {
            $disclosure = trim((string) (data_get($vendorOptions, 'recording_disclosure_message')
                ?: config('call_recording.disclosure.phrase')));
            if ($disclosure !== '') {
                $welcomeBase = rtrim($welcomeTemplate);
                if ($welcomeBase !== '' && ! preg_match('/[.!?]$/', $welcomeBase)) {
                    $welcomeBase .= '.';
                }
                $welcomeTemplate = $welcomeBase . ' ' . $disclosure;
                $callLog?->update(['recording_disclosure_played' => true]);
            }
        }

        $ttsPayload = $this->renderPrompt($welcomeTemplate, [
            '{name}' => $callerName ?? '',
            '{company}' => $shortName,
            '{greeting}' => $greeting,
        ]);

        Log::channel('telnyx')->info('Playing welcome TTS and dialing admins simultaneously', [
            'call_control_id' => $callControlId,
            'tts' => $ttsPayload,
            'is_known_caller' => $isKnownCaller,
        ]);

        // Play TTS to the caller — admins are dialed simultaneously below
        $this->sendCallCommand($callControlId, 'speak', [
            'payload' => $ttsPayload,
            ...$this->ttsVoiceParams(),
            'client_state' => base64_encode(json_encode([
                'action' => 'welcome_done',
                'call_log_id' => $callLogId,
                'original_caller' => $originalCaller,
            ])),
        ]);

        // Dial admins now while TTS is playing — if an admin answers before TTS
        // finishes, they'll hear hold music until TTS completes, then get bridged.
        $this->dialAdmins($callControlId, $callLogId, $originalCaller);

        return response()->json(['status' => 'ok']);
    }

    // joinCallerToConference removed — using bridge mode instead of conferences.

    /**
     * Handle an admin answering the ring.
     * If TTS is still playing to the caller, hold the admin until TTS finishes.
     * If TTS is already done, bridge immediately.
     */
    protected function handleAdminRingAnswered(string $callControlId, array $clientState): JsonResponse
    {
        $callLogId = $clientState['call_log_id'] ?? null;
        $incomingCallControlId = $clientState['incoming_call_control_id'] ?? null;
        $adminUserId = $clientState['admin_user_id'] ?? null;
        $callLog = $callLogId ? CallLog::find($callLogId) : null;

        if (! $callLog || ! $incomingCallControlId) {
            Log::channel('telnyx')->error('Admin ring answered but missing call log or incoming call ID', [
                'call_control_id' => $callControlId,
                'call_log_id' => $callLogId,
            ]);
            $this->sendCallCommand($callControlId, 'hangup');
            return response()->json(['status' => 'ok']);
        }

        // If the caller disconnected entirely, hang up this leg.
        if (in_array($callLog->status, [CallLog::STATUS_COMPLETED, CallLog::STATUS_MISSED])) {
            Log::channel('telnyx')->info('Admin answered but caller disconnected — hanging up', [
                'call_control_id' => $callControlId,
                'call_log_id' => $callLogId,
                'call_status' => $callLog->status,
            ]);
            $this->sendCallCommand($callControlId, 'hangup');
            return response()->json(['status' => 'ok']);
        }

        // Play a screening prompt to the answering admin: "{Caller} is calling.
        // Hang up now to send them to voicemail or remain on the line to
        // connect." On speak.ended (admin_screen_done) we'll either join the
        // existing conference (late answerer) or create one (first answerer).
        // If the admin hangs up during the prompt, the admin_ring hangup
        // handler removes them and may trigger voicemail.
        $metadata = $callLog->metadata ?? [];
        $joinedAdminIds = $metadata['joined_admin_ids'] ?? [];
        $conferenceId = $metadata['conference_id'] ?? null;
        $isLateAnswerer = ! empty($joinedAdminIds) && $conferenceId;

        return $this->playAdminScreeningPrompt(
            $callControlId,
            $callLog,
            $incomingCallControlId,
            $adminUserId,
            $isLateAnswerer ? $conferenceId : null,
        );
    }

    /**
     * Speak the screening prompt to the answering admin. The prompt tells them
     * who's calling and that hanging up sends the caller to voicemail. On
     * speak.ended (action=admin_screen_done) the bridge/join is performed.
     */
    protected function playAdminScreeningPrompt(
        string $callControlId,
        CallLog $callLog,
        string $incomingCallControlId,
        ?int $adminUserId,
        ?string $existingConferenceId,
    ): JsonResponse {
        $callerLabel = $callLog->caller_name ?: 'Someone';

        // TODO: Multi-vendor support — look up vendor by connection_id
        $vendor = Vendor::find(1);
        $vendorOptions = $vendor ? (array) $vendor->options : [];
        $shortName = data_get($vendorOptions, 'short_name') ?: ($vendor?->business_name ?? 'our team');
        $greeting = $this->buildTimeGreeting();
        $template = data_get($vendorOptions, 'screening_message')
            ?: \App\Livewire\Vendors\VendorOptions::DEFAULT_SCREENING;
        $promptText = $this->renderPrompt($template, [
            '{name}' => $callerLabel,
            '{company}' => $shortName,
            '{greeting}' => $greeting,
        ]);

        Log::channel('telnyx')->info('Playing admin screening prompt', [
            'admin_call_control_id' => $callControlId,
            'call_log_id' => $callLog->id,
            'admin_user_id' => $adminUserId,
            'is_late_answerer' => $existingConferenceId !== null,
            'caller_label' => $callerLabel,
        ]);

        $this->sendCallCommand($callControlId, 'speak', [
            'payload' => $promptText,
            ...$this->ttsVoiceParams(),
            'client_state' => base64_encode(json_encode([
                'action' => 'admin_screen_done',
                'call_log_id' => $callLog->id,
                'incoming_call_control_id' => $incomingCallControlId,
                'admin_user_id' => $adminUserId,
                'conference_id' => $existingConferenceId,
            ])),
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Called from handleCallSpeakEnded when the admin screening prompt finishes
     * and the admin has stayed on the line. Join existing conference (late
     * answerer) or claim the bridge lock and create a new one.
     */
    protected function handleAdminScreenDone(string $callControlId, array $clientState): JsonResponse
    {
        $callLogId = $clientState['call_log_id'] ?? null;
        $incomingCallControlId = $clientState['incoming_call_control_id'] ?? null;
        $adminUserId = $clientState['admin_user_id'] ?? null;
        $existingConferenceId = $clientState['conference_id'] ?? null;
        $callLog = $callLogId ? CallLog::find($callLogId) : null;

        if (! $callLog || ! $incomingCallControlId) {
            $this->sendCallCommand($callControlId, 'hangup');
            return response()->json(['status' => 'ok']);
        }

        // If this admin leg already hung up while the screening prompt was
        // playing, Telnyx still fires call.speak.ended for the interrupted
        // speak. Don't try to bridge a dead admin — route the caller to the
        // voicemail IVR instead so they aren't left in silence.
        if (Cache::has("telnyx_admin_dead:{$callControlId}")) {
            Log::channel('telnyx')->info('Screening prompt ended but admin already hung up — routing caller to voicemail', [
                'admin_call_control_id' => $callControlId,
                'call_log_id' => $callLogId,
                'incoming_call_control_id' => $incomingCallControlId,
            ]);

            // Best-effort: only trigger voicemail if the caller is still on
            // the line. triggerVoicemail() is idempotent so it's safe even if
            // handleAdminRingHangup already triggered it.
            if (! in_array($callLog->status, [CallLog::STATUS_COMPLETED, CallLog::STATUS_MISSED])) {
                $this->triggerVoicemail($incomingCallControlId, $callLogId);
            }

            return response()->json(['status' => 'ok']);
        }

        // If the caller disconnected during the screening prompt, hang up the admin.
        if (in_array($callLog->status, [CallLog::STATUS_COMPLETED, CallLog::STATUS_MISSED])) {
            Log::channel('telnyx')->info('Screening prompt done but caller disconnected — hanging up admin', [
                'admin_call_control_id' => $callControlId,
                'call_log_id' => $callLogId,
            ]);
            $this->sendCallCommand($callControlId, 'hangup');
            return response()->json(['status' => 'ok']);
        }

        // Late answerer: a conference already exists.
        if ($existingConferenceId) {
            Log::channel('telnyx')->info('Late admin screening done — joining existing conference', [
                'admin_call_control_id' => $callControlId,
                'call_log_id' => $callLogId,
                'admin_user_id' => $adminUserId,
                'conference_id' => $existingConferenceId,
            ]);
            $this->joinAdminToConference($callControlId, $callLogId, $adminUserId, $existingConferenceId);
            return response()->json(['status' => 'ok']);
        }

        // Maybe another admin became the first joiner during our prompt — re-check.
        $callLog->refresh();
        $freshConferenceId = ($callLog->metadata ?? [])['conference_id'] ?? null;
        if ($freshConferenceId) {
            Log::channel('telnyx')->info('Screening done — conference appeared during prompt, joining', [
                'admin_call_control_id' => $callControlId,
                'call_log_id' => $callLogId,
                'conference_id' => $freshConferenceId,
            ]);
            $this->joinAdminToConference($callControlId, $callLogId, $adminUserId, $freshConferenceId);
            return response()->json(['status' => 'ok']);
        }

        // First answerer who passed the screening: bridge into a new conference.
        return $this->bridgeFirstAdmin($callControlId, $incomingCallControlId, $callLog, $adminUserId);
    }

    /**
     * First admin to answer: claim the bridge lock, then either start the
     * conference (if caller's intro TTS is done) or hold the admin until it is.
     */
    protected function bridgeFirstAdmin(string $callControlId, string $incomingCallControlId, CallLog $callLog, ?int $adminUserId): JsonResponse
    {
        $callLogId = $callLog->id;

        Log::channel('telnyx')->info('First admin answered — bridging without prompt', [
            'admin_call_control_id' => $callControlId,
            'incoming_call_control_id' => $incomingCallControlId,
            'call_log_id' => $callLogId,
            'admin_user_id' => $adminUserId,
        ]);

        // Race-safe: claim the bridge lock. If someone else already became the
        // first joiner between our admin_ring dispatch and the answer event,
        // join the existing conference instead.
        $bridgeLockKey = "telnyx_bridge_lock:{$callLogId}";
        if (! Cache::add($bridgeLockKey, 'admin_answered', 60)) {
            $callLog->refresh();
            $conferenceId = ($callLog->metadata ?? [])['conference_id'] ?? null;
            if ($conferenceId) {
                Log::channel('telnyx')->info('Lost first-join race — joining existing conference', [
                    'call_control_id' => $callControlId,
                    'call_log_id' => $callLogId,
                    'conference_id' => $conferenceId,
                ]);
                $this->joinAdminToConference($callControlId, $callLogId, $adminUserId, $conferenceId);
            } else {
                $this->sendCallCommand($callControlId, 'hangup');
            }
            return response()->json(['status' => 'ok']);
        }

        $metadata = $callLog->metadata ?? [];
        $ttsComplete = ! empty($metadata['tts_complete'])
            || Cache::has("telnyx_tts_complete:{$callLogId}");

        if ($ttsComplete) {
            $this->startConferenceWithCaller($callControlId, $callLogId, $incomingCallControlId, $adminUserId);
        } else {
            // Hold the admin until the caller's intro TTS finishes; the speak.ended
            // handler will pick the pending admin up and start the conference.
            Log::channel('telnyx')->info('Admin answered but TTS still playing — holding admin', [
                'admin_call_control_id' => $callControlId,
                'call_log_id' => $callLogId,
            ]);

            $metadata['pending_admin_call_control_id'] = $callControlId;
            $metadata['pending_admin_user_id'] = $adminUserId;
            $callLog->update(['metadata' => $metadata]);

            $holdAudioUrl = config('services.telnyx.hold_audio_url')
                ?: $this->telnyxBaseUrl() . '/telnyx-audio/ringback.wav';
            $this->sendCallCommand($callControlId, 'playback_start', [
                'audio_url' => $holdAudioUrl,
                'client_state' => base64_encode(json_encode([
                    'action' => 'admin_waiting_for_tts',
                    'call_log_id' => $callLogId,
                    'incoming_call_control_id' => $incomingCallControlId,
                    'admin_user_id' => $adminUserId,
                ])),
            ]);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle the result of the admin DTMF screening gather.
     * If admin pressed a key → confirmed human, proceed to bridge.
     * If no keypress (timeout) → voicemail or unattended, hang up.
     */
    protected function handleAdminScreenResult(string $callControlId, string $digits, array $clientState): JsonResponse
    {
        $callLogId = $clientState['call_log_id'] ?? null;
        $incomingCallControlId = $clientState['incoming_call_control_id'] ?? null;
        $adminUserId = $clientState['admin_user_id'] ?? null;
        $callLog = $callLogId ? CallLog::find($callLogId) : null;

        if (! $callLog || ! $incomingCallControlId) {
            $this->sendCallCommand($callControlId, 'hangup');
            return response()->json(['status' => 'ok']);
        }

        if (empty($digits)) {
            // No keypress — carrier voicemail or admin didn't interact
            Log::channel('telnyx')->info('Admin screen timeout (no keypress) — hanging up', [
                'call_control_id' => $callControlId,
                'call_log_id' => $callLogId,
            ]);
            $this->sendCallCommand($callControlId, 'hangup');
            return response()->json(['status' => 'ok']);
        }

        // Admin pressed a key — confirmed human
        Log::channel('telnyx')->info('Admin screen passed (keypress confirmed human)', [
            'call_control_id' => $callControlId,
            'digits' => $digits,
            'call_log_id' => $callLogId,
            'admin_user_id' => $adminUserId,
        ]);

        // If caller disconnected, abort
        if (in_array($callLog->status, [CallLog::STATUS_COMPLETED, CallLog::STATUS_MISSED])) {
            $this->sendCallCommand($callControlId, 'hangup');
            return response()->json(['status' => 'ok']);
        }

        // If a conference already exists (race: another admin became first), join it.
        $callLog->refresh();
        $metadata = $callLog->metadata ?? [];
        $conferenceId = $metadata['conference_id'] ?? null;

        if ($conferenceId) {
            $this->joinAdminToConference($callControlId, $callLogId, $adminUserId, $conferenceId);
            return response()->json(['status' => 'ok']);
        }

        // ── First admin to confirm: claim the bridge lock and create conference ──
        $bridgeLockKey = "telnyx_bridge_lock:{$callLogId}";
        if (! Cache::add($bridgeLockKey, 'admin_screen', 60)) {
            // Lost the race — somebody else became the first joiner. If a
            // conference now exists, join it; otherwise hang up gracefully.
            $callLog->refresh();
            $conferenceId = ($callLog->metadata ?? [])['conference_id'] ?? null;
            if ($conferenceId) {
                Log::channel('telnyx')->info('Lost first-join race — joining conference instead', [
                    'call_control_id' => $callControlId,
                    'call_log_id' => $callLogId,
                    'conference_id' => $conferenceId,
                ]);
                $this->joinAdminToConference($callControlId, $callLogId, $adminUserId, $conferenceId);
            } else {
                $this->sendCallCommand($callControlId, 'hangup');
            }
            return response()->json(['status' => 'ok']);
        }

        // Check TTS completion (race-safe via cache)
        $ttsComplete = ! empty($metadata['tts_complete'])
            || Cache::has("telnyx_tts_complete:{$callLogId}");

        if ($ttsComplete) {
            // TTS done — start the conference now
            $this->startConferenceWithCaller($callControlId, $callLogId, $incomingCallControlId, $adminUserId);
        } else {
            // TTS still playing — hold this admin until TTS finishes; speak.ended
            // handler will pick the pending admin up and start the conference.
            Log::channel('telnyx')->info('Admin confirmed but TTS still playing — holding admin', [
                'admin_call_control_id' => $callControlId,
                'call_log_id' => $callLogId,
            ]);

            $metadata['pending_admin_call_control_id'] = $callControlId;
            $metadata['pending_admin_user_id'] = $adminUserId;
            $callLog->update(['metadata' => $metadata]);

            $holdAudioUrl = config('services.telnyx.hold_audio_url')
                ?: $this->telnyxBaseUrl() . '/telnyx-audio/ringback.wav';
            $this->sendCallCommand($callControlId, 'playback_start', [
                'audio_url' => $holdAudioUrl,
                'client_state' => base64_encode(json_encode([
                    'action' => 'admin_waiting_for_tts',
                    'call_log_id' => $callLogId,
                    'incoming_call_control_id' => $incomingCallControlId,
                    'admin_user_id' => $adminUserId,
                ])),
            ]);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Create a conference, drop the caller into it, then drop the first admin into it.
     */
    protected function startConferenceWithCaller(string $adminCallControlId, int $callLogId, string $incomingCallControlId, ?int $adminUserId): void
    {
        $callLog = CallLog::find($callLogId);
        if (! $callLog) {
            return;
        }

        $adminUser = $adminUserId ? User::find($adminUserId) : null;
        $conferenceName = "call_{$callLogId}_" . substr(md5($incomingCallControlId), 0, 8);

        Log::channel('telnyx')->info('Creating conference with caller as first participant', [
            'call_log_id' => $callLogId,
            'conference_name' => $conferenceName,
            'incoming_call_control_id' => $incomingCallControlId,
            'admin_call_control_id' => $adminCallControlId,
            'admin_user_id' => $adminUserId,
        ]);

        // Mark BOTH legs bridged BEFORE stopping the ringback — never after.
        // playback_stop makes Telnyx fire call.playback.ended immediately, and
        // handleCallPlaybackEnded re-loops the ringback unless this flag is
        // already set. Setting it after createConference() (an HTTP round trip)
        // left a ~1s window in which the caller's ringback restarted twice and
        // then played OVER the live conference — the caller heard ring tone
        // instead of the admin and assumed nobody had picked up.
        // (handleClickToCallTargetAnswered has always ordered it this way.)
        Cache::put("telnyx_bridged:{$incomingCallControlId}", true, now()->addMinutes(60));
        Cache::put("telnyx_bridged:{$adminCallControlId}", true, now()->addMinutes(60));

        // Stop any audio on both legs before joining the conference.
        $this->sendCallCommand($incomingCallControlId, 'playback_stop');
        $this->sendCallCommand($adminCallControlId, 'playback_stop');

        // Create the conference seeded with the caller's leg.
        $conferenceId = $this->createConference($conferenceName, $incomingCallControlId);
        if (! $conferenceId) {
            Log::channel('telnyx')->error('Failed to create conference — hanging up admin leg', [
                'call_log_id' => $callLogId,
            ]);
            // Release the bridge lock so another answering admin can retry, and
            // clear the flags so the caller's hold audio can resume.
            Cache::forget("telnyx_bridge_lock:{$callLogId}");
            Cache::forget("telnyx_bridged:{$incomingCallControlId}");
            Cache::forget("telnyx_bridged:{$adminCallControlId}");
            $this->sendCallCommand($adminCallControlId, 'hangup');
            return;
        }

        // Persist conference id and mark first admin joined.
        $metadata = $callLog->metadata ?? [];
        $metadata['conference_id'] = $conferenceId;
        $metadata['conference_name'] = $conferenceName;
        $joinedAdmins = $metadata['joined_admin_ids'] ?? [];
        if ($adminUserId) {
            $joinedAdmins[] = $adminUserId;
        }
        $metadata['joined_admin_ids'] = array_values(array_unique($joinedAdmins));

        // Track timing for admin to join the call
        if (! empty($metadata['admin_dial_initiated_at'])) {
            $dialTime = new \DateTime($metadata['admin_dial_initiated_at']);
            $joinSeconds = now()->diffInSeconds($dialTime);
            $metadata['admin_join_time_seconds'] = $joinSeconds;
        }

        $callLog->update([
            'status' => CallLog::STATUS_TRANSFERRED,
            'forwarded_to' => $adminUser?->cell_phone,
            'metadata' => $metadata,
        ]);

        // Join the admin to the conference.
        if (! $this->joinConference($conferenceId, $adminCallControlId)) {
            Log::channel('telnyx')->info('Admin leg gone before conference join — rolling back to voicemail', [
                'admin_call_control_id' => $adminCallControlId,
                'conference_id' => $conferenceId,
            ]);

            // The admin leg died between answering and joining the conference
            // (most often: rejected the screening prompt by hanging up). The
            // caller is now alone in a freshly-created conference and would
            // otherwise hear silence/comfort-noise indefinitely. Roll back the
            // joined-admin bookkeeping and route them straight to voicemail.
            $rollback = $callLog->fresh()?->metadata ?? $metadata;
            $rollback['joined_admin_ids'] = array_values(array_filter(
                $rollback['joined_admin_ids'] ?? [],
                fn ($id) => $id !== $adminUserId
            ));
            $rollback['admin_call_control_ids'] = array_values(array_filter(
                $rollback['admin_call_control_ids'] ?? [],
                fn ($cc) => $cc !== $adminCallControlId
            ));
            unset($rollback['conference_id'], $rollback['conference_name']);
            $callLog->update([
                'status' => CallLog::STATUS_ANSWERED,
                'forwarded_to' => null,
                'metadata' => $rollback,
            ]);
            Cache::forget("telnyx_bridged:{$incomingCallControlId}");
            Cache::forget("telnyx_bridged:{$adminCallControlId}");
            Cache::forget("telnyx_bridge_lock:{$callLogId}");
            Cache::put("telnyx_admin_dead:{$adminCallControlId}", true, now()->addMinutes(10));

            $this->sendCallCommand($adminCallControlId, 'hangup');

            // If other admin legs are still ringing, let them keep trying.
            // Otherwise, send the caller to voicemail right now so they aren't
            // stranded in an empty conference.
            $stillRinging = ! empty($rollback['admin_call_control_ids']);
            if (! $stillRinging) {
                Log::channel('telnyx')->warning('Admin hung up before joining conference — no other admins ringing, triggering voicemail', [
                    'incoming_call_control_id' => $incomingCallControlId,
                    'call_log_id' => $callLogId,
                ]);
                $this->triggerVoicemail($incomingCallControlId, $callLogId);
            } else {
                $holdAudioUrl = config('services.telnyx.hold_audio_url')
                    ?: $this->telnyxBaseUrl() . '/telnyx-audio/ringback.wav';

                $this->sendCallCommand($incomingCallControlId, 'playback_start', [
                    'audio_url' => $holdAudioUrl,
                    'client_state' => base64_encode(json_encode([
                        'action' => 'caller_waiting',
                        'call_log_id' => $callLogId,
                    ])),
                ]);

                Log::channel('telnyx')->info('Admin hung up before joining conference — other admins still ringing, waiting', [
                    'incoming_call_control_id' => $incomingCallControlId,
                    'call_log_id' => $callLogId,
                    'remaining_admin_legs' => count($rollback['admin_call_control_ids']),
                ]);
            }
            return;
        }

        if ($adminUserId) {
            SendCallAnsweredBrowserNotifications::dispatch($callLogId, $adminUserId);
            event(new InboundCallJoined($adminUserId, $callLogId));
        }

        // NOTE: we intentionally do NOT hang up other ringing admin legs here.
        // They keep ringing for their original timeout so a second/third recipient
        // can answer and join the conference with the "already connected" prompt.
    }

    /**
     * Join an additional admin into an existing conference (late answerer or invite).
     */
    protected function joinAdminToConference(string $adminCallControlId, int $callLogId, ?int $adminUserId, string $conferenceId): void
    {
        $callLog = CallLog::find($callLogId);
        if (! $callLog) {
            $this->sendCallCommand($adminCallControlId, 'hangup');
            return;
        }

        $this->sendCallCommand($adminCallControlId, 'playback_stop');

        if (! $this->joinConference($conferenceId, $adminCallControlId)) {
            $this->sendCallCommand($adminCallControlId, 'hangup');
            return;
        }

        Cache::put("telnyx_bridged:{$adminCallControlId}", true, now()->addMinutes(60));

        $metadata = $callLog->metadata ?? [];
        $joinedAdmins = $metadata['joined_admin_ids'] ?? [];
        if ($adminUserId) {
            $joinedAdmins[] = $adminUserId;
        }
        $metadata['joined_admin_ids'] = array_values(array_unique($joinedAdmins));

        // Track this leg in admin_call_control_ids so DTMF lookup can locate it
        $adminCcIds = $metadata['admin_call_control_ids'] ?? [];
        if (! in_array($adminCallControlId, $adminCcIds, true)) {
            $adminCcIds[] = $adminCallControlId;
        }
        $metadata['admin_call_control_ids'] = array_values(array_unique($adminCcIds));

        $callLog->update(['metadata' => $metadata]);

        if ($adminUserId) {
            SendCallAnsweredBrowserNotifications::dispatch($callLogId, $adminUserId);
            event(new InboundCallJoined($adminUserId, $callLogId));
        }

        Log::channel('telnyx')->info('Admin joined conference', [
            'admin_call_control_id' => $adminCallControlId,
            'conference_id' => $conferenceId,
            'call_log_id' => $callLogId,
            'admin_user_id' => $adminUserId,
        ]);
    }

    /**
     * Bridge an admin with the incoming caller (compat shim).
     *
     * Now routes through the conference flow: if a conference already exists
     * for this call log, the admin joins it; otherwise a new conference is
     * created with the caller and admin as the first two participants.
     */
    protected function bridgeAdminWithCaller(string $adminCallControlId, int $callLogId, string $incomingCallControlId, ?int $adminUserId): void
    {
        $callLog = CallLog::find($callLogId);
        if (! $callLog) {
            return;
        }

        $conferenceId = ($callLog->metadata ?? [])['conference_id'] ?? null;
        if ($conferenceId) {
            $this->joinAdminToConference($adminCallControlId, $callLogId, $adminUserId, $conferenceId);
            return;
        }

        $this->startConferenceWithCaller($adminCallControlId, $callLogId, $incomingCallControlId, $adminUserId);
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
        if (in_array($action, ['admin_ring', 'admin_screen', 'admin_waiting_for_tts'], true)) {
            return $this->handleAdminRingHangup($callControlId, $clientState, $hangupCause);
        }

        // ── Click-to-call target didn't answer (timeout/busy/rejected) ──
        if ($action === 'click_to_call_target_ring') {
            return $this->handleClickToCallTargetHangup($callControlId, $clientState, $hangupCause);
        }

        // ── Click-to-call user leg hung up (after failure TTS or while waiting) ──
        // Don't let the standard handler overwrite status set by target leg handling.
        // answered_at on these calls refers to when the USER picked up, not the target.
        if (in_array($action, ['click_to_call_failed_tts', 'click_to_call', 'click_to_call_waiting'])) {
            $callLogId = $clientState['call_log_id'] ?? null;
            $callLog = $callLogId ? CallLog::find($callLogId) : null;

            if ($callLog) {
                if (! in_array($callLog->status, [CallLog::STATUS_TRANSFERRED, CallLog::STATUS_COMPLETED], true)) {
                    $this->hangupOutstandingClickToCallTargets($callLog);
                }

                $duration = $callLog->answered_at
                    ? abs(now()->diffInSeconds($callLog->answered_at))
                    : null;

                if ($callLog->status === CallLog::STATUS_TRANSFERRED) {
                    // Call was bridged — normal end of a connected call
                    $callLog->update([
                        'status' => CallLog::STATUS_COMPLETED,
                        'hangup_cause' => $hangupCause,
                        'duration_seconds' => $duration,
                        'ended_at' => now(),
                    ]);
                } elseif ($callLog->status !== CallLog::STATUS_COMPLETED) {
                    // Call was never bridged — preserve existing status (missed/failed)
                    $callLog->update([
                        'hangup_cause' => $hangupCause,
                        'ended_at' => now(),
                    ]);
                }
            }

            Log::channel('telnyx')->info('Click-to-call user leg ended', [
                'call_control_id' => $callControlId,
                'action' => $action,
                'status' => $callLog->status ?? null,
                'call_log_id' => $callLogId,
                'hangup_cause' => $hangupCause,
            ]);

            return response()->json(['status' => 'ok']);
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
                        ? abs(now()->diffInSeconds($callLog->answered_at))
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
            // Don't overwrite blocked calls — the hangup is just cleanup after we rejected them
            if ($callLog->status === CallLog::STATUS_BLOCKED) {
                $callLog->update([
                    'hangup_cause' => $hangupCause,
                    'ended_at' => now(),
                ]);

                Log::channel('telnyx')->info('Blocked call hangup (status preserved)', [
                    'call_control_id' => $callControlId,
                    'hangup_cause' => $hangupCause,
                ]);

                return response()->json(['status' => 'ok']);
            }

            $wasAnswered = $callLog->answered_at !== null;
            $duration = $callLog->answered_at
                ? abs(now()->diffInSeconds($callLog->answered_at))
                : null;

            // For incoming calls, "answered_at" means the IVR/system answered — not a human.
            // Only mark as completed if an admin was actually bridged (status was set to transferred).
            $metadata = $callLog->metadata ?? [];
            $adminWasBridged = $callLog->direction === 'incoming'
                ? ! empty($metadata['joined_admin_ids'])
                : true;

            $status = match (true) {
                $callLog->direction === 'incoming' && ! $adminWasBridged => CallLog::STATUS_MISSED,
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

            // If an incoming caller hung up before any admin was bridged,
            // proactively hang up all still-ringing admin legs so their
            // phones stop ringing immediately.
            if ($callLog->direction === 'incoming') {
                $metadata = $callLog->metadata ?? [];
                $adminCallControlIds = $metadata['admin_call_control_ids'] ?? [];

                if (empty($metadata['joined_admin_ids']) && ! empty($adminCallControlIds)) {
                    Log::channel('telnyx')->info('Caller hung up before admin answered — cleaning up admin ring legs', [
                        'call_control_id' => $callControlId,
                        'admin_legs_to_hangup' => count($adminCallControlIds),
                    ]);

                    foreach ($adminCallControlIds as $adminCcId) {
                        $this->sendCallCommand($adminCcId, 'hangup');
                    }
                }


            }
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle an admin ring leg hanging up (timeout or rejection).
     * If all admin legs failed and none answered, trigger voicemail.
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
            'incoming_call_control_id' => $incomingCallControlId,
        ]);

        // Mark this admin leg as dead so a racing call.speak.ended for the
        // screening prompt (which fires when Telnyx tears down the speak after
        // the admin hangs up) doesn't try to bridge an admin that's already gone.
        Cache::put("telnyx_admin_dead:{$callControlId}", true, now()->addMinutes(10));

        if (! $callLog || ! $incomingCallControlId) {
            Log::channel('telnyx')->warning('Admin ring hangup — missing call log or incoming ID, cannot trigger voicemail', [
                'call_log_id' => $callLogId,
                'has_call_log' => (bool) $callLog,
                'has_incoming_id' => (bool) $incomingCallControlId,
            ]);
            return response()->json(['status' => 'ok']);
        }

        $metadata = $callLog->metadata ?? [];

        // Remove this admin from the pending list
        $adminCallControlIds = $metadata['admin_call_control_ids'] ?? [];
        $metadata['admin_call_control_ids'] = array_values(
            array_filter($adminCallControlIds, fn ($id) => $id !== $callControlId)
        );

        // If this admin had already joined the conference, also remove them from
        // joined_admin_ids so we can detect "caller is now alone in conference".
        $adminUserId = $clientState['admin_user_id'] ?? null;
        $joinedAdminIds = $metadata['joined_admin_ids'] ?? [];
        $hadJoined = $adminUserId && in_array($adminUserId, $joinedAdminIds, true);
        if ($hadJoined) {
            $metadata['joined_admin_ids'] = array_values(
                array_filter($joinedAdminIds, fn ($id) => $id !== $adminUserId)
            );
        }

        Log::channel('telnyx')->info('Admin removed from pending list', [
            'removed_id' => $callControlId,
            'was_in_list' => in_array($callControlId, $adminCallControlIds, true),
            'had_joined_conference' => $hadJoined,
            'remaining_ringing_count' => count($metadata['admin_call_control_ids']),
            'remaining_joined_count' => count($metadata['joined_admin_ids'] ?? []),
            'call_log_id' => $callLogId,
        ]);

        // Clear pending admin state if this was the pending admin
        if (($metadata['pending_admin_call_control_id'] ?? null) === $callControlId) {
            $metadata['pending_admin_call_control_id'] = null;
            $metadata['pending_admin_user_id'] = null;
        }

        $callLog->update(['metadata' => $metadata]);

        // If at least one OTHER admin is still in the conference, the caller still
        // has a connected party — nothing to do.
        if (! empty($metadata['joined_admin_ids'])) {
            return response()->json(['status' => 'ok']);
        }

        // If all admin legs have ended (none ringing AND none still joined) → trigger voicemail
        if (empty($metadata['admin_call_control_ids'])) {
            // If the caller already disconnected, skip voicemail — the call is dead
            $callLog->refresh();
            if (in_array($callLog->status, [CallLog::STATUS_COMPLETED, CallLog::STATUS_MISSED])) {
                Log::channel('telnyx')->info('All admin legs failed but caller already disconnected — skipping voicemail', [
                    'incoming_call_control_id' => $incomingCallControlId,
                    'call_log_id' => $callLogId,
                    'status' => $callLog->status,
                ]);

                return response()->json(['status' => 'ok']);
            }

            // All admins failed — set cache flag (race-proof signal for
            // caller_waiting re-loop safety net) then trigger voicemail
            Cache::put("telnyx_all_admins_failed:{$callLogId}", true, now()->addMinutes(10));

            Log::channel('telnyx')->info('All admin legs failed — triggering voicemail', [
                'incoming_call_control_id' => $incomingCallControlId,
                'call_log_id' => $callLogId,
            ]);
            $this->triggerVoicemail($incomingCallControlId, $callLogId);
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
        $callControlId = $payload['call_control_id'] ?? null;
        $clientStateRaw = $payload['client_state'] ?? null;

        $clientState = $clientStateRaw
            ? json_decode(base64_decode($clientStateRaw), true)
            : null;

        // Track bridge timing and participant information for performance analysis
        $callLog = null;
        if ($clientState['call_log_id'] ?? null) {
            $callLog = CallLog::find($clientState['call_log_id']);
            if ($callLog) {
                $metadata = $callLog->metadata ?? [];
                $metadata['bridged_at'] = now()->toDateTimeString();

                // Calculate setup time: time from call initiation to bridge
                if (! empty($metadata['initiated_at'])) {
                    $initiatedTime = new \DateTime($metadata['initiated_at']);
                    $setupSeconds = now()->diffInSeconds($initiatedTime);
                    $metadata['setup_time_seconds'] = $setupSeconds;

                    Log::channel('telnyx')->info('Call setup complete', [
                        'call_control_id' => $callControlId,
                        'call_log_id' => $callLog->id,
                        'setup_time_seconds' => $setupSeconds,
                        'participant_count' => count($metadata['joined_admin_ids'] ?? []) + 1,
                    ]);
                }

                $callLog->update(['metadata' => $metadata]);
            }
        }

        Log::channel('telnyx')->info('Call bridged', [
            'call_control_id' => $callControlId,
            'call_session_id' => $payload['call_session_id'] ?? null,
            'setup_time_seconds' => $callLog?->metadata['setup_time_seconds'] ?? null,
        ]);

        // Set a cache flag so the playback re-loop knows to stop
        if ($callControlId) {
            Cache::put("telnyx_bridged:{$callControlId}", true, now()->addMinutes(10));
        }

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
     * Handle call.playback.ended - audio playback finished.
     * If the caller is still waiting for an admin, re-start the ringback audio.
     */
    protected function handleCallPlaybackEnded(array $data): JsonResponse
    {
        $payload = $data['payload'] ?? [];
        $callControlId = $payload['call_control_id'] ?? null;
        $clientStateRaw = $payload['client_state'] ?? null;

        $clientState = $clientStateRaw
            ? json_decode(base64_decode($clientStateRaw), true)
            : null;

        $action = $clientState['action'] ?? null;

        // Re-loop ringback audio while caller is waiting for admin, click-to-call target,
        // or admin is waiting for TTS to finish
        if (in_array($action, ['caller_waiting', 'click_to_call_waiting', 'admin_waiting_for_tts'], true)) {
            $callLogId = $clientState['call_log_id'] ?? null;

            // Check cache flag first (fastest, avoids DB race condition)
            $bridgedViaCache = $callControlId && Cache::has("telnyx_bridged:{$callControlId}");

            if (! $bridgedViaCache) {
                // Also check DB as a fallback
                $callLog = $callLogId ? CallLog::find($callLogId) : null;
                $metadata = $callLog ? ($callLog->metadata ?? []) : [];
                $bridgedViaCache = ! empty($metadata['joined_admin_ids'])
                    || ($callLog && $callLog->status === CallLog::STATUS_TRANSFERRED);
            }

            if (! $bridgedViaCache) {
                // Safety net: if all admin legs failed (tracked via atomic cache flag)
                // but voicemail didn't start due to a metadata race condition, trigger it
                // now instead of re-looping ringback (prevents stuck-caller scenario).
                if ($action === 'caller_waiting' && $callLogId && Cache::has("telnyx_all_admins_failed:{$callLogId}")) {
                    Log::channel('telnyx')->warning('Safety net: all admins failed but caller still on ringback — triggering voicemail', [
                        'call_control_id' => $callControlId,
                        'call_log_id' => $callLogId,
                    ]);
                    $this->triggerVoicemail($callControlId, $callLogId);

                    return response()->json(['status' => 'ok']);
                }

                // Preserve all client_state fields from the original command
                // so downstream handlers (like hangup) have full context
                $reloopClientState = $clientState;
                $reloopClientState['action'] = $action;
                $reloopClientState['call_log_id'] = $callLogId;

                $holdAudioUrl = config('services.telnyx.hold_audio_url')
                    ?: $this->telnyxBaseUrl() . '/telnyx-audio/ringback.wav';
                $this->sendCallCommand($callControlId, 'playback_start', [
                    'audio_url' => $holdAudioUrl,
                    'client_state' => base64_encode(json_encode($reloopClientState)),
                ]);
            } else {
                // Bridged/voicemail active — check if a voicemail gather is
                // waiting for this playback to finish before it starts.
                $pendingVoicemail = Cache::pull("telnyx_pending_voicemail:{$callControlId}");
                if ($pendingVoicemail) {
                    Log::channel('telnyx')->info('Ringback stopped — starting voicemail IVR gather', [
                        'call_control_id' => $callControlId,
                        'call_log_id' => $pendingVoicemail['call_log_id'] ?? null,
                    ]);
                    $this->startVoicemailGather($callControlId, $pendingVoicemail);
                }
            }

            return response()->json(['status' => 'ok']);
        }

        Log::channel('telnyx')->info('Playback ended (non-ringback)', [
            'call_control_id' => $callControlId,
            'action' => $action,
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

        if ($action === 'admin_screen_done') {
            return $this->handleAdminScreenDone($callControlId, $clientState ?? []);
        }

        if ($action === 'welcome_done') {
            $callLogId = $clientState['call_log_id'] ?? null;
            $originalCaller = $clientState['original_caller'] ?? null;
            $callLog = $callLogId ? CallLog::find($callLogId) : null;

            // Start recording now that welcome+disclosure TTS has finished — keeps
            // the system prompt out of the audio file while preserving the
            // recording_disclosure_played audit trail set when TTS was queued.
            if ($callLog && config('call_recording.mode') === 'auto' && empty($callLog->recording_started_at)) {
                $this->startCallRecording($callControlId, $callLog);
            }

            // Mark TTS as complete via cache (race-safe — can't be clobbered by metadata writes)
            if ($callLogId) {
                Cache::put("telnyx_tts_complete:{$callLogId}", true, now()->addMinutes(10));
            }

            // Mark TTS as complete in metadata — use atomic JSON path update
            // so we don't overwrite admin_call_control_ids modified concurrently
            // by handleAdminRingHangup (prevents race-condition metadata clobber)
            if ($callLogId) {
                CallLog::where('id', $callLogId)->update([
                    'metadata->tts_complete' => true,
                ]);
            }

            // Check if voicemail or bridge already in progress (AMD machine detected
            // and triggerVoicemail ran, or another handler already bridged)
            if (Cache::has("telnyx_bridged:{$callControlId}")) {
                Log::channel('telnyx')->info('TTS completed but call already handled (voicemail/bridge in progress)', [
                    'call_control_id' => $callControlId,
                    'call_log_id' => $callLogId,
                ]);

                // If voicemail was triggered while welcome TTS was still playing,
                // playback.ended will never fire (ringback was never started).
                // Pick up the pending voicemail context and start the IVR now.
                $pendingVoicemail = Cache::pull("telnyx_pending_voicemail:{$callControlId}");
                if ($pendingVoicemail) {
                    Log::channel('telnyx')->info('Pending voicemail found after TTS end — starting IVR gather directly', [
                        'call_control_id' => $callControlId,
                        'call_log_id' => $callLogId,
                    ]);
                    $this->startVoicemailGather($callControlId, $pendingVoicemail);
                }
            } elseif ($callLog && in_array($callLog->fresh()?->status, [CallLog::STATUS_COMPLETED, CallLog::STATUS_MISSED])) {
                Log::channel('telnyx')->info('TTS completed but caller already disconnected', [
                    'call_control_id' => $callControlId,
                    'call_log_id' => $callLogId,
                ]);
            } else {
                // Check if an admin already answered during TTS and is waiting to be bridged
                $freshCallLog = $callLog ? $callLog->fresh() : null;
                $freshMetadata = $freshCallLog ? ($freshCallLog->metadata ?? []) : ($callLog->metadata ?? []);
                $pendingAdminCcId = $freshMetadata['pending_admin_call_control_id'] ?? null;
                $pendingAdminUserId = $freshMetadata['pending_admin_user_id'] ?? null;

                if ($pendingAdminCcId) {
                    // Admin answered during TTS — bridge now
                    Log::channel('telnyx')->info('TTS completed — admin already waiting, bridging now', [
                        'call_control_id' => $callControlId,
                        'call_log_id' => $callLogId,
                        'pending_admin_cc_id' => $pendingAdminCcId,
                    ]);

                    // Clear pending state
                    $freshMetadata['pending_admin_call_control_id'] = null;
                    $freshMetadata['pending_admin_user_id'] = null;
                    ($freshCallLog ?? $callLog)->update(['metadata' => $freshMetadata]);

                    $this->bridgeAdminWithCaller($pendingAdminCcId, $callLogId, $callControlId, $pendingAdminUserId);
                } else {
                    // No admin answered yet — play ringback while caller waits
                    Log::channel('telnyx')->info('TTS completed — no admin answered yet, playing ringback', [
                        'call_control_id' => $callControlId,
                        'call_log_id' => $callLogId,
                    ]);

                    $holdAudioUrl = config('services.telnyx.hold_audio_url')
                        ?: $this->telnyxBaseUrl() . '/telnyx-audio/ringback.wav';
                    $this->sendCallCommand($callControlId, 'playback_start', [
                        'audio_url' => $holdAudioUrl,
                        'client_state' => base64_encode(json_encode([
                            'action' => 'caller_waiting',
                            'call_log_id' => $callLogId,
                        ])),
                    ]);
                }
            }
        } elseif ($action === 'voicemail_prompt_done') {
            // Voicemail prompt finished → start recording
            // (Legacy path - newer path uses voicemail_intro_done)
            $callLogId = $clientState['call_log_id'] ?? null;

            Log::channel('telnyx')->info('Voicemail prompt done — starting recording', [
                'call_control_id' => $callControlId,
            ]);

            $this->sendCallCommand($callControlId, 'record_start', [
                'format' => (string) config('call_recording.format', 'wav'),
                'channels' => 'dual', // Voicemail always dual-channel
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

            // Server-side backstop for the after-hours rule: the menu no
            // longer OFFERS press 1 after hours, but a call that straddles
            // the cutoff can still arrive here — and phones must not ring
            // outside business hours. Straight to the voicemail greeting.
            $retryVendor = Vendor::find(1);
            if ($retryVendor && ! $retryVendor->isWithinBusinessHours()) {
                Log::channel('telnyx')->info('IVR retry pressed after hours — routing to voicemail instead of ringing admins', [
                    'call_control_id' => $callControlId,
                    'call_log_id' => $callLogId,
                ]);

                $greetingTemplate = data_get($retryVendor->options ?? [], 'voicemail_greeting')
                    ?: \App\Livewire\Vendors\VendorOptions::DEFAULT_VOICEMAIL_GREETING;
                $this->sendCallCommand($callControlId, 'speak', [
                    'payload' => $this->renderPrompt($greetingTemplate, [
                        '{name}' => '',
                        '{company}' => data_get($retryVendor->options ?? [], 'short_name') ?: ($retryVendor->business_name ?? 'us'),
                        '{greeting}' => $this->buildTimeGreeting(),
                    ]),
                    ...$this->ttsVoiceParams(),
                    'client_state' => base64_encode(json_encode([
                        'action' => 'voicemail_prompt_done',
                        'call_log_id' => $callLogId,
                    ])),
                ]);

                return response()->json(['status' => 'ok']);
            }

            Log::channel('telnyx')->info('IVR retry — playing ringback and re-dialing admins', [
                'call_control_id' => $callControlId,
                'call_log_id' => $callLogId,
            ]);

            // Play ringback while waiting for admin to answer
            $holdAudioUrl = config('services.telnyx.hold_audio_url')
                ?: $this->telnyxBaseUrl() . '/telnyx-audio/ringback.wav';
            $this->sendCallCommand($callControlId, 'playback_start', [
                'audio_url' => $holdAudioUrl,
                'client_state' => base64_encode(json_encode([
                    'action' => 'caller_waiting',
                    'call_log_id' => $callLogId,
                ])),
            ]);

            // Reset metadata for retry
            $callLog = $callLogId ? CallLog::find($callLogId) : null;
            if ($callLog) {
                $metadata = $callLog->metadata ?? [];
                $metadata['admin_call_control_ids'] = [];
                $metadata['joined_admin_ids'] = [];
                $metadata['tts_complete'] = true;
                $metadata['all_admins_failed'] = false;
                $metadata['pending_admin_call_control_id'] = null;
                $metadata['pending_admin_user_id'] = null;
                $metadata['pending_admin_amd_confirmed'] = null;
                $callLog->update(['metadata' => $metadata]);
            }

            // Clear cache flags from previous attempt
            if ($callLogId) {
                Cache::forget("telnyx_all_admins_failed:{$callLogId}");
                Cache::forget("telnyx_bridged:{$callControlId}");
            }

            $this->dialAdmins($callControlId, $callLogId, $originalCaller);
        } elseif ($action === 'ivr_sms_confirmation') {
            // Press 2: SMS confirmation message done → hang up
            $callLogId = $clientState['call_log_id'] ?? null;

            Log::channel('telnyx')->info('IVR SMS confirmation done — hanging up', [
                'call_control_id' => $callControlId,
                'call_log_id' => $callLogId,
            ]);

            $this->sendCallCommand($callControlId, 'hangup');
        } elseif ($action === 'click_to_call_target_intro_done') {
            // Target heard the outbound disclosure → bridge with the user who initiated the call
            // (or if already in conference, this just logs completion)
            $callLogId = $clientState['call_log_id'] ?? null;
            $userCallControlId = $clientState['user_call_control_id'] ?? null;
            $callLog = $callLogId ? CallLog::find($callLogId) : null;

            // The disclosure outlives the call: the user can hang up while it
            // is still playing (production call 1945 — user gave up at 24s,
            // this fired 3s later). Bridging a dead leg just burns a failing
            // API call and logs a scary "intro bridge failed"; drop the target
            // leg instead.
            if ($callLog && in_array($callLog->status, [
                CallLog::STATUS_COMPLETED,
                CallLog::STATUS_MISSED,
                CallLog::STATUS_FAILED,
            ], true)) {
                Log::channel('telnyx')->info('Click-to-call: intro finished after the call ended — hanging up target leg', [
                    'target_call_control_id' => $callControlId,
                    'user_call_control_id' => $userCallControlId,
                    'call_log_id' => $callLogId,
                    'status' => $callLog->status,
                ]);

                $this->sendCallCommand($callControlId, 'hangup');

                return response()->json(['status' => 'ok']);
            }

            // If call is already TRANSFERRED (via conference join), skip bridge attempt
            if ($callLog && $callLog->status === CallLog::STATUS_TRANSFERRED) {
                Log::channel('telnyx')->info('Click-to-call: target intro done — call already bridged via conference', [
                    'target_call_control_id' => $callControlId,
                    'user_call_control_id' => $userCallControlId,
                    'call_log_id' => $callLogId,
                ]);
                return response()->json(['status' => 'ok']);
            }

            // Fall back to bridge for non-conference scenarios
            Log::channel('telnyx')->info('Click-to-call: target intro done — bridging with user', [
                'target_call_control_id' => $callControlId,
                'user_call_control_id' => $userCallControlId,
                'call_log_id' => $callLogId,
            ]);

            if ($userCallControlId) {
                $bridgeSuccess = $this->bridgeCalls($callControlId, $userCallControlId);

                if ($bridgeSuccess) {
                    $callLog?->update(['status' => CallLog::STATUS_TRANSFERRED]);
                } else {
                    Log::channel('telnyx')->warning('Click-to-call intro bridge failed — cleaning up', [
                        'target_call_control_id' => $callControlId,
                        'user_call_control_id' => $userCallControlId,
                        'call_log_id' => $callLogId,
                    ]);
                    $this->sendCallCommand($callControlId, 'hangup');
                    $this->sendCallCommand($userCallControlId, 'hangup');
                }
            } else {
                Log::channel('telnyx')->error('No user call control ID for bridge', [
                    'call_log_id' => $callLogId,
                ]);
                $this->sendCallCommand($callControlId, 'hangup');
            }
        } elseif ($action === 'conference_invite_intro_done') {
            // Invited participant intro TTS finished → join the existing conference
            $callLogId = $clientState['call_log_id'] ?? null;
            $callLog = $callLogId ? CallLog::find($callLogId) : null;
            $conferenceId = $callLog ? (($callLog->metadata ?? [])['conference_id'] ?? null) : null;

            if (! $callLog || ! $conferenceId) {
                Log::channel('telnyx')->warning('Conference invite intro done but no conference id available — hanging up', [
                    'call_control_id' => $callControlId,
                    'call_log_id' => $callLogId,
                ]);
                $this->sendCallCommand($callControlId, 'hangup');
            } elseif (in_array($callLog->status, [CallLog::STATUS_COMPLETED, CallLog::STATUS_MISSED, CallLog::STATUS_FAILED])) {
                Log::channel('telnyx')->info('Conference invite intro done but call ended — hanging up', [
                    'call_control_id' => $callControlId,
                    'call_log_id' => $callLogId,
                    'status' => $callLog->status,
                ]);
                $this->sendCallCommand($callControlId, 'hangup');
            } else {
                $this->joinAdminToConference($callControlId, $callLogId, null, $conferenceId);
            }
        } elseif ($action === 'voicemail_intro_done') {
            // Voicemail intro TTS ("Please leave a message after the tone") finished.
            // Now join the target (voicemail machine leg) into the conference and start recording.
            $callLogId = $clientState['call_log_id'] ?? null;
            $conferenceId = $clientState['conference_id'] ?? null;
            $targetCallControlId = $clientState['target_call_control_id'] ?? null;

            Log::channel('telnyx')->info('Voicemail intro done — joining target to conference and starting recording', [
                'user_call_control_id' => $callControlId,
                'target_call_control_id' => $targetCallControlId,
                'conference_id' => $conferenceId,
                'call_log_id' => $callLogId,
            ]);

            if ($conferenceId && $targetCallControlId) {
                // Stop any playback on the target before joining (prevents media binding)
                $this->sendCallCommand($targetCallControlId, 'playback_stop');

                // Join the target leg into the conference so user can leave a voicemail message
                if ($this->joinConference($conferenceId, $targetCallControlId)) {
                    // Start recording the conference (both user + target) for voicemail
                    // Use configured quality settings; voicemail always uses dual-channel for best quality
                    $this->sendCallCommand($callControlId, 'record_start', [
                        'format' => (string) config('call_recording.format', 'wav'),
                        'channels' => 'dual', // Voicemail always dual-channel for best quality
                        'play_beep' => false, // No beep on voicemail recording
                        'max_length' => 180, // 3 minutes max voicemail
                        'timeout_secs' => 5,  // Stop after 5s silence
                        'client_state' => base64_encode(json_encode([
                            'action' => 'voicemail_recording',
                            'call_log_id' => $callLogId,
                        ])),
                    ]);

                    // Update call log to reflect transferred status
                    $callLog = $callLogId ? CallLog::find($callLogId) : null;
                    $callLog?->update(['status' => CallLog::STATUS_TRANSFERRED]);
                } else {
                    Log::channel('telnyx')->error('Voicemail: failed to join target to conference', [
                        'target_call_control_id' => $targetCallControlId,
                        'conference_id' => $conferenceId,
                        'call_log_id' => $callLogId,
                    ]);
                    // Hang up both legs cleanly
                    $this->sendCallCommand($callControlId, 'hangup');
                    $this->sendCallCommand($targetCallControlId, 'hangup');
                }
            } else {
                Log::channel('telnyx')->error('Voicemail intro done but missing conference or target', [
                    'user_call_control_id' => $callControlId,
                    'target_call_control_id' => $targetCallControlId,
                    'conference_id' => $conferenceId,
                    'call_log_id' => $callLogId,
                ]);
                $this->sendCallCommand($callControlId, 'hangup');
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

        $preferredFormat = strtolower((string) config('call_recording.format', 'wav'));
        $fallbackFormat = $preferredFormat === 'wav' ? 'mp3' : 'wav';
        $recordingUrl = $recordingUrls[$preferredFormat] ?? $recordingUrls[$fallbackFormat] ?? null;

        if ($callLog && $recordingUrl) {
            $durationSeconds = (int) ($callLog->duration_seconds ?? 0);
            if ($durationSeconds <= 0) {
                Log::channel('telnyx')->info('Skipping recording save for zero-duration call', [
                    'call_log_id' => $callLog->id,
                    'call_control_id' => $callControlId,
                    'duration_seconds' => $callLog->duration_seconds,
                ]);

                $callLog->update([
                    'recording_url' => null,
                    'recording_disk' => null,
                    'recording_path' => null,
                    'recording_telnyx_id' => null,
                    'has_voicemail' => false,
                ]);

                return response()->json(['status' => 'ok']);
            }

            $isVoicemail = ($clientState['action'] ?? null) === 'voicemail_recording';

            // Auto-recording starts at the welcome and keeps rolling through the
            // ringback while admins are dialed. If nobody ever bridged and the
            // caller hung up without leaving a voicemail (status missed/failed),
            // the audio is just TTS + hold music with no conversation — keeping
            // it would pollute the call list and trigger pointless transcription
            // and AI analysis. Only persist a recording when it captured a real
            // two-way conversation or a genuine voicemail.
            $wasConnected = in_array($callLog->status, [CallLog::STATUS_TRANSFERRED, CallLog::STATUS_COMPLETED], true);
            if (! $isVoicemail && ! $wasConnected) {
                Log::channel('telnyx')->info('Discarding recording for call with no conversation or voicemail', [
                    'call_log_id' => $callLog->id,
                    'call_control_id' => $callControlId,
                    'status' => $callLog->status,
                    'has_voicemail' => $callLog->has_voicemail,
                ]);

                $callLog->update([
                    'recording_url' => null,
                    'recording_disk' => null,
                    'recording_path' => null,
                    'recording_telnyx_id' => null,
                    'has_voicemail' => false,
                ]);

                return response()->json(['status' => 'ok']);
            }

            $callLog->update([
                'recording_url' => $recordingUrl,
                'has_voicemail' => $isVoicemail,
            ]);

            // Download the recording locally before the Telnyx signed URL expires (~10 min)
            StoreCallRecording::dispatch($callLog->id);

            // Notify all admin recipients (in-app + browser push) that a new voicemail was left.
            if ($isVoicemail) {
                SendVoicemailBrowserNotifications::dispatch($callLog->id);
            }
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
     * If a machine/voicemail is detected on an admin ring leg, hang it up.
     * If a human is detected, proceed with bridging the admin to the caller.
     */
    protected function handleAmdEnded(array $data): JsonResponse
    {
        $payload = $data['payload'] ?? [];
        $callControlId = $payload['call_control_id'] ?? null;
        $result = $payload['result'] ?? null;
        $clientStateRaw = $payload['client_state'] ?? null;

        $clientState = $clientStateRaw
            ? json_decode(base64_decode($clientStateRaw), true)
            : null;

        $action = $clientState['action'] ?? null;

        Log::channel('telnyx')->info('AMD ended', [
            'call_control_id' => $callControlId,
            'result' => $result,
            'action' => $action,
        ]);

        $isMachine = in_array($result, ['machine', 'machine_start', 'machine_end_beep', 'machine_end_silence', 'machine_end_other'], true);
        $isHuman = in_array($result, ['human', 'human_residence', 'human_business', 'not_sure'], true);

        // ── Admin ring leg: AMD no longer used (replaced by DTMF screening) ──
        // AMD is not requested on admin dials (DTMF "press any key" screening
        // is used instead), so an AMD event here is unexpected — ignore it.
        if ($action === 'admin_ring') {
            Log::channel('telnyx')->info('AMD event on admin_ring leg ignored (DTMF screening active)', [
                'call_control_id' => $callControlId,
                'result' => $result,
            ]);
            return response()->json(['status' => 'ok']);
        }

        // ── Click-to-call target: AMD result ──
        // and notify the user that the target didn't answer.
        // But skip if the call is already bridged — AMD can fire late after a human answered.
        if ($action === 'click_to_call_target_ring' && $isMachine) {
            $callLogId = $clientState['call_log_id'] ?? null;
            $userCallControlId = $clientState['user_call_control_id'] ?? null;

            // Check if call was already bridged (human answered, AMD fired late with wrong result)
            $callLog = $callLogId ? CallLog::find($callLogId) : null;
            if ($callLog && $callLog->status === CallLog::STATUS_TRANSFERRED) {
                Log::channel('telnyx')->info('AMD detected machine on click-to-call target but call already bridged — ignoring', [
                    'call_control_id' => $callControlId,
                    'result' => $result,
                    'call_log_id' => $callLogId,
                ]);

                return response()->json(['status' => 'ok']);
            }

            // Prevent race condition with handleClickToCallTargetAnswered — only one handler should bridge
            $bridgeLockKey = "telnyx_bridge_lock:{$callLogId}";
            if (! Cache::add($bridgeLockKey, 'amd_machine', 60)) {
                Log::channel('telnyx')->info('AMD: bridge already initiated by target answered handler — skipping', [
                    'call_control_id' => $callControlId,
                    'result' => $result,
                    'call_log_id' => $callLogId,
                ]);
                return response()->json(['status' => 'ok']);
            }

            Log::channel('telnyx')->info('AMD detected voicemail on click-to-call target — creating conference for voicemail message', [
                'call_control_id' => $callControlId,
                'result' => $result,
                'call_log_id' => $callLogId,
            ]);

            // Use conference instead of legacy bridge so voicemail leaves a proper recorded message.
            // Seed the conference with the user leg so they're present first; then join the voicemail target.
            if ($userCallControlId && $callLogId) {
                Cache::put("telnyx_bridged:{$userCallControlId}", true, now()->addMinutes(10));
                Cache::put("telnyx_bridged:{$callControlId}", true, now()->addMinutes(10));

                $conferenceName = "voicemail_{$callLogId}_" . substr(md5($userCallControlId), 0, 8);
                $conferenceId = $this->createConference($conferenceName, $userCallControlId);

                if ($conferenceId) {
                    // Play "leave a message" prompt to the user before target joins
                    $this->sendCallCommand($userCallControlId, 'speak', [
                        'payload' => 'Please leave your message after the tone.',
                        ...$this->ttsVoiceParams(),
                        'client_state' => base64_encode(json_encode([
                            'action' => 'voicemail_intro_done',
                            'call_log_id' => $callLogId,
                            'conference_id' => $conferenceId,
                            'target_call_control_id' => $callControlId,
                        ])),
                    ]);

                    // Schedule auto-hangup of this voicemail call after 3 minutes to prevent stranding
                    Cache::put("telnyx_voicemail_timeout:{$callLogId}", true, now()->addMinutes(3));

                    $callLog?->update(['status' => CallLog::STATUS_TRANSFERRED]);
                } else {
                    Log::channel('telnyx')->error('AMD voicemail: failed to create conference', [
                        'call_log_id' => $callLogId,
                    ]);
                    $this->sendCallCommand($callControlId, 'hangup');
                    $this->sendCallCommand($userCallControlId, 'hangup');
                }
            } else {
                $this->sendCallCommand($callControlId, 'hangup');
            }
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle DTMF digits pressed during a call.
     *
     * In-conference: a joined recipient can press 9 to dial all other call
     * recipients who haven't joined yet, prompting them to join the conference.
     */
    protected function handleCallDtmfReceived(array $data): JsonResponse
    {
        $payload = $data['payload'] ?? [];
        $callControlId = $payload['call_control_id'] ?? null;
        $digit = $payload['digit'] ?? null;

        Log::channel('telnyx')->info('DTMF received', [
            'call_control_id' => $callControlId,
            'digit' => $digit,
        ]);

        if ($digit !== '9' || ! $callControlId) {
            return response()->json(['status' => 'ok']);
        }

        // Only act if this leg is part of an active conference
        if (! Cache::has("telnyx_bridged:{$callControlId}")) {
            return response()->json(['status' => 'ok']);
        }

        // Find the call log for this leg via metadata->admin_call_control_ids or
        // the bridged caller leg.
        $callLog = CallLog::query()
            ->whereJsonContains('metadata->admin_call_control_ids', $callControlId)
            ->latest('id')
            ->first();

        if (! $callLog) {
            $callLog = CallLog::query()
                ->where('call_control_id', $callControlId)
                ->latest('id')
                ->first();
        }

        if (! $callLog) {
            Log::channel('telnyx')->info('DTMF 9: no matching call log found', [
                'call_control_id' => $callControlId,
            ]);
            return response()->json(['status' => 'ok']);
        }

        $metadata = $callLog->metadata ?? [];
        $conferenceId = $metadata['conference_id'] ?? null;
        if (! $conferenceId) {
            return response()->json(['status' => 'ok']);
        }

        // Throttle: don't allow re-invites more than once per 10s
        $throttleKey = "telnyx_invite_throttle:{$callLog->id}";
        if (! Cache::add($throttleKey, true, 10)) {
            Log::channel('telnyx')->info('DTMF 9: invite throttled', [
                'call_log_id' => $callLog->id,
            ]);
            return response()->json(['status' => 'ok']);
        }

        $this->inviteRemainingRecipients($callLog);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Dial all configured call_recipients who have NOT yet joined the
     * conference for this call log, so they can join via the late-screen flow.
     */
    protected function inviteRemainingRecipients(CallLog $callLog): void
    {
        $metadata = $callLog->metadata ?? [];
        $joinedAdminIds = $metadata['joined_admin_ids'] ?? [];

        $vendor = Vendor::find(1);
        $vendorOptions = $vendor ? (array) $vendor->options : [];
        $recipientUserIds = (array) data_get($vendorOptions, 'call_recipients', []);

        $remainingIds = array_values(array_diff(
            array_map('intval', $recipientUserIds),
            array_map('intval', $joinedAdminIds)
        ));

        if (empty($remainingIds)) {
            Log::channel('telnyx')->info('DTMF 9: no remaining recipients to invite', [
                'call_log_id' => $callLog->id,
            ]);
            return;
        }

        $remainingUsers = User::whereIn('id', $remainingIds)
            ->whereNotNull('cell_phone')
            ->where('cell_phone', '!=', '')
            ->get();

        $apiKey = config('services.telnyx.api_key');
        $connectionId = config('services.telnyx.connection_id');
        $telnyxFrom = config('services.telnyx.from');
        // NOTE: Telnyx rejects outbound calls (error D51 "Unverified origination
        // number") when `from` is a non-Telnyx number we don't own. So we must
        // dial from our Telnyx number; the recipient's caller ID will show our
        // business number. We surface the actual caller via `from_display_name`
        // (CNAM) — some mobile carriers display it, others ignore it.
        $timeout = 15;

        $newCallControlIds = $metadata['admin_call_control_ids'] ?? [];
        $dialedPhones = [];

        foreach ($remainingUsers as $user) {
            $phone = $this->normalizeDialPhone($user->cell_phone);
            if (! $phone || GroupSmsService::isOurNumber($phone) || isset($dialedPhones[$phone])) {
                continue;
            }
            $dialedPhones[$phone] = true;

            try {
                $response = $this->telnyxHttp(0)
                    ->post('https://api.telnyx.com/v2/calls', [
                        'connection_id' => $connectionId,
                        'to' => $phone,
                        'from' => $telnyxFrom,
                        'from_display_name' => $callLog->caller_name ?: 'Conference Invite',
                        'timeout_secs' => $timeout,
                        'preferred_codecs' => config('services.telnyx.preferred_codecs'),
                        'client_state' => base64_encode(json_encode([
                            'action' => 'admin_ring',
                            'call_log_id' => $callLog->id,
                            'incoming_call_control_id' => $callLog->call_control_id,
                            'admin_user_id' => $user->id,
                        ])),
                        'webhook_url' => $this->telnyxBaseUrl() . '/webhooks/telnyx/voice',
                    ]);

                if ($response->successful()) {
                    $newId = $response->json('data.call_control_id');
                    if ($newId) {
                        $newCallControlIds[] = $newId;
                    }
                    Log::channel('telnyx')->info('DTMF 9: invited recipient to conference', [
                        'call_log_id' => $callLog->id,
                        'admin_user_id' => $user->id,
                        'phone' => $phone,
                    ]);
                } else {
                    Log::channel('telnyx')->error('DTMF 9: failed to invite recipient', [
                        'admin_user_id' => $user->id,
                        'error' => $response->json(),
                    ]);
                }
            } catch (\Exception $e) {
                Log::channel('telnyx')->error('DTMF 9: exception inviting recipient', [
                    'admin_user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $metadata['admin_call_control_ids'] = array_values(array_unique($newCallControlIds));
        $callLog->update(['metadata' => $metadata]);
    }

    /**
     * Handle premium AMD greeting ended event.
     * Logs whether a beep was detected (useful for diagnostics).
     */
    protected function handleAmdGreetingEnded(array $data): JsonResponse
    {
        $payload = $data['payload'] ?? [];

        Log::channel('telnyx')->info('AMD greeting ended (premium)', [
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
            $hour >= 5 && $hour < 12 => 'Good morning',
            $hour >= 12 && $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
    }

    /**
     * Dial all selected admin call recipients simultaneously.
     * Each admin gets their own outbound call leg; first to answer gets bridged with the caller.
     */
    protected function dialAdmins(string $incomingCallControlId, ?int $callLogId, ?string $originalCaller): void
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
        // NOTE: Telnyx rejects outbound calls (error D51 "Unverified origination
        // number") when `from` is a non-Telnyx number we don't own. We must dial
        // from our Telnyx number; recipient's native caller ID shows that. The
        // actual caller is surfaced via `from_display_name` (CNAM).
        // Use a short timeout so admin phones stop ringing well before
        // carrier voicemail picks up (~25s). This ensures callers always
        // reach our Telnyx Voicemail Menu instead of personal voicemail.
        $timeout = 15;
        $adminCallControlIds = [];
        $dialedPhones = [];

        foreach ($adminUsers as $adminUser) {
            $phone = $this->normalizeDialPhone($adminUser->cell_phone);
            if (! $phone || GroupSmsService::isOurNumber($phone) || isset($dialedPhones[$phone])) {
                continue;
            }
            $dialedPhones[$phone] = true;

            try {
                $response = $this->telnyxHttp(0)
                    ->post('https://api.telnyx.com/v2/calls', [
                        'connection_id' => $connectionId,
                        'to' => $phone,
                        'from' => $telnyxFrom,
                        'from_display_name' => $callLog?->caller_name ?: ($originalCaller ?? 'Incoming Call'),
                        'timeout_secs' => $timeout,
                        'preferred_codecs' => config('services.telnyx.preferred_codecs'),
                        // No answering_machine_detection here — deliberately.
                        // handleAmdEnded discards every AMD result for
                        // admin_ring legs (screening replaced it), so
                        // requesting it billed premium detection on every admin
                        // leg of every inbound call for nothing. The companion
                        // 'machine_detection' key wasn't even a real Dial
                        // parameter, so Telnyx silently ignored it.
                        'client_state' => base64_encode(json_encode([
                            'action' => 'admin_ring',
                            'call_log_id' => $callLogId,
                            'incoming_call_control_id' => $incomingCallControlId,
                            'admin_user_id' => $adminUser->id,
                        ])),
                        'webhook_url' => $this->telnyxBaseUrl() . '/webhooks/telnyx/voice',
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

        // Store admin call control IDs and metadata in the call log for tracking
        if ($callLog) {
            $metadata = $callLog->metadata ?? [];
            $metadata['admin_call_control_ids'] = $adminCallControlIds;
            $metadata['admin_dial_initiated_at'] = now()->toDateTimeString();
            $metadata['admin_dial_count'] = count($adminCallControlIds);
            $callLog->update(['metadata' => $metadata]);
        }

        // If no admins were successfully dialed, trigger voicemail
        if (empty($adminCallControlIds)) {
            Log::channel('telnyx')->error('Failed to dial any admins — triggering voicemail');
            $this->triggerVoicemail($incomingCallControlId, $callLogId);
        }
    }

    /**
     * Spam-flagged call answered: play the spam screening prompt as a
     * voicemail-style IVR. Press 2 → send text, stay on line → voicemail.
     * This gives misidentified callers a way to reach us instead of just
     * dropping the call.
     */
    protected function handleSpamVoicemailAnswered(string $callControlId, array $clientState): JsonResponse
    {
        $callLogId = $clientState['call_log_id'] ?? null;
        $originalCaller = $clientState['original_caller'] ?? null;

        $vendor = Vendor::find(1);
        $shortName = data_get($vendor?->options ?? [], 'short_name')
            ?: ($vendor?->business_name ?? 'us');

        $template = data_get($vendor?->options ?? [], 'spam_message')
            ?: \App\Livewire\Vendors\VendorOptions::DEFAULT_SPAM;

        $ivrPrompt = $this->renderPrompt($template, [
            '{name}' => '',
            '{company}' => $shortName,
            '{greeting}' => $this->buildTimeGreeting(),
        ]);

        Log::channel('telnyx')->info('Spam voicemail: playing screening prompt + IVR', [
            'call_control_id' => $callControlId,
            'call_log_id' => $callLogId,
        ]);

        // Mark as bridged so other handlers don't try to re-route this call.
        Cache::put("telnyx_bridged:{$callControlId}", true, now()->addMinutes(10));

        // Reuse the existing voicemail gather — Press 2 → SMS, stay → voicemail.
        $this->startVoicemailGather($callControlId, [
            'call_log_id' => $callLogId,
            'ivr_prompt' => $ivrPrompt,
            'valid_digits' => '2',
            'is_known_caller' => false,
            'original_caller' => $originalCaller,
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Trigger voicemail IVR: play interactive menu with DTMF options.
     * Press 1 = re-dial, Press 2 = send text, Stay on line = voicemail.
     */
    protected function triggerVoicemail(string $callControlId, ?int $callLogId, ?string $prefixMessage = null): void
    {
        // Idempotency: only ever trigger voicemail once per call.
        // Multiple paths (admin hangup, all-admins-failed safety net, admin
        // screen abort) can all race to call this. The duplicate gather then
        // makes the caller hear the menu twice.
        $idempotencyKey = $callLogId
            ? "telnyx_voicemail_triggered:{$callLogId}"
            : "telnyx_voicemail_triggered_cc:{$callControlId}";
        if (! Cache::add($idempotencyKey, true, now()->addMinutes(10))) {
            Log::channel('telnyx')->info('Voicemail already triggered for this call — skipping duplicate', [
                'call_control_id' => $callControlId,
                'call_log_id' => $callLogId,
            ]);
            return;
        }

        // Check if caller already disconnected before starting voicemail flow
        if ($callLogId) {
            $freshLog = CallLog::find($callLogId);
            if ($freshLog && in_array($freshLog->status, [CallLog::STATUS_COMPLETED, CallLog::STATUS_MISSED])) {
                Log::channel('telnyx')->info('Caller already disconnected — skipping voicemail', [
                    'call_control_id' => $callControlId,
                    'call_log_id' => $callLogId,
                ]);
                return;
            }
        }

        // Check if voicemail is enabled
        $vendor = Vendor::find(1);
        $voicemailEnabled = (bool) data_get($vendor?->options ?? [], 'voicemail_enabled', true);

        if (! $voicemailEnabled) {
            // Set bridged flag to stop ringback re-loop, then hang up
            Cache::put("telnyx_bridged:{$callControlId}", true, now()->addMinutes(10));

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

        // Known callers get full IVR (press 1 re-dial + press 2 SMS), unknown
        // get press 2 + voicemail only. After hours NOBODY gets press 1 — the
        // whole point of the after-hours route is that phones don't ring, and
        // offering "press 1 to connect" would ring them anyway.
        $afterHours = $vendor && ! $vendor->isWithinBusinessHours();

        if ($isKnownCaller && ! $afterHours) {
            $ivrTemplate = data_get($vendor?->options ?? [], 'voicemail_message')
                ?: \App\Livewire\Vendors\VendorOptions::DEFAULT_VOICEMAIL;
            $validDigits = '12';
        } else {
            $ivrTemplate = data_get($vendor?->options ?? [], 'voicemail_message_unknown')
                ?: \App\Livewire\Vendors\VendorOptions::DEFAULT_VOICEMAIL_UNKNOWN;
            $validDigits = '2';
        }

        // Optional prefix (e.g. the after-hours greeting) spoken before the IVR
        // menu. Prepended to the raw template *before* rendering so the whole
        // thing is wrapped in a single SSML envelope and plays seamlessly,
        // immediately followed by the menu, without a second TTS round-trip.
        $template = $ivrTemplate;
        if ($prefixMessage !== null && trim($prefixMessage) !== '') {
            $prefix = trim($prefixMessage);
            if (! preg_match('/[.!?]$/', $prefix)) {
                $prefix .= '.';
            }
            $template = $prefix . ' ' . $ivrTemplate;
        }

        $ivrPrompt = $this->renderPrompt($template, [
            '{name}' => $callerName ?? '',
            '{company}' => $shortName,
            '{greeting}' => $this->buildTimeGreeting(),
        ]);

        $voicemailContext = [
            'call_log_id' => $callLogId,
            'ivr_prompt' => $ivrPrompt,
            'valid_digits' => $validDigits,
            'is_known_caller' => $isKnownCaller,
            'original_caller' => $callLog?->from_number,
        ];

        // Set the bridged flag to stop ringback re-loop and signal to
        // speak.ended/playback.ended handlers that the call is handled.
        Cache::put("telnyx_bridged:{$callControlId}", true, now()->addMinutes(10));

        // Stop any active ringback playback FIRST — Telnyx otherwise queues the
        // gather_using_speak behind the current audio loop iteration which can
        // delay the menu by 20+ seconds (we've seen 22s in production logs).
        // Best-effort; ignore errors if no playback is active.
        $this->sendCallCommand($callControlId, 'playback_stop');

        // Start the IVR — gather_using_speak will play the menu and collect a digit.
        $this->startVoicemailGather($callControlId, $voicemailContext);
    }

    /**
     * Build the IVR prompt text for a given call log.
     */
    protected function buildIvrPrompt(?int $callLogId): string
    {
        $vendor = Vendor::find(1);
        $shortName = data_get($vendor?->options ?? [], 'short_name') ?: ($vendor?->business_name ?? 'us');

        $callLog = $callLogId ? CallLog::find($callLogId) : null;
        $callerName = null;
        $isKnownCaller = false;
        if ($callLog?->user_id) {
            $callerUser = User::find($callLog->user_id);
            $callerName = $callerUser?->first_name;
            $isKnownCaller = true;
        }

        if ($isKnownCaller) {
            $ivrTemplate = data_get($vendor?->options ?? [], 'voicemail_message')
                ?: \App\Livewire\Vendors\VendorOptions::DEFAULT_VOICEMAIL;
        } else {
            $ivrTemplate = data_get($vendor?->options ?? [], 'voicemail_message_unknown')
                ?: \App\Livewire\Vendors\VendorOptions::DEFAULT_VOICEMAIL_UNKNOWN;
        }

        $ivrPrompt = $this->renderPrompt($ivrTemplate, [
            '{name}' => $callerName ?? '',
            '{company}' => $shortName,
            '{greeting}' => $this->buildTimeGreeting(),
        ]);

        return $ivrPrompt;
    }

    /**
     * Send the voicemail IVR gather_using_speak command.
     * Called either directly (no active playback) or from handleCallPlaybackEnded
     * after the ringback has been confirmed stopped.
     */
    protected function startVoicemailGather(string $callControlId, array $context): void
    {
        $callLogId = $context['call_log_id'] ?? null;
        $ivrPrompt = $context['ivr_prompt'];
        $validDigits = $context['valid_digits'];
        $isKnownCaller = $context['is_known_caller'];
        $originalCaller = $context['original_caller'] ?? null;

        Log::channel('telnyx')->info('Playing voicemail IVR menu', [
            'call_control_id' => $callControlId,
            'call_log_id' => $callLogId,
            'ivr_prompt' => $ivrPrompt,
            'is_known_caller' => $isKnownCaller,
        ]);

        $retryCount = $context['retry_count'] ?? 0;

        // Stamp when the prompt started so gather.ended can tell "the menu
        // played and nobody pressed" (normal — go to voicemail) from "the
        // gather died before speaking" (retry). See handleCallGatherEnded.
        Cache::put("telnyx_voicemail_gather_started:{$callControlId}", microtime(true), now()->addMinutes(5));

        $this->sendCallCommand($callControlId, 'gather_using_speak', [
            'payload' => $ivrPrompt,
            ...$this->ttsVoiceParams(),
            'valid_digits' => $validDigits,
            'minimum_digits' => 1,
            'maximum_digits' => 1,
            // Wait 2 seconds after the menu finishes for a digit press, then
            // fall through to the voicemail greeting. Longer waits felt like dead-air to
            // most callers — they assume the call is broken and hang up.
            'timeout_millis' => 2000,
            'maximum_tries' => 1,
            'client_state' => base64_encode(json_encode([
                'action' => 'voicemail_ivr_menu',
                'call_log_id' => $callLogId,
                'original_caller' => $originalCaller,
                'is_known_caller' => $isKnownCaller,
                'retry_count' => $retryCount,
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

        // ── Admin DTMF screen result ──
        if ($action === 'admin_screen') {
            return $this->handleAdminScreenResult($callControlId, $digits, $clientState);
        }

        if ($action === 'voicemail_ivr_ringback_wait') {
            return $this->handleRingbackGatherResult($callControlId, $digits, $clientState);
        }

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
                ?: \App\Livewire\Vendors\VendorOptions::DEFAULT_IVR_PRESS1;
            $press1Payload = $this->renderPrompt($press1Template, [
                '{name}' => $callerName ?? '',
                '{company}' => $shortName,
                '{greeting}' => $this->buildTimeGreeting(),
            ]);

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
                ?: \App\Livewire\Vendors\VendorOptions::DEFAULT_IVR_PRESS2;
            $press2Payload = $this->renderPrompt($press2Template, [
                '{name}' => $callerName ?? '',
                '{company}' => $shortName,
                '{greeting}' => $this->buildTimeGreeting(),
            ]);

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
            // ── No digit / timeout ──
            // Staying on the line IS the advertised way to reach voicemail, so
            // no-digit is the normal path — never replay the menu just because
            // nobody pressed.
            //
            // Retry only when the prompt genuinely never played, and decide
            // that from HOW LONG the gather ran, not from a companion webhook:
            // gather_using_speak does not reliably emit its own
            // call.speak.ended, so the old telnyx_voicemail_tts_played flag was
            // almost never set and every voicemail caller heard the whole menu
            // twice (12 of 17 sampled transcripts; production call 1940 played
            // a 14s menu and still logged "TTS never played").
            // A gather that spoke the menu runs ~14-18s; one that died before
            // speaking ends after just the DTMF timeout (~2s).
            $retryCount = $clientState['retry_count'] ?? 0;
            $gatherStartedAt = Cache::pull("telnyx_voicemail_gather_started:{$callControlId}");
            $gatherSeconds = $gatherStartedAt ? microtime(true) - (float) $gatherStartedAt : null;
            // Unknown elapsed time (cache miss) counts as played: replaying is
            // the worse failure.
            $promptPlayed = $gatherSeconds === null || $gatherSeconds >= self::VOICEMAIL_PROMPT_MIN_SECONDS;

            if ($retryCount < 1 && ! $promptPlayed) {
                Log::channel('telnyx')->info('IVR: gather ended before the menu could play — retrying once', [
                    'call_control_id' => $callControlId,
                    'retry_count' => $retryCount,
                    'call_log_id' => $callLogId,
                    'gather_seconds' => $gatherSeconds,
                    'gather_status' => $payload['status'] ?? null,
                ]);

                $this->startVoicemailGather($callControlId, [
                    'call_log_id' => $callLogId,
                    'ivr_prompt' => $this->buildIvrPrompt($callLogId),
                    'valid_digits' => $isKnownCaller ? '12' : '2',
                    'is_known_caller' => $isKnownCaller,
                    'original_caller' => $originalCaller,
                    'retry_count' => $retryCount + 1,
                ]);

                return response()->json(['status' => 'ok']);
            }

            Log::channel('telnyx')->info('IVR: no digit pressed — playing voicemail greeting', [
                'call_control_id' => $callControlId,
                'call_log_id' => $callLogId,
                'gather_seconds' => $gatherSeconds,
                'gather_status' => $payload['status'] ?? null,
                'retry_count' => $retryCount,
            ]);

            // Menu played (or we already retried) — go directly to voicemail greeting
            $vendor = Vendor::find(1);
            $shortName = data_get($vendor?->options ?? [], 'short_name') ?: ($vendor?->business_name ?? 'us');

            $greetingTemplate = data_get($vendor?->options ?? [], 'voicemail_greeting')
                ?: \App\Livewire\Vendors\VendorOptions::DEFAULT_VOICEMAIL_GREETING;
            $greetingPayload = $this->renderPrompt($greetingTemplate, [
                '{name}' => $callerName ?? '',
                '{company}' => $shortName,
                '{greeting}' => $this->buildTimeGreeting(),
            ]);

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
     * Handle gather result from the ringback-wait phase (after IVR TTS played).
     * Digit pressed → handle same as IVR menu; no digit → voicemail greeting.
     */
    protected function handleRingbackGatherResult(string $callControlId, string $digits, array $clientState): JsonResponse
    {
        $callLogId = $clientState['call_log_id'] ?? null;
        $originalCaller = $clientState['original_caller'] ?? null;
        $isKnownCaller = $clientState['is_known_caller'] ?? false;

        // Resolve caller name for placeholder substitution
        $callLog = $callLogId ? CallLog::find($callLogId) : null;
        $callerName = null;
        if ($callLog?->user_id) {
            $callerUser = User::find($callLog->user_id);
            $callerName = $callerUser?->first_name;
        }

        $vendor = Vendor::find(1);
        $shortName = data_get($vendor?->options ?? [], 'short_name') ?: ($vendor?->business_name ?? 'us');

        if ($digits === '1' && $isKnownCaller) {
            Log::channel('telnyx')->info('Ringback-wait: caller pressed 1 — re-dialing', [
                'call_control_id' => $callControlId,
            ]);

            $this->sendEmergencyContactsSms($originalCaller);

            $press1Template = data_get($vendor?->options ?? [], 'ivr_press1_message')
                ?: \App\Livewire\Vendors\VendorOptions::DEFAULT_IVR_PRESS1;
            $press1Payload = $this->renderPrompt($press1Template, [
                '{name}' => $callerName ?? '',
                '{company}' => $shortName,
                '{greeting}' => $this->buildTimeGreeting(),
            ]);

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
            Log::channel('telnyx')->info('Ringback-wait: caller pressed 2 — sending SMS', [
                'call_control_id' => $callControlId,
                'caller_number' => $originalCaller,
            ]);

            $this->sendCallbackSms($callLogId, $originalCaller);

            $press2Template = data_get($vendor?->options ?? [], 'ivr_press2_message')
                ?: \App\Livewire\Vendors\VendorOptions::DEFAULT_IVR_PRESS2;
            $press2Payload = $this->renderPrompt($press2Template, [
                '{name}' => $callerName ?? '',
                '{company}' => $shortName,
                '{greeting}' => $this->buildTimeGreeting(),
            ]);

            $this->sendCallCommand($callControlId, 'speak', [
                'payload' => $press2Payload,
                ...$this->ttsVoiceParams(),
                'client_state' => base64_encode(json_encode([
                    'action' => 'ivr_sms_confirmation',
                    'call_log_id' => $callLogId,
                ])),
            ]);

            if ($callLogId) {
                CallLog::where('id', $callLogId)->update([
                    'status' => CallLog::STATUS_MISSED,
                    'metadata->ivr_action' => 'sms_callback',
                ]);
            }
        } else {
            // No digit — proceed to voicemail greeting
            Log::channel('telnyx')->info('Ringback-wait: no digit pressed — playing voicemail greeting', [
                'call_control_id' => $callControlId,
                'call_log_id' => $callLogId,
            ]);

            $greetingTemplate = data_get($vendor?->options ?? [], 'voicemail_greeting')
                ?: \App\Livewire\Vendors\VendorOptions::DEFAULT_VOICEMAIL_GREETING;
            $greetingPayload = $this->renderPrompt($greetingTemplate, [
                '{name}' => $callerName ?? '',
                '{company}' => $shortName,
                '{greeting}' => $this->buildTimeGreeting(),
            ]);

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
     * Send callback notification to the business SMS system on behalf of the caller (IVR Press 2).
     * Creates a message in the app's SMS thread so it appears in the messaging interface
     * and triggers push notifications to all admins.
     */
    protected function sendCallbackSms(?int $callLogId, ?string $callerNumber): void
    {
        if (! $callerNumber) {
            Log::channel('telnyx')->warning('Cannot send callback SMS — no caller number', [
                'call_log_id' => $callLogId,
            ]);
            return;
        }

        // Look up caller name from database user or CNAM on call log
        $callLog = $callLogId ? CallLog::find($callLogId) : null;
        $callerName = null;
        if ($callLog?->user_id) {
            $callerUser = User::find($callLog->user_id);
            $callerName = $callerUser?->full_name;
        }
        if (! $callerName && $callLog?->caller_name) {
            $callerName = $callLog->caller_name;
        }

        $callerDisplay = $callerName
            ? "{$callerName} ({$callerNumber})"
            : $callerNumber;

        $vendor = Vendor::find(1);
        // short_name falls back to the full business name when not set.
        $businessName = $vendor?->short_name ?: ($vendor?->business_name ?: 'us');
        $website = trim((string) ($vendor?->business_website ?? ''));
        $smsText = "Hi, {$businessName} received your call. We'll get back to you as soon as possible.";
        if ($website !== '') {
            $smsText .= ' ' . $website;
        }

        $ourNumber = config('services.telnyx.from');
        if (! $ourNumber) {
            Log::channel('telnyx')->error('Cannot send callback SMS — Telnyx from number not configured');
            return;
        }

        $normalizedCaller = GroupSmsService::formatE164($callerNumber);

        // Send a real SMS to the caller confirming the callback
        $apiKey = config('services.telnyx.api_key');
        $messagingProfileId = config('services.telnyx.messaging_profile_id');

        if ($apiKey) {
            try {
                $toPhone = $normalizedCaller;
                if (app()->environment(['local', 'development']) && ($devTo = config('services.telnyx.dev_to'))) {
                    $toPhone = $devTo;
                }

                $smsPayload = [
                    'from' => $ourNumber,
                    'to' => $toPhone,
                    'text' => $smsText,
                ];

                if ($messagingProfileId) {
                    $smsPayload['messaging_profile_id'] = $messagingProfileId;
                }

                Http::withToken($apiKey)->post('https://api.telnyx.com/v2/messages', $smsPayload);

                Log::channel('telnyx')->info('Callback SMS sent to caller', [
                    'caller_number' => $toPhone,
                    'from' => $ourNumber,
                ]);
            } catch (\Throwable $e) {
                Log::channel('telnyx')->warning('Failed to send callback SMS to caller', [
                    'caller_number' => $normalizedCaller,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Find or create the SMS thread for this caller
        $thread = SmsGroupThread::findByParticipant($ourNumber, $normalizedCaller);

        if (! $thread) {
            $thread = $this->createThreadForInboundMessage($normalizedCaller, $ourNumber);
        } elseif (! $thread->vendor_id) {
            $thread->update(['vendor_id' => Vendor::find(1)?->id]);
        }

        // Create the message in the app's SMS system so it appears in the messaging interface
        $inAppText = "📞 {$callerDisplay} called and requested a callback via the phone menu. Please call them back ASAP.";
        $message = SmsMessage::create([
            'thread_id' => $thread?->id,
            'provider' => 'telnyx',
            'provider_message_id' => 'callback_' . ($callLogId ?? uniqid()),
            'direction' => SmsMessage::DIRECTION_INBOUND,
            'from_number' => $normalizedCaller,
            'to_numbers' => [$ourNumber],
            'text' => $inAppText,
            'status' => 'received',
        ]);

        // Also record the outbound SMS we just sent to the caller so it appears
        // in the thread alongside any future replies.
        if ($thread) {
            SmsMessage::create([
                'thread_id' => $thread->id,
                'provider' => 'telnyx',
                'provider_message_id' => 'callback_reply_' . ($callLogId ?? uniqid()),
                'direction' => SmsMessage::DIRECTION_OUTBOUND,
                'from_number' => $ourNumber,
                'to_numbers' => [$normalizedCaller],
                'text' => $smsText,
                'status' => 'sent',
            ]);
        }

        if ($thread) {
            $thread->update(['last_activity_at' => now()]);

            try {
                \App\Events\SmsMessageReceived::dispatch($thread->id);
            } catch (\Throwable $e) {
                Log::warning('SMS broadcast failed', [
                    'thread_id' => $thread->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        SendInboundSmsBrowserNotifications::dispatch($message->id);

        Log::channel('telnyx')->info('Callback message created in SMS system', [
            'call_log_id' => $callLogId,
            'caller_number' => $normalizedCaller,
            'thread_id' => $thread?->id,
            'message_id' => $message->id,
        ]);
    }

    /**
     * Send an SMS alert to each admin phone when a caller requests a callback (Press 2).
     */
    protected function sendCallbackAlertToAdmins(string $callerDisplay): void
    {
        $vendor = Vendor::find(1);
        $vendorOptions = $vendor ? (array) $vendor->options : [];
        $recipientUserIds = (array) data_get($vendorOptions, 'call_recipients', []);

        if (empty($recipientUserIds)) {
            return;
        }

        $adminUsers = User::whereIn('id', $recipientUserIds)
            ->whereNotNull('cell_phone')
            ->where('cell_phone', '!=', '')
            ->get();

        if ($adminUsers->isEmpty()) {
            return;
        }

        $apiKey = config('services.telnyx.api_key');
        $from = config('services.telnyx.from');
        $messagingProfileId = config('services.telnyx.messaging_profile_id');

        if (! $apiKey || ! $from) {
            return;
        }

        $shortName = data_get($vendorOptions, 'short_name') ?: ($vendor?->business_name ?? 'your business');
        $alertText = "📞 Callback requested: {$callerDisplay} just called {$shortName} and pressed 2 for a callback. Please call them back ASAP.";

        foreach ($adminUsers as $admin) {
            $adminPhone = GroupSmsService::formatE164($admin->cell_phone);

            if (app()->environment(['local', 'development']) && ($devTo = config('services.telnyx.dev_to'))) {
                $adminPhone = $devTo;
            }

            try {
                $payload = [
                    'from' => $from,
                    'to' => $adminPhone,
                    'text' => $alertText,
                ];

                if ($messagingProfileId) {
                    $payload['messaging_profile_id'] = $messagingProfileId;
                }

                Http::withToken($apiKey)->post('https://api.telnyx.com/v2/messages', $payload);

                Log::channel('telnyx')->info('Callback alert SMS sent to admin', [
                    'admin_user_id' => $admin->id,
                    'admin_name' => $admin->full_name,
                    'phone' => $adminPhone,
                ]);
            } catch (\Throwable $e) {
                Log::channel('telnyx')->warning('Failed to send callback alert to admin', [
                    'admin_user_id' => $admin->id,
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
     * Create a Telnyx conference seeded with the given call leg.
     * Returns the conference id, or null on failure.
     */
    protected function createConference(string $name, string $beneficiaryCallControlId): ?string
    {
        $apiKey = config('services.telnyx.api_key');
        if (! $apiKey) {
            return null;
        }

        try {
            $response = $this->telnyxHttp(0)
                ->post('https://api.telnyx.com/v2/conferences', [
                    'name' => $name,
                    'call_control_id' => $beneficiaryCallControlId,
                    'comfort_noise' => true,
                    // Disable join/leave beeps. They overlap the screening prompt /
                    // welcome TTS as participants enter and leave the conference
                    // and were reported as confusing in production.
                    'beep_enabled' => 'never',
                ]);

            if ($response->successful()) {
                $id = $response->json('data.id');
                Log::channel('telnyx')->info('Conference created', [
                    'name' => $name,
                    'conference_id' => $id,
                    'beneficiary_call_control_id' => $beneficiaryCallControlId,
                ]);
                return $id;
            }

            Log::channel('telnyx')->error('Conference create failed', [
                'name' => $name,
                'status' => $response->status(),
                'error' => $response->json(),
            ]);
        } catch (\Exception $e) {
            Log::channel('telnyx')->error('Conference create exception', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Join an existing Telnyx conference with a new call leg.
     * Hardened with telnyxHttp() for timeout + retry to prevent webhook hangs.
     * Explicitly sets mute:false + hold:false to guarantee media flow (prevents one-way audio).
     */
    protected function joinConference(string $conferenceId, string $callControlId): bool
    {
        try {
            $response = $this->telnyxHttp()
                ->post("https://api.telnyx.com/v2/conferences/{$conferenceId}/actions/join", [
                    'call_control_id' => $callControlId,
                    'mute' => false,
                    'hold' => false,
                ]);

            if ($response->successful()) {
                Log::channel('telnyx')->info('Joined conference', [
                    'conference_id' => $conferenceId,
                    'call_control_id' => $callControlId,
                ]);
                return true;
            }

            $body = $response->json();
            $code = (string) data_get($body, 'errors.0.code');
            $level = in_array($code, ['90018', '90015'], true) ? 'info' : 'error';
            Log::channel('telnyx')->{$level}('Conference join failed', [
                'conference_id' => $conferenceId,
                'call_control_id' => $callControlId,
                'status' => $response->status(),
                'error' => $body,
            ]);
        } catch (\Exception $e) {
            Log::channel('telnyx')->error('Conference join exception', [
                'conference_id' => $conferenceId,
                'call_control_id' => $callControlId,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * Send a call control command to the Telnyx API.
     *
     * Hardened for reliability:
     *  - explicit connect/response timeouts so a slow Telnyx response can
     *    never block the webhook worker indefinitely (the #1 cause of silent
     *    "stuck" calls);
     *  - transient connection failures (timeout, reset, DNS) are retried;
     *  - an idempotent `command_id` is attached so a retry can never make
     *    Telnyx execute the command twice (no double-speak / double-dial).
     *    4xx responses (e.g. 90018 call-ended) are NOT retried.
     */
    protected function sendCallCommand(string $callControlId, string $action, array $params = []): bool
    {
        $apiKey = config('services.telnyx.api_key');

        if (!$apiKey) {
            Log::channel('telnyx')->error('Telnyx API key not configured for call control');
            return false;
        }

        // Reuse a caller-supplied command_id when present; otherwise generate
        // one. The same id is sent on every retry attempt so Telnyx dedupes.
        if (empty($params['command_id'])) {
            $params['command_id'] = (string) \Illuminate\Support\Str::uuid();
        }

        try {
            $response = $this->telnyxHttp()
                ->post("https://api.telnyx.com/v2/calls/{$callControlId}/actions/{$action}", $params);

            if ($response->successful()) {
                Log::channel('telnyx')->info("Call command sent: {$action}", [
                    'call_control_id' => $callControlId,
                    'command_id' => $params['command_id'],
                    'result' => $response->json('data.result'),
                ]);
                return true;
            } else {
                $body = $response->json();
                $code = (string) data_get($body, 'errors.0.code');
                // 90018 = call already ended, 90015 = invalid call control id (leg ended).
                // Both are expected race conditions when the remote party hangs up before our command lands.
                $isCallEndedRace = in_array($code, ['90018', '90015'], true);
                $level = $isCallEndedRace ? 'info' : 'error';
                Log::channel('telnyx')->{$level}("Call command failed: {$action}", [
                    'call_control_id' => $callControlId,
                    'command_id' => $params['command_id'],
                    'status' => $response->status(),
                    'error' => $body,
                ]);

                // Safety net: if a TTS prompt fails for a reason other than the
                // call already ending, play a pre-recorded fallback clip so the
                // caller hears something instead of dead silence.
                if ($action === 'speak' && ! $isCallEndedRace) {
                    $this->playFallbackAudio($callControlId);
                }

                return false;
            }
        } catch (\Exception $e) {
            Log::channel('telnyx')->error("Exception sending call command: {$action}", [
                'call_control_id' => $callControlId,
                'command_id' => $params['command_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            if ($action === 'speak') {
                $this->playFallbackAudio($callControlId);
            }

            return false;
        }
    }

    /**
     * Shared, hardened HTTP client for every Telnyx Call Control / dial
     * request. The explicit connect/response timeouts guarantee a slow Telnyx
     * API response can never block the webhook worker (or hold a call/conference
     * lock) indefinitely — the root cause of silent "stuck" calls.
     *
     * @param  int|null  $retries  Transient connection-error retries. Defaults
     *                             to the configured value. Pass 0 for
     *                             non-idempotent requests (dial/conference)
     *                             that lack a command_id, so a retry can never
     *                             place a duplicate call.
     */
    protected function telnyxHttp(?int $retries = null): \Illuminate\Http\Client\PendingRequest
    {
        $retries ??= (int) config('services.telnyx.command_retries', 2);

        $request = Http::withToken(config('services.telnyx.api_key'))
            ->connectTimeout((int) config('services.telnyx.command_connect_timeout', 5))
            ->timeout((int) config('services.telnyx.command_timeout', 10));

        if ($retries > 0) {
            $request->retry(
                $retries,
                250,
                fn ($exception) => $exception instanceof \Illuminate\Http\Client\ConnectionException,
                throw: false,
            );
        }

        return $request;
    }

    /**
     * Play the configured fallback audio clip on a call leg. Used as a last
     * resort when TTS fails so the caller is not left in silence. No-op when
     * no fallback URL is configured.
     */
    protected function playFallbackAudio(string $callControlId): void
    {
        $fallbackUrl = config('services.telnyx.tts_fallback_audio_url');

        if (empty($fallbackUrl)) {
            return;
        }

        Log::channel('telnyx')->warning('TTS failed; playing fallback audio', [
            'call_control_id' => $callControlId,
            'audio_url' => $fallbackUrl,
        ]);

        try {
            $this->telnyxHttp()->post(
                "https://api.telnyx.com/v2/calls/{$callControlId}/actions/playback_start",
                [
                    'audio_url' => $fallbackUrl,
                    'command_id' => (string) \Illuminate\Support\Str::uuid(),
                ],
            );
        } catch (\Throwable $e) {
            Log::channel('telnyx')->error('Failed to play fallback audio', [
                'call_control_id' => $callControlId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Detect and short-circuit duplicate webhook deliveries. Telnyx retries
     * webhooks (and can deliver the same event more than once), which without
     * guarding can double-bridge calls, double-send SMS, etc. Each event
     * carries a unique `id`; we record it in the cache and treat any repeat
     * within the TTL as a duplicate. Events without an `id` (e.g. internal
     * test payloads) are never treated as duplicates.
     */
    protected function isDuplicateWebhookEvent(array $data): bool
    {
        $eventId = $data['id'] ?? null;

        if (empty($eventId)) {
            return false;
        }

        // Cache::add is atomic: it returns false when the key already exists,
        // marking this delivery as a duplicate.
        $isFirstDelivery = Cache::add("telnyx_event:{$eventId}", true, now()->addHours(6));

        if (! $isFirstDelivery) {
            Log::channel('telnyx')->info('Ignoring duplicate Telnyx webhook event', [
                'event_id' => $eventId,
                'event_type' => $data['event_type'] ?? null,
            ]);
        }

        return ! $isFirstDelivery;
    }

    /**
     * Start call recording on a call leg using configured channels/format.
     * Stamps the CallLog with the recording metadata.
     */
    protected function startCallRecording(string $callControlId, CallLog $callLog): bool
    {
        $channels = (string) config('call_recording.channels', 'dual');
        $format = strtolower((string) config('call_recording.format', 'wav'));
        $playBeep = (bool) config('call_recording.play_beep', true);

        $params = [
            'channels' => $channels,
            'format' => $format,
            'play_beep' => $playBeep,
            'client_state' => base64_encode(json_encode([
                'action' => 'call_recording',
                'call_log_id' => $callLog->id,
            ])),
        ];

        Log::channel('telnyx')->info('Starting call recording with optimized quality settings', [
            'call_log_id' => $callLog->id,
            'call_control_id' => $callControlId,
            'mode' => config('call_recording.mode', 'auto'),
            'channels' => $channels,
            'format' => $format,
            'play_beep' => $playBeep,
        ]);

        // Transcription is handled by OpenAI Whisper after the recording is
        // stored locally — see App\Jobs\TranscribeCallRecording. Telnyx STT
        // is skipped because it costs $0.015/min and returned empty text in
        // testing; OpenAI Whisper is $0.006/min and supports Polish.

        $ok = $this->sendCallCommand($callControlId, 'record_start', $params);

        if ($ok) {
            $callLog->update([
                'recording_started_at' => now(),
                'purge_after' => now()->addDays((int) config('call_recording.retention_days', 180)),
            ]);
        }

        return $ok;
    }

    /**
     * Handle call.recording.transcription.saved — Telnyx finished STT.
     *
     * Disabled: transcription is now done locally by OpenAI Whisper via the
     * `calls:process-recordings` artisan command. We still log the event for
     * diagnostics but no longer persist Telnyx's (frequently empty) text.
     */
    protected function handleCallRecordingTranscriptionSaved(array $data): JsonResponse
    {
        $payload = $data['payload'] ?? [];
        Log::channel('telnyx')->info('Ignoring Telnyx call.recording.transcription.saved (Whisper handles STT)', [
            'call_control_id' => $payload['call_control_id'] ?? null,
            'transcription_id' => $payload['transcription_id'] ?? null,
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * @deprecated Kept for reference; no longer reachable.
     */
    protected function handleCallRecordingTranscriptionSavedLegacy(array $data): JsonResponse
    {
        $payload = $data['payload'] ?? [];
        $callControlId = $payload['call_control_id'] ?? null;
        $clientStateRaw = $payload['client_state'] ?? null;
        $clientState = $clientStateRaw
            ? json_decode(base64_decode($clientStateRaw), true)
            : null;

        $callLogId = $clientState['call_log_id'] ?? null;
        $callLog = $callLogId
            ? CallLog::find($callLogId)
            : CallLog::findByCallControlId($callControlId);

        if (! $callLog) {
            Log::channel('telnyx')->warning('Recording transcription saved but no CallLog found', [
                'call_control_id' => $callControlId,
                'transcription_id' => $payload['transcription_id'] ?? null,
            ]);
            return response()->json(['status' => 'ok']);
        }

        $text = $payload['transcription_data']['text']
            ?? $payload['transcription']['text']
            ?? null;
        $language = $payload['transcription_data']['language']
            ?? $payload['transcription']['language']
            ?? null;
        $segments = $payload['transcription_data']['segments']
            ?? $payload['transcription']['segments']
            ?? null;

        $transcript = CallTranscript::updateOrCreate(
            ['call_log_id' => $callLog->id],
            [
                'telnyx_recording_id' => $payload['recording_id'] ?? null,
                'telnyx_transcription_id' => $payload['transcription_id'] ?? null,
                'engine' => 'telnyx',
                'language' => $language,
                'text' => $text,
                'segments' => $segments,
                'status' => $text ? CallTranscript::STATUS_READY : CallTranscript::STATUS_FAILED,
                'failure_reason' => $text ? null : 'empty_transcript',
            ],
        );

        if ($language) {
            $callLog->update(['language' => $language]);
        }

        Log::channel('telnyx')->info('Call transcription saved', [
            'call_log_id' => $callLog->id,
            'transcript_id' => $transcript->id,
            'language' => $language,
            'has_text' => (bool) $text,
        ]);

        // Summarization runs via the `calls:process-recordings` artisan command, not here.

        return response()->json(['status' => 'ok']);
    }

    /**
    /**
     * Bridge two call legs directly (legacy 2-leg bridge).
     * Used for outbound-to-voicemail (AMD detects machine → bridge user + voicemail).
     * Hardened with telnyxHttp() for timeout + retry to prevent webhook hangs.
     */
    protected function bridgeCalls(string $callControlIdA, string $callControlIdB): bool
    {
        try {
            // command_id deduplicates — Telnyx ignores duplicate bridge commands with the same ID
            $commandId = 'bridge_' . md5($callControlIdA . $callControlIdB);

            $response = $this->telnyxHttp()
                ->post("https://api.telnyx.com/v2/calls/{$callControlIdA}/actions/bridge", [
                    'call_control_id' => $callControlIdB,
                    'command_id' => $commandId,
                ]);

            if ($response->successful()) {
                Log::channel('telnyx')->info('Calls bridged successfully', [
                    'call_control_id_a' => $callControlIdA,
                    'call_control_id_b' => $callControlIdB,
                ]);
                return true;
            } else {
                $body = $response->json();
                $code = (string) data_get($body, 'errors.0.code');
                $level = in_array($code, ['90018', '90015'], true) ? 'info' : 'error';
                Log::channel('telnyx')->{$level}('Bridge failed', [
                    'call_control_id_a' => $callControlIdA,
                    'call_control_id_b' => $callControlIdB,
                    'status' => $response->status(),
                    'error' => $body,
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::channel('telnyx')->error('Bridge exception', [
                'call_control_id_a' => $callControlIdA,
                'call_control_id_b' => $callControlIdB,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Hang up all ringing admin legs except the one who answered and got bridged.
     */
    protected function hangupOtherAdminLegs(?int $callLogId, string $answeredAdminCallControlId): void
    {
        if (! $callLogId) {
            return;
        }

        $callLog = CallLog::find($callLogId);
        if (! $callLog) {
            return;
        }

        $metadata = $callLog->metadata ?? [];
        $adminCallControlIds = $metadata['admin_call_control_ids'] ?? [];

        foreach ($adminCallControlIds as $adminCcId) {
            if ($adminCcId !== $answeredAdminCallControlId) {
                Log::channel('telnyx')->info('Hanging up other admin ring leg', [
                    'admin_call_control_id' => $adminCcId,
                    'call_log_id' => $callLogId,
                ]);
                $this->sendCallCommand($adminCcId, 'hangup');
            }
        }
    }

    /**
     * Build a normalized unique list of click-to-call targets from client_state
     * and fallback metadata.
     *
     * @return array<int, string>
     */
    protected function extractClickToCallTargets(array $clientState, ?CallLog $callLog = null): array
    {
        $targets = [];

        if (! empty($clientState['target_phone']) && is_string($clientState['target_phone'])) {
            $targets[] = $clientState['target_phone'];
        }

        if (! empty($clientState['target_phones']) && is_array($clientState['target_phones'])) {
            foreach ($clientState['target_phones'] as $phone) {
                if (is_string($phone)) {
                    $targets[] = $phone;
                }
            }
        }

        if ($callLog) {
            $metadata = $callLog->metadata ?? [];
            if (! empty($metadata['target_phones']) && is_array($metadata['target_phones'])) {
                foreach ($metadata['target_phones'] as $phone) {
                    if (is_string($phone)) {
                        $targets[] = $phone;
                    }
                }
            }
        }

        return collect($targets)
            ->map(fn (string $phone) => $this->normalizeDialPhone($phone))
            ->filter(fn (?string $phone) => is_string($phone) && $phone !== '' && ! GroupSmsService::isOurNumber($phone))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Record a failed click-to-call target leg and report whether any target legs
     * are still pending.
     */
    protected function markFailedTargetAndHasRemaining(CallLog $callLog, string $failedCallControlId): bool
    {
        $metadata = $callLog->metadata ?? [];
        $allTargetLegIds = collect($metadata['target_call_control_ids'] ?? [])
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->values();

        if ($allTargetLegIds->count() <= 1) {
            return false;
        }

        $failedLegIds = collect($metadata['failed_target_call_control_ids'] ?? [])
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->push($failedCallControlId)
            ->unique()
            ->values();

        $metadata['failed_target_call_control_ids'] = $failedLegIds->all();
        $callLog->update(['metadata' => $metadata]);

        $answeredLegId = $metadata['answered_target_call_control_id'] ?? null;
        if (is_string($answeredLegId) && $answeredLegId !== '') {
            return false;
        }

        $remainingLegIds = $allTargetLegIds->diff($failedLegIds);

        return $remainingLegIds->isNotEmpty();
    }

    /**
     * Hang up all sibling click-to-call target legs except the one that answered.
     */
    protected function hangupOtherClickToCallTargetLegs(CallLog $callLog, string $answeredTargetCallControlId): void
    {
        $targetLegIds = collect(($callLog->metadata ?? [])['target_call_control_ids'] ?? [])
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique();

        foreach ($targetLegIds as $targetLegId) {
            if ($targetLegId === $answeredTargetCallControlId) {
                continue;
            }

            $this->sendCallCommand($targetLegId, 'hangup');
        }
    }

    /**
     * Hang up all outstanding click-to-call target legs when the user leg ends
     * before a successful bridge.
     */
    protected function hangupOutstandingClickToCallTargets(CallLog $callLog): void
    {
        $metadata = $callLog->metadata ?? [];
        $answeredLegId = $metadata['answered_target_call_control_id'] ?? null;

        $targetLegIds = collect($metadata['target_call_control_ids'] ?? [])
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique();

        foreach ($targetLegIds as $targetLegId) {
            if ($targetLegId === $answeredLegId) {
                continue;
            }

            $this->sendCallCommand($targetLegId, 'hangup');
        }
    }

    protected function normalizeDialPhone(?string $phone): ?string
    {
        if (! is_string($phone) || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);
        if (! is_string($digits) || $digits === '') {
            return null;
        }

        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+' . $digits;
        }

        return str_starts_with($phone, '+') ? $phone : ('+' . $digits);
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

        $this->updateOutboundMessageStatus($data, 'delivered');

        return response()->json(['status' => 'ok']);
    }

    /**
     * Persist a carrier status onto the outbound message and tell the open
     * conversation, so bubbles show delivered/failed instead of staying
     * silently on "sent" forever.
     */
    protected function updateOutboundMessageStatus(array $data, string $status): void
    {
        $providerId = $data['id'] ?? null;

        if (! $providerId) {
            return;
        }

        $message = \App\Models\SmsMessage::where('provider_message_id', $providerId)
            ->where('direction', 'outbound')
            ->first();

        if (! $message) {
            return;
        }

        // failed must never be overwritten by a late delivered event for
        // another recipient of the same group message.
        if ($message->status === 'failed' && $status === 'delivered') {
            return;
        }

        if ($message->status === $status) {
            return;
        }

        $message->status = $status;

        if ($status === 'failed' && isset($data['errors'])) {
            $raw = is_array($message->raw_payload) ? $message->raw_payload : [];
            $raw['delivery_errors'] = $data['errors'];
            $message->raw_payload = $raw;
        }

        $message->save();

        try {
            event(new \App\Events\SmsMessageStatusUpdated(
                messageId: $message->id,
                threadId: (int) $message->thread_id,
                status: $status,
            ));
        } catch (\Throwable $e) {
            // Broadcasting is best-effort; the status is already persisted.
            Log::channel('telnyx')->warning('Status broadcast failed', ['error' => $e->getMessage()]);
        }
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

        $this->updateOutboundMessageStatus($data, 'failed');

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
        // Find which of our Telnyx numbers is in the recipient list
        $ourNumber = collect($allTo)->first(fn ($phone) => GroupSmsService::isOurNumber($phone))
            ?? config('services.telnyx.from');
        $to = in_array($ourNumber, $allTo) ? $ourNumber : ($allTo[0] ?? null);

        $text = $data['text'] ?? '';

        // Fix double-encoded UTF-8 text (e.g. iMessage reactions via carrier SMS gateways).
        // Detect Â/Ã (U+00C2/U+00C3 as 2-byte UTF-8) followed by another multi-byte sequence —
        // the hallmark of UTF-8 bytes re-encoded as UTF-8.
        if ($text && preg_match('/\xC3[\x82\x83]\xC2[\x80-\xBF]/', $text)) {
            $decoded = @iconv('UTF-8', 'CP1252//IGNORE', $text);
            if ($decoded !== false && mb_check_encoding($decoded, 'UTF-8')) {
                $text = $decoded;
            }
        }

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

        $normalizedFrom = is_string($from) ? GroupSmsService::formatE164($from) : null;
        if ($normalizedFrom && BlockedCaller::isBlocked($normalizedFrom)) {
            Log::channel('telnyx')->info('Inbound SMS from blocked caller ignored', [
                'from' => $normalizedFrom,
                'to' => $to,
                'message_id' => $data['id'] ?? null,
            ]);

            return response()->json(['status' => 'ok', 'message' => 'blocked caller ignored']);
        }

        $normalizedText = strtoupper(trim($text));
        if (in_array($normalizedText, ['START', 'STOP', 'HELP'], true)) {
            Log::channel('telnyx')->info('Inbound compliance keyword received (provider/carrier may auto-respond)', [
                'keyword' => $normalizedText,
                'from' => $from,
                'to' => $to,
            ]);
        }

        // Build the full set of external participants for group MMS detection.
        // allTo includes our number + other external recipients; add the sender too.
        $externalPhones = collect($allTo)
            ->reject(fn ($phone) => GroupSmsService::isOurNumber($phone))
            ->push($from)
            ->map(fn ($p) => GroupSmsService::formatE164($p))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $isGroupMms = count($externalPhones) > 1;

        // Find the group thread this message belongs to
        $thread = null;
        if ($from && $to) {
            if ($isGroupMms) {
                // For multi-party group MMS, match by exact participant set.
                // Do NOT fall back to 1:1 threads — a new group thread will be
                // created below if none exists.
                $thread = SmsGroupThread::findByParticipantGroup($to, $externalPhones);
            } else {
                // Single-participant lookup for 1:1 threads
                $thread = SmsGroupThread::findByParticipant($to, $from);
            }

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
            $thread = $isGroupMms
                ? $this->createThreadForInboundGroupMessage($externalPhones, $to)
                : $this->createThreadForInboundMessage($from, $to);

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

        // If the sender hasn't opted in yet and we've never asked them, send the
        // START consent prompt so the conversation can continue. This covers the
        // case where a caller pressed "2" during the IVR (auto-creating a thread
        // with no prior opt-in) and then replied to the callback confirmation SMS.
        if ($thread
            && ! $isGroupMms
            && ! GroupSmsService::isStartKeyword($text)
            && $thread->opt_in_prompt_sent_at === null
            && $thread->hasPendingOptIn()
        ) {
            try {
                $this->groupSmsService->resendConsentPrompt($thread);
                Log::channel('telnyx')->info('Sent opt-in consent prompt to inbound sender', [
                    'thread_id' => $thread->id,
                    'from' => $from,
                ]);
            } catch (\Throwable $e) {
                Log::channel('telnyx')->warning('Failed to send opt-in consent prompt', [
                    'thread_id' => $thread->id,
                    'from' => $from,
                    'error' => $e->getMessage(),
                ]);
            }
        }

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

        // If no client matched, attempt to match to a vendor (sub) instead
        $subjectVendorId = null;
        if (! $clientId) {
            $subjectVendorId = $this->resolveSubjectVendorIdByPhone($normalizedSender);
        }

        // When the client has multiple users, set an explicit thread name
        // so this 1-on-1 thread is visually distinct from group threads.
        $threadName = null;
        if ($clientId) {
            $client = \App\Models\Client::with('users')->find($clientId);
            if ($client && $client->users->count() > 1) {
                $senderDigits = preg_replace('/\D/', '', $normalizedSender);
                $senderUser = $client->users->first(function ($user) use ($senderDigits) {
                    return preg_replace('/\D/', '', $user->cell_phone ?? '') === $senderDigits;
                });
                if ($senderUser) {
                    $threadName = trim($senderUser->first_name . ' ' . $senderUser->last_name);
                }
            }
        }

        // TODO: Multi-vendor support — resolve vendor by $ourNumber
        $vendorId = \App\Models\Vendor::find(1)?->id;

        // Always use the primary Telnyx number so all outbound goes from one number
        $primaryFrom = config('services.telnyx.from');

        $thread = SmsGroupThread::create([
            'from_number' => $primaryFrom,
            'participants' => [$normalizedSender],
            'name' => $threadName,
            'client_id' => $clientId,
            'subject_vendor_id' => $subjectVendorId,
            'vendor_id' => $vendorId,
            'last_activity_at' => now(),
        ]);

        // Auto-opt-in if this phone already opted in on another thread
        $alreadyOptedIn = SmsThreadParticipant::where('phone_number', $normalizedSender)
            ->whereNotNull('opted_in_at')
            ->exists();

        SmsThreadParticipant::create([
            'thread_id' => $thread->id,
            'phone_number' => $normalizedSender,
            'opted_in_at' => $alreadyOptedIn ? now() : null,
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
     * Create a new thread for a multi-party group MMS.
     *
     * @param  array<string>  $participantPhones  All external E.164 phones
     */
    protected function createThreadForInboundGroupMessage(array $participantPhones, string $ourNumber): ?SmsGroupThread
    {
        // Try to resolve a client from any of the participant phones
        $clientId = null;
        foreach ($participantPhones as $phone) {
            $clientId = $this->resolveClientIdByPhone($phone);
            if ($clientId) {
                break;
            }
        }

        // TODO: Multi-vendor support — resolve vendor by $ourNumber
        $vendorId = \App\Models\Vendor::find(1)?->id;

        // Always use the primary Telnyx number so all outbound goes from one number
        $primaryFrom = config('services.telnyx.from');

        $thread = SmsGroupThread::create([
            'from_number' => $primaryFrom,
            'participants' => array_values($participantPhones),
            'client_id' => $clientId,
            'vendor_id' => $vendorId,
            'last_activity_at' => now(),
        ]);

        // Collect phones that already opted in on other threads
        $alreadyOptedInPhones = SmsThreadParticipant::whereIn('phone_number', $participantPhones)
            ->whereNotNull('opted_in_at')
            ->pluck('phone_number')
            ->unique()
            ->all();

        foreach ($participantPhones as $phone) {
            SmsThreadParticipant::create([
                'thread_id' => $thread->id,
                'phone_number' => $phone,
                'opted_in_at' => in_array($phone, $alreadyOptedInPhones) ? now() : null,
            ]);
        }

        Log::channel('telnyx')->info('Auto-created new group thread for inbound MMS', [
            'thread_id' => $thread->id,
            'participants' => $participantPhones,
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
     * Try to find a vendor (sub) by a phone number (checking vendors.business_phone and user cell_phone).
     *
     * Phone numbers are stored as digits only, so we strip the E.164 prefix to compare.
     */
    protected function resolveSubjectVendorIdByPhone(string $e164Phone): ?int
    {
        $digits = preg_replace('/[^0-9]/', '', $e164Phone);

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        // Check vendor business_phone (may include separators)
        $vendor = \App\Models\Vendor::whereRaw("REPLACE(REPLACE(REPLACE(business_phone, '-', ''), ' ', ''), '.', '') = ?", [$digits])->first();
        if ($vendor) {
            return $vendor->id;
        }

        // Check user cell_phone — find vendor via pivot
        $user = User::where('cell_phone', $digits)->first();
        if ($user) {
            $vendor = $user->vendors()->first();
            if ($vendor) {
                return $vendor->id;
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

        // Authoritative final state: any recipient failing marks the message
        // failed; all-delivered marks it delivered.
        $recipientStatuses = collect($data['to'] ?? [])->pluck('status');
        if ($recipientStatuses->contains(fn ($s) => in_array($s, ['delivery_failed', 'sending_failed', 'failed'], true))) {
            $this->updateOutboundMessageStatus($data, 'failed');
        } elseif ($recipientStatuses->isNotEmpty() && $recipientStatuses->every(fn ($s) => $s === 'delivered')) {
            $this->updateOutboundMessageStatus($data, 'delivered');
        }

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
     * For Azure Neural voices we ship the prompt as SSML so we can:
     *  - wrap with a trailing <break> to stop the engine clipping the final
     *    syllable when Telnyx transitions to the next call command
     *    (playback_start, gather, hangup, etc.); and
     *  - request a friendlier prosody via Microsoft's mstts:express-as style.
     *
     * The matching renderPrompt() helper is responsible for emitting the
     * <speak> envelope so the payload sent to Telnyx is already valid SSML.
     *
     * @return array{voice: string, language: string, payload_type: string, voice_settings: array<string,mixed>}
     */
    protected function ttsVoiceParams(): array
    {
        $voiceType = config('services.telnyx.tts_voice_type');
        $isAzure = $voiceType === 'azure';

        $voiceSettings = ['type' => $voiceType];
        if ($isAzure) {
            // Azure Neural "speaking style" hint — friendly is the warmest
            // option for customer-facing IVR prompts.
            $voiceSettings['style'] = 'friendly';
        }

        return [
            'voice' => config('services.telnyx.tts_voice'),
            'language' => 'en-US',
            'payload_type' => $isAzure ? 'ssml' : 'text',
            'voice_settings' => $voiceSettings,
        ];
    }

    /**
     * Substitute {name}/{company}/{greeting} placeholders into a prompt
     * template and clean it up for Azure Neural TTS:
     *  - collapse whitespace runs left over from empty placeholders
     *  - remove spaces before terminal punctuation (e.g. ", " becoming " ,")
     *  - strip leading/trailing punctuation orphans (e.g. ", who's calling")
     *  - ensure the prompt ends with terminal punctuation so Azure doesn't
     *    clip the final word.
     */
    protected function renderPrompt(string $template, array $vars): string
    {
        $text = strtr($template, [
            '{name}' => $vars['{name}'] ?? '',
            '{company}' => $vars['{company}'] ?? '',
            '{greeting}' => $vars['{greeting}'] ?? '',
        ]);

        // Collapse multiple spaces and tidy spaces before punctuation.
        $text = preg_replace('/\s+/', ' ', trim($text));
        $text = preg_replace('/\s+([!.?,;:])/', '$1', $text);
        // Remove an orphan leading comma left by an empty placeholder
        // (e.g. ", you've reached..." → "you've reached...").
        $text = preg_replace('/^[,;:]\s*/', '', $text);
        // Collapse double punctuation like ".." or "!." that an empty
        // placeholder can leave behind.
        $text = preg_replace('/([.!?])[.!?,;:]+/', '$1', $text);

        // Ensure terminal punctuation so Azure Neural TTS doesn't drop the
        // last word.
        if ($text !== '' && ! preg_match('/[.!?]$/', $text)) {
            $text .= '.';
        }

        // For Azure voices we ship SSML (see ttsVoiceParams()) so we can add
        // a trailing <break> that stops the engine clipping the final
        // syllable when Telnyx fires the next call command. We also escape
        // XML-significant characters so customer-supplied prompts can't
        // break the SSML envelope.
        if (config('services.telnyx.tts_voice_type') === 'azure') {
            $escaped = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');

            // We rely on Azure Neural TTS's built-in prosody for sentence and
            // clause pauses (it parses ,.!? automatically). The leading and
            // trailing <break> tags are NOT prosody — they work around two
            // Telnyx pipeline bugs:
            //   - Leading break: Telnyx drops the first ~150-200ms of audio while
            //     warming up its outbound RTP stream. Short opening phrases like
            //     "Good morning," at +15% speed are ~200ms themselves, so words
            //     immediately after them (e.g. "thanks for calling") still land
            //     in the dead zone. 400ms gives headroom for any speed/network
            //     variance.
            //   - Trailing break: Telnyx fires the next call command the moment
            //     Azure reports speech.ended, cutting off the final phoneme.
            $rate = config('services.telnyx.tts_rate', '+10%');
            return '<speak version="1.0" xml:lang="en-US">'
                . '<break time="400ms"/>'
                . '<prosody rate="' . $rate . '">'
                . $escaped
                . '</prosody>'
                . '<break time="400ms"/>'
                . '</speak>';
        }

        // Trailing space gives non-Azure engines an extra tail buffer so the
        // final phoneme isn't clipped at the speak → next-command boundary.
        return $text . ' ';
    }

    /**
     * Build a publicly reachable base URL for Telnyx webhooks.
     *
     * In dev environments where APP_URL is localhost, Telnyx cannot reach the
     * webhook endpoint.  When TELNYX_PUBLIC_URL (e.g. a Cloudflare tunnel) is
     * set, we use that instead.
     */
    protected function telnyxBaseUrl(): string
    {
        $appUrl = config('app.url');

        if (str_contains($appUrl, '127.0.0.1') || str_contains($appUrl, 'localhost')) {
            $publicUrl = config('services.telnyx.public_url');

            if ($publicUrl) {
                return rtrim($publicUrl, '/');
            }
        }

        return rtrim($appUrl, '/');
    }
}
