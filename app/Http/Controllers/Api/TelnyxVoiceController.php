<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CallLog;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Services\TelnyxVoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Telnyx Voice Webhook Controller
 *
 * Handles all voice webhooks from Telnyx and provides
 * an API endpoint for initiating click-to-call from the Hive UI.
 *
 * Telnyx uses a webhook-driven "Call Control" model:
 * - Webhooks send event data (call.initiated, call.answered, call.hangup, etc.)
 * - You respond with commands via API calls, not by returning XML
 * - Each call has a call_control_id used to send commands
 */
class TelnyxVoiceController extends Controller
{
    public function __construct(
        protected TelnyxVoiceService $voiceService
    ) {}

    /**
     * Handle all Telnyx voice webhooks.
     *
     * Telnyx sends various event types to a single webhook URL.
     * We dispatch based on event_type.
     */
    public function handleWebhook(Request $request): Response
    {
        $payload = $request->all();
        $data = $payload['data'] ?? $payload;
        $eventType = $data['event_type'] ?? 'unknown';

        Log::channel('telnyx')->debug('Voice webhook received', [
            'event_type' => $eventType,
            'call_control_id' => $data['payload']['call_control_id'] ?? null,
        ]);

        return match ($eventType) {
            'call.initiated' => $this->handleCallInitiated($data),
            'call.answered' => $this->handleCallAnswered($data),
            'call.hangup' => $this->handleCallHangup($data),
            'call.speak.ended' => $this->handleSpeakEnded($data),
            'call.bridged' => $this->handleCallBridged($data),
            'call.recording.saved' => $this->handleRecordingSaved($data),
            default => $this->handleUnknownEvent($eventType, $data),
        };
    }

    /**
     * Handle call.initiated event.
     *
     * Fires when a call is started (inbound or outbound).
     * For inbound calls, we answer and forward to staff.
     */
    protected function handleCallInitiated(array $data): Response
    {
        $eventPayload = $data['payload'] ?? [];
        $direction = $eventPayload['direction'] ?? 'unknown';
        $callControlId = $eventPayload['call_control_id'] ?? '';
        $from = $eventPayload['from'] ?? '';
        $to = $eventPayload['to'] ?? '';

        Log::channel('telnyx')->info('Call initiated', [
            'call_control_id' => $callControlId,
            'direction' => $direction,
            'from' => $from,
            'to' => $to,
        ]);

        // Log the call
        $this->logCall($eventPayload, $direction === 'incoming' ? CallLog::DIRECTION_INBOUND : CallLog::DIRECTION_OUTBOUND, CallLog::STATUS_RINGING);

        // For inbound calls, answer and forward
        if ($direction === 'incoming') {
            try {
                // Answer the call first
                $this->voiceService->answerCall($callControlId);
            } catch (\Exception $e) {
                Log::channel('telnyx')->error('Failed to answer inbound call', [
                    'error' => $e->getMessage(),
                    'call_control_id' => $callControlId,
                ]);
            }
        }

        return response('', 200);
    }

    /**
     * Handle call.answered event.
     *
     * Fires when a call is answered.
     * For inbound: transfer to staff, preserving original caller ID.
     * For click-to-call staff leg: speak whisper, then dial customer.
     */
    protected function handleCallAnswered(array $data): Response
    {
        $eventPayload = $data['payload'] ?? [];
        $callControlId = $eventPayload['call_control_id'] ?? '';
        $direction = $eventPayload['direction'] ?? 'unknown';
        $from = $eventPayload['from'] ?? '';
        $clientState = $this->voiceService->decodeClientState($eventPayload['client_state'] ?? null);

        Log::channel('telnyx')->info('Call answered', [
            'call_control_id' => $callControlId,
            'direction' => $direction,
            'from' => $from,
            'client_state_type' => $clientState['type'] ?? 'none',
        ]);

        try {
            // Check if this is a click-to-call staff leg
            if ($clientState && ($clientState['type'] ?? '') === 'click_to_call') {
                $this->handleClickToCallStaffAnswered($callControlId, $clientState);

                return response('', 200);
            }

            // For inbound calls, forward to staff with original caller ID preserved
            if ($direction === 'incoming') {
                $forwardTo = $this->voiceService->getForwardingNumbers();

                if (empty($forwardTo)) {
                    Log::channel('telnyx')->warning('No forwarding numbers configured');
                    // Speak a message and hang up
                    $this->voiceService->speakText(
                        $callControlId,
                        'Sorry, no one is available to take your call. Please try again later.'
                    );

                    return response('', 200);
                }

                // Transfer to first forwarding number, preserving the original caller ID
                // so staff sees the homeowner's number on their phone
                $this->voiceService->transferCall(
                    callControlId: $callControlId,
                    to: $forwardTo[0],
                    callerIdFrom: $from, // Preserve original caller's number
                    timeout: 30
                );
            }
        } catch (\Exception $e) {
            Log::channel('telnyx')->error('Failed to handle answered call', [
                'error' => $e->getMessage(),
                'call_control_id' => $callControlId,
            ]);
        }

        return response('', 200);
    }

    /**
     * Handle click-to-call when staff answers.
     *
     * Play a whisper, then dial the customer.
     */
    protected function handleClickToCallStaffAnswered(string $callControlId, array $clientState): void
    {
        $customerPhone = $clientState['customer_phone'] ?? null;
        $customerName = $clientState['customer_name'] ?? 'Customer';
        $projectName = $clientState['project_name'] ?? null;

        if (! $customerPhone) {
            Log::channel('telnyx')->error('Click-to-call missing customer phone');
            $this->voiceService->hangupCall($callControlId);

            return;
        }

        // Build whisper message
        $whisper = "Connecting you to {$customerName}";
        if ($projectName) {
            $whisper .= " regarding {$projectName}";
        }
        $whisper .= '. Please hold.';

        // Speak the whisper, then we'll transfer on speak.ended event
        // Store the customer phone in client_state for the next event
        Log::channel('telnyx')->info('Playing click-to-call whisper', [
            'call_control_id' => $callControlId,
            'customer_phone' => $customerPhone,
        ]);

        $this->voiceService->speakText($callControlId, $whisper);

        // Store state for when speak ends - we'll need to look this up
        // In a real implementation, you'd store this in cache/DB
        // For now, we'll transfer immediately after speak (Telnyx queues commands)
        $this->voiceService->transferCall(
            callControlId: $callControlId,
            to: $customerPhone,
            callerIdFrom: $this->voiceService->getFromNumber(), // Show business number to customer
            timeout: 45
        );
    }

    /**
     * Handle call.hangup event.
     *
     * Fires when a call ends. Good for logging/cleanup.
     */
    protected function handleCallHangup(array $data): Response
    {
        $eventPayload = $data['payload'] ?? [];

        Log::channel('telnyx')->info('Call hangup', [
            'call_control_id' => $eventPayload['call_control_id'] ?? null,
            'hangup_cause' => $eventPayload['hangup_cause'] ?? null,
            'hangup_source' => $eventPayload['hangup_source'] ?? null,
            'from' => $eventPayload['from'] ?? null,
            'to' => $eventPayload['to'] ?? null,
        ]);

        $this->updateCallLogOnDisconnect($eventPayload);

        return response('', 200);
    }

    /**
     * Handle call.speak.ended event.
     */
    protected function handleSpeakEnded(array $data): Response
    {
        Log::channel('telnyx')->debug('Speak ended', [
            'call_control_id' => $data['payload']['call_control_id'] ?? null,
        ]);

        return response('', 200);
    }

    /**
     * Handle call.bridged event.
     */
    protected function handleCallBridged(array $data): Response
    {
        Log::channel('telnyx')->info('Call bridged', [
            'call_control_id' => $data['payload']['call_control_id'] ?? null,
        ]);

        return response('', 200);
    }

    /**
     * Handle call.recording.saved event.
     */
    protected function handleRecordingSaved(array $data): Response
    {
        $eventPayload = $data['payload'] ?? [];

        Log::channel('telnyx')->info('Recording saved', [
            'call_control_id' => $eventPayload['call_control_id'] ?? null,
            'recording_url' => $eventPayload['recording_urls']['mp3'] ?? null,
        ]);

        $this->updateCallLogWithRecording($eventPayload);

        return response('', 200);
    }

    /**
     * Handle unknown event types.
     */
    protected function handleUnknownEvent(string $eventType, array $data): Response
    {
        Log::channel('telnyx')->debug('Unhandled voice event', [
            'event_type' => $eventType,
        ]);

        return response('', 200);
    }

    /**
     * API: Initiate a click-to-call from Hive UI.
     *
     * Staff clicks "Call" button in Hive, this endpoint:
     * 1. Calls the staff member's phone
     * 2. When they answer, plays a whisper
     * 3. Transfers to the customer
     */
    public function initiateClickToCall(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'staff_phone' => 'required|string',
            'customer_phone' => 'required|string',
            'customer_name' => 'nullable|string|max:255',
            'project_id' => 'nullable|integer|exists:projects,id',
            'client_id' => 'nullable|integer|exists:clients,id',
            'contact_user_id' => 'nullable|integer|exists:users,id',
        ]);

        // Resolve customer name from related models if not provided
        $customerName = $validated['customer_name'] ?? null;
        $projectName = null;

        if (! $customerName && ! empty($validated['contact_user_id'])) {
            $contactUser = User::find($validated['contact_user_id']);
            $customerName = $contactUser?->name;
        }

        if (! $customerName && ! empty($validated['client_id'])) {
            $client = Client::find($validated['client_id']);
            $customerName = $client?->name;
        }

        if (! empty($validated['project_id'])) {
            $project = Project::find($validated['project_id']);
            $projectName = $project?->name;
        }

        try {
            $result = $this->voiceService->initiateClickToCall(
                staffPhone: $validated['staff_phone'],
                customerPhone: $validated['customer_phone'],
                customerName: $customerName,
                projectName: $projectName
            );

            // Log the click-to-call
            CallLog::create([
                'call_id' => $result['call_control_id'] ?? uniqid('ctc_'),
                'direction' => CallLog::DIRECTION_CLICK_TO_CALL,
                'status' => CallLog::STATUS_INITIATED,
                'from_number' => $this->voiceService->getFromNumber(),
                'to_number' => $validated['customer_phone'],
                'caller_name' => $customerName,
                'user_id' => auth()->id(),
                'project_id' => $validated['project_id'] ?? null,
                'client_id' => $validated['client_id'] ?? null,
                'contact_user_id' => $validated['contact_user_id'] ?? null,
                'metadata' => [
                    'staff_phone' => $validated['staff_phone'],
                    'project_name' => $projectName,
                    'call_control_id' => $result['call_control_id'] ?? null,
                    'call_leg_id' => $result['call_leg_id'] ?? null,
                ],
            ]);

            return response()->json([
                'success' => true,
                'call_control_id' => $result['call_control_id'] ?? null,
                'message' => 'Call initiated. Your phone will ring shortly.',
            ]);
        } catch (\Exception $e) {
            Log::channel('telnyx')->error('Click-to-call initiation failed', [
                'error' => $e->getMessage(),
                'staff_phone' => $validated['staff_phone'],
                'customer_phone' => $validated['customer_phone'],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to initiate call: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Log a call to the database.
     */
    protected function logCall(array $payload, string $direction, string $status): CallLog
    {
        return CallLog::updateOrCreate(
            ['call_id' => $payload['call_control_id'] ?? uniqid('call_')],
            [
                'direction' => $direction,
                'status' => $status,
                'from_number' => $payload['from'] ?? '',
                'to_number' => $payload['to'] ?? '',
                'metadata' => $payload,
            ]
        );
    }

    /**
     * Update call log when call disconnects.
     */
    protected function updateCallLogOnDisconnect(array $payload): void
    {
        $callControlId = $payload['call_control_id'] ?? null;
        if (! $callControlId) {
            return;
        }

        $callLog = CallLog::where('call_id', $callControlId)->first();
        if (! $callLog) {
            return;
        }

        // Calculate duration from start_time and end_time if available
        $duration = null;
        if (! empty($payload['start_time']) && ! empty($payload['end_time'])) {
            $start = \Carbon\Carbon::parse($payload['start_time']);
            $end = \Carbon\Carbon::parse($payload['end_time']);
            $duration = $end->diffInSeconds($start);
        }

        $callLog->update([
            'status' => CallLog::STATUS_COMPLETED,
            'duration_seconds' => $duration,
            'disconnect_cause' => $payload['hangup_cause'] ?? null,
            'ended_at' => now(),
        ]);
    }

    /**
     * Update call log with recording info.
     */
    protected function updateCallLogWithRecording(array $payload): void
    {
        $callControlId = $payload['call_control_id'] ?? null;
        if (! $callControlId) {
            return;
        }

        $callLog = CallLog::where('call_id', $callControlId)->first();
        if (! $callLog) {
            return;
        }

        $recordingUrl = $payload['recording_urls']['mp3']
            ?? $payload['recording_urls']['wav']
            ?? null;

        $callLog->update([
            'recording_url' => $recordingUrl,
            'has_voicemail' => true,
        ]);
    }
}
