<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendInboundSmsBrowserNotifications;
use App\Jobs\StoreSmsMedia;
use App\Models\CallLog;
use App\Models\SmsGroupThread;
use App\Models\SmsLog;
use App\Models\SmsMessage;
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

        return match ($eventType) {
            'call.initiated' => $this->handleCallInitiated($data),
            'call.answered' => $this->handleCallAnswered($data),
            'call.hangup' => $this->handleCallHangup($data),
            'call.bridged' => $this->handleCallBridged($data),
            'call.recording.saved' => $this->handleCallRecordingSaved($data),
            'call.machine.detection.ended' => $this->handleAmdEnded($data),
            default => $this->handleUnknownVoiceEvent($eventType, $data),
        };
    }

    // =========================================================================
    // Voice Call Control Handlers
    // =========================================================================

    /**
     * Handle call.initiated - answer incoming calls and transfer to forwarding number(s).
     * Also handles the first leg of outbound click-to-call.
     */
    protected function handleCallInitiated(array $data): JsonResponse
    {
        $payload = $data['payload'] ?? [];
        $direction = $payload['direction'] ?? null;
        $callControlId = $payload['call_control_id'] ?? null;

        // For outbound click-to-call, the direction is "outgoing" and we already
        // created the CallLog in the Livewire component. Just log and let it ring.
        if ($direction === 'outgoing') {
            Log::channel('telnyx')->info('Outbound call initiated (click-to-call leg)', [
                'call_control_id' => $callControlId,
                'to' => $payload['to'] ?? null,
            ]);
            return response()->json(['status' => 'ok']);
        }

        if ($direction !== 'incoming' || !$callControlId) {
            return response()->json(['status' => 'ok']);
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
        ]);

        // Try to match caller to a user
        $user = $callLog->lookUpCaller();
        if ($user) {
            $callLog->update(['user_id' => $user->id]);
        }

        Log::channel('telnyx')->info('Incoming call - answering', [
            'call_control_id' => $callControlId,
            'from' => $payload['from'] ?? null,
            'to' => $payload['to'] ?? null,
            'caller_user_id' => $user?->id,
        ]);

        // Answer the call, then we'll transfer on call.answered
        $this->sendCallCommand($callControlId, 'answer', [
            'client_state' => base64_encode(json_encode([
                'action' => 'transfer_after_answer',
                'call_log_id' => $callLog->id,
                'original_caller' => $payload['from'] ?? null,
            ])),
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle call.answered - once answered, transfer to the forwarding number(s)
     * or bridge to the click-to-call target.
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

        // ── Click-to-call: user answered their phone → now bridge to target ──
        if ($action === 'click_to_call') {
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

            Log::channel('telnyx')->info('Click-to-call: user answered, bridging to target', [
                'call_control_id' => $callControlId,
                'target_phone' => $targetPhone,
                'call_log_id' => $callLogId,
            ]);

            // Transfer (bridge) to the target number
            $this->sendCallCommand($callControlId, 'transfer', [
                'to' => $targetPhone,
                'from' => config('services.telnyx.from'),
                'timeout_secs' => (int) config('services.telnyx.voice_timeout', 30),
                'client_state' => base64_encode(json_encode([
                    'action' => 'click_to_call_bridged',
                    'call_log_id' => $callLogId,
                ])),
            ]);

            $callLog?->update([
                'status' => CallLog::STATUS_TRANSFERRED,
                'forwarded_to' => $targetPhone,
            ]);

            return response()->json(['status' => 'ok']);
        }

        if ($action !== 'transfer_after_answer') {
            // This is a different leg (e.g. the transfer destination answering)
            return response()->json(['status' => 'ok']);
        }

        $callLogId = $clientState['call_log_id'] ?? null;
        $callLog = $callLogId ? CallLog::find($callLogId) : null;

        if ($callLog) {
            $callLog->update([
                'status' => CallLog::STATUS_ANSWERED,
                'answered_at' => now(),
            ]);
        }

        // Get forwarding destination(s)
        $forwardTo = $this->getForwardingDestination();

        if (!$forwardTo) {
            Log::channel('telnyx')->error('No forwarding destination configured - hanging up');
            $this->sendCallCommand($callControlId, 'hangup');
            $callLog?->update(['status' => CallLog::STATUS_FAILED, 'hangup_cause' => 'no_forward_destination']);
            return response()->json(['status' => 'ok']);
        }

        Log::channel('telnyx')->info('Transferring call', [
            'call_control_id' => $callControlId,
            'forward_to' => $forwardTo,
        ]);

        // Transfer the call — show the original caller's number as caller ID
        $originalCaller = $clientState['original_caller'] ?? config('services.telnyx.from');

        $this->sendCallCommand($callControlId, 'transfer', [
            'to' => $forwardTo,
            'from' => $originalCaller,
            'timeout_secs' => (int) config('services.telnyx.voice_timeout', 30),
            'client_state' => base64_encode(json_encode([
                'action' => 'transferred',
                'call_log_id' => $callLog?->id,
            ])),
        ]);

        $callLog?->update([
            'status' => CallLog::STATUS_TRANSFERRED,
            'forwarded_to' => $forwardTo,
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle call.hangup - call ended.
     */
    protected function handleCallHangup(array $data): JsonResponse
    {
        $payload = $data['payload'] ?? [];
        $callControlId = $payload['call_control_id'] ?? null;
        $hangupCause = $payload['hangup_cause'] ?? null;

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
     * Handle call.recording.saved - recording ready.
     */
    protected function handleCallRecordingSaved(array $data): JsonResponse
    {
        $payload = $data['payload'] ?? [];
        $callControlId = $payload['call_control_id'] ?? null;
        $recordingUrls = $payload['recording_urls'] ?? [];

        $callLog = CallLog::findByCallControlId($callControlId);

        if ($callLog && !empty($recordingUrls)) {
            $callLog->update([
                'recording_url' => $recordingUrls['mp3'] ?? $recordingUrls['wav'] ?? null,
            ]);
        }

        Log::channel('telnyx')->info('Call recording saved', [
            'call_control_id' => $callControlId,
            'recording_urls' => $recordingUrls,
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
     * Get the forwarding destination phone number.
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

            \App\Events\SmsMessageReceived::dispatch($thread->id);
        } else {
            Log::channel('telnyx')->info('No group thread found for inbound message', [
                'from' => $from,
                'to' => $to,
            ]);
        }

        SendInboundSmsBrowserNotifications::dispatch($message->id);

        return response()->json(['status' => 'ok']);
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
}
