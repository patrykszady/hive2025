<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Telnyx Voice Service
 *
 * Handles outbound call creation and call control for:
 * - Inbound call forwarding (preserving homeowner caller ID)
 * - Click-to-call (two-leg calls with bridge)
 *
 * Telnyx uses a webhook-driven "Call Control" model where you receive
 * webhooks for call events and respond with commands via API calls.
 */
class TelnyxVoiceService
{
    protected string $apiKey;

    protected string $connectionId;

    protected string $fromNumber;

    protected string $baseUrl = 'https://api.telnyx.com/v2';

    public function __construct()
    {
        $this->apiKey = config('services.telnyx.api_key', '');
        $this->connectionId = config('services.telnyx.connection_id', '');
        $this->fromNumber = config('services.telnyx.from', '');
    }

    /**
     * Create an outbound call via Telnyx Voice API.
     *
     * @param  string  $to  Destination phone number (E.164)
     * @param  string|null  $from  Override caller ID (E.164)
     * @param  string|null  $clientState  Base64-encoded state to pass through webhooks
     * @return array Response with call_control_id
     *
     * @throws \Exception
     */
    public function createCall(
        string $to,
        ?string $from = null,
        ?string $clientState = null
    ): array {
        $to = $this->normalizePhone($to);
        $from = $from ? $this->normalizePhone($from) : $this->fromNumber;

        $payload = [
            'to' => $to,
            'from' => $from,
            'connection_id' => $this->connectionId,
        ];

        if ($clientState) {
            // Telnyx requires client_state to be base64 encoded
            $payload['client_state'] = base64_encode($clientState);
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(30)
                ->post("{$this->baseUrl}/calls", $payload);

            if (! $response->successful()) {
                Log::channel('telnyx')->error('Failed to create outbound call', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'to' => $to,
                ]);
                throw new \Exception('Failed to create call: ' . $response->body());
            }

            $data = $response->json('data');

            Log::channel('telnyx')->info('Outbound call created', [
                'call_control_id' => $data['call_control_id'] ?? null,
                'call_leg_id' => $data['call_leg_id'] ?? null,
                'to' => $to,
                'from' => $from,
            ]);

            return $data;
        } catch (\Exception $e) {
            Log::channel('telnyx')->error('Exception creating outbound call', [
                'error' => $e->getMessage(),
                'to' => $to,
            ]);
            throw $e;
        }
    }

    /**
     * Answer an incoming call.
     *
     * @param  string  $callControlId  The call_control_id from the webhook
     * @return array Response data
     */
    public function answerCall(string $callControlId): array
    {
        return $this->sendCallCommand($callControlId, 'answer');
    }

    /**
     * Hang up a call.
     *
     * @param  string  $callControlId  The call_control_id from the webhook
     * @return array Response data
     */
    public function hangupCall(string $callControlId): array
    {
        return $this->sendCallCommand($callControlId, 'hangup');
    }

    /**
     * Transfer/bridge a call to another number.
     *
     * Used for inbound call forwarding. To preserve the original caller ID
     * on the forwarded leg (so staff sees homeowner's number), we use
     * the 'from' parameter set to the original caller.
     *
     * @param  string  $callControlId  The call_control_id from the webhook
     * @param  string  $to  Destination phone number to transfer to
     * @param  string|null  $callerIdFrom  Caller ID to display (original caller for forwarding)
     * @param  int  $timeout  Ring timeout in seconds
     * @return array Response data
     */
    public function transferCall(
        string $callControlId,
        string $to,
        ?string $callerIdFrom = null,
        int $timeout = 30
    ): array {
        $payload = [
            'to' => $this->normalizePhone($to),
            'timeout_secs' => $timeout,
        ];

        // Preserve original caller ID for inbound forwarding
        if ($callerIdFrom) {
            $payload['from'] = $this->normalizePhone($callerIdFrom);
        }

        return $this->sendCallCommand($callControlId, 'transfer', $payload);
    }

    /**
     * Bridge two call legs together.
     *
     * @param  string  $callControlId  First call's call_control_id
     * @param  string  $targetCallControlId  Second call's call_control_id to bridge with
     * @return array Response data
     */
    public function bridgeCalls(string $callControlId, string $targetCallControlId): array
    {
        return $this->sendCallCommand($callControlId, 'bridge', [
            'call_control_id' => $targetCallControlId,
        ]);
    }

    /**
     * Speak text on a call using TTS.
     *
     * @param  string  $callControlId  The call_control_id from the webhook
     * @param  string  $text  Text to speak
     * @param  string  $voice  Voice to use (e.g., 'female', 'male')
     * @return array Response data
     */
    public function speakText(string $callControlId, string $text, string $voice = 'female'): array
    {
        return $this->sendCallCommand($callControlId, 'speak', [
            'payload' => $text,
            'voice' => $voice,
            'language' => 'en-US',
        ]);
    }

    /**
     * Start recording a call.
     *
     * @param  string  $callControlId  The call_control_id from the webhook
     * @param  string  $format  Recording format (mp3 or wav)
     * @param  string  $channels  'single' or 'dual' track
     * @return array Response data
     */
    public function startRecording(
        string $callControlId,
        string $format = 'mp3',
        string $channels = 'single'
    ): array {
        return $this->sendCallCommand($callControlId, 'record_start', [
            'format' => $format,
            'channels' => $channels,
        ]);
    }

    /**
     * Stop recording a call.
     *
     * @param  string  $callControlId  The call_control_id from the webhook
     * @return array Response data
     */
    public function stopRecording(string $callControlId): array
    {
        return $this->sendCallCommand($callControlId, 'record_stop');
    }

    /**
     * Initiate a click-to-call (two-leg call).
     *
     * This calls the staff member first. When they answer, you bridge to the customer.
     *
     * @param  string  $staffPhone  Staff member's phone to call first (E.164)
     * @param  string  $customerPhone  Customer's phone to call second (E.164)
     * @param  string|null  $customerName  Customer name for whisper announcement
     * @param  string|null  $projectName  Project name for context
     * @return array Response with call_control_id for the staff leg
     */
    public function initiateClickToCall(
        string $staffPhone,
        string $customerPhone,
        ?string $customerName = null,
        ?string $projectName = null
    ): array {
        // Build client_state with customer info for the answer callback
        $clientState = json_encode([
            'type' => 'click_to_call',
            'customer_phone' => $this->normalizePhone($customerPhone),
            'customer_name' => $customerName ?? 'Customer',
            'project_name' => $projectName,
        ]);

        return $this->createCall(
            to: $staffPhone,
            from: $this->fromNumber,
            clientState: $clientState
        );
    }

    /**
     * Get the forwarding phone numbers from config.
     *
     * @return array<string> Phone numbers in E.164 format
     */
    public function getForwardingNumbers(): array
    {
        $forwardTo = config('services.telnyx.voice_forward_to', '');

        if (empty($forwardTo)) {
            return [];
        }

        return collect(explode(',', $forwardTo))
            ->map(fn ($phone) => $this->normalizePhone(trim($phone)))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Send a call control command to Telnyx.
     *
     * @param  string  $callControlId  The call_control_id
     * @param  string  $command  Command name (answer, hangup, transfer, etc.)
     * @param  array  $payload  Additional command parameters
     * @return array Response data
     */
    protected function sendCallCommand(string $callControlId, string $command, array $payload = []): array
    {
        $url = "{$this->baseUrl}/calls/{$callControlId}/actions/{$command}";

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(30)
                ->post($url, $payload);

            if (! $response->successful()) {
                Log::channel('telnyx')->error("Call command {$command} failed", [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'call_control_id' => $callControlId,
                ]);
                throw new \Exception("Failed to execute {$command}: " . $response->body());
            }

            $data = $response->json('data') ?? [];

            Log::channel('telnyx')->debug("Call command {$command} executed", [
                'call_control_id' => $callControlId,
                'result' => $data['result'] ?? 'ok',
            ]);

            return $data;
        } catch (\Exception $e) {
            Log::channel('telnyx')->error("Exception executing {$command}", [
                'error' => $e->getMessage(),
                'call_control_id' => $callControlId,
            ]);
            throw $e;
        }
    }

    /**
     * Normalize phone number to E.164 format.
     */
    public function normalizePhone(string $phone): string
    {
        // Remove any non-digit characters except leading +
        $phone = preg_replace('/[^\d+]/', '', $phone);

        // If no country code, assume US (+1)
        if (! str_starts_with($phone, '+')) {
            // Remove leading 1 if present, then add +1
            $phone = '+1' . ltrim($phone, '1');
        }

        return $phone;
    }

    /**
     * Get the configured from number.
     */
    public function getFromNumber(): string
    {
        return $this->fromNumber;
    }

    /**
     * Check if Telnyx Voice is properly configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->apiKey)
            && ! empty($this->connectionId)
            && ! empty($this->fromNumber);
    }

    /**
     * Decode client_state from webhook payload.
     *
     * @param  string|null  $clientState  Base64-encoded client state
     * @return array|null Decoded state or null
     */
    public function decodeClientState(?string $clientState): ?array
    {
        if (empty($clientState)) {
            return null;
        }

        $decoded = base64_decode($clientState, true);
        if ($decoded === false) {
            return null;
        }

        return json_decode($decoded, true);
    }
}
