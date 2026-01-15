<?php

namespace App\Services;

use App\Models\SmsMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Telnyx Messaging Service
 *
 * Handles outbound SMS/MMS sending via Telnyx Messaging API.
 * Supports single and group messaging (multiple recipients in one API call).
 */
class TelnyxService
{
    protected string $apiKey;

    protected string $messagingProfileId;

    protected string $fromNumber;

    protected string $baseUrl = 'https://api.telnyx.com/v2';

    public function __construct(
        protected ?MicrosoftTeamsService $teams = null
    )
    {
        $this->apiKey = config('services.telnyx.api_key', '');
        $this->messagingProfileId = config('services.telnyx.messaging_profile_id', '');
        $this->fromNumber = config('services.telnyx.from', '');
    }

    /**
     * Send an SMS to one or more recipients.
     *
     * For group SMS (multiple recipients), all recipients will be in the same
     * conversation thread. This is ideal for client notifications where multiple
     * users on a project should see the same message thread.
     *
     * @param  array<string>|string  $to  Phone number(s) in E.164 format (e.g., +15551234567)
     * @param  string  $text  Message text
     * @param  string|null  $from  Override the default from number
     * @param  array  $mediaUrls  Optional MMS media URLs (images, etc.)
     * @return array Response data from Telnyx
     *
     * @throws \Exception
     */
    public function sendSms(
        array|string $to,
        string $text,
        ?string $from = null,
        array $mediaUrls = [],
        array $context = [],
        bool $mirrorToTeams = true
    ): array
    {
        // Normalize recipients to array format
        $recipients = collect(is_array($to) ? $to : [$to])
            ->map(fn ($phone) => $this->normalizePhone($phone))
            ->values()
            ->all();

        $fromNumber = $from ? $this->normalizePhone($from) : $this->fromNumber;

        $payload = [
            'from' => $fromNumber,
            'to' => count($recipients) === 1 ? $recipients[0] : $recipients,
            'text' => $text,
        ];

        // Add messaging profile ID if configured
        if (! empty($this->messagingProfileId)) {
            $payload['messaging_profile_id'] = $this->messagingProfileId;
        }

        // Add media for MMS if provided
        if (! empty($mediaUrls)) {
            $payload['media_urls'] = $mediaUrls;
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(30)
                ->post("{$this->baseUrl}/messages", $payload);

            if (! $response->successful()) {
                Log::channel('telnyx')->error('SMS send failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'to_count' => count($recipients),
                ]);
                throw new \Exception('Failed to send SMS: ' . $response->body());
            }

            $data = $response->json('data');

            Log::channel('telnyx')->info('SMS sent successfully', [
                'message_id' => $data['id'] ?? null,
                'to_count' => count($recipients),
                'is_group' => count($recipients) > 1,
                'has_media' => ! empty($mediaUrls),
            ]);

            $result = [
                'id' => $data['id'] ?? null,
                'to' => $data['to'] ?? $recipients,
                'from' => $data['from']['phone_number'] ?? $fromNumber,
                'text' => $data['text'] ?? $text,
                'media' => $data['media'] ?? [],
                'parts' => $data['parts'] ?? 1,
                'cost' => $data['cost'] ?? null,
            ];

            SmsMessage::query()->create([
                'provider' => 'telnyx',
                'provider_message_id' => data_get($result, 'id'),
                'direction' => 'outbound',
                'from_number' => $fromNumber,
                'to_numbers' => $recipients,
                'text' => $text,
                'raw_payload' => [
                    'request' => $payload,
                    'response' => $result,
                    'context' => $context,
                ],
            ]);

            if ($mirrorToTeams && $this->teams) {
                $toLabel = implode(', ', $recipients);
                $this->teams->postText("Outbound SMS to {$toLabel}: {$text}");
            }

            return $result;
        } catch (\Exception $e) {
            Log::channel('telnyx')->error('SMS exception', [
                'error' => $e->getMessage(),
                'to_count' => count($recipients),
            ]);

            throw $e;
        }
    }

    /**
     * Send a group MMS with media attachments.
     *
     * @param  array<string>  $to  Phone numbers (must be array for group MMS)
     * @param  string  $text  Message text
     * @param  array  $mediaUrls  URLs to media files (images, etc.)
     * @param  string|null  $from  Override the default from number
     * @return array Response data
     *
     * @throws \Exception
     */
    public function sendGroupMms(array $to, string $text, array $mediaUrls = [], ?string $from = null): array
    {
        return $this->sendSms($to, $text, $from, $mediaUrls);
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
     * Check if Telnyx is properly configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->apiKey)
            && ! empty($this->fromNumber);
    }

    /**
     * Parse an inbound message webhook payload.
     *
     * @param  array  $payload  The webhook payload from Telnyx
     * @return array Normalized inbound message data
     */
    public function parseInboundMessage(array $payload): array
    {
        $data = $payload['data'] ?? $payload;
        $eventPayload = $data['payload'] ?? $data;

        return [
            'id' => $eventPayload['id'] ?? null,
            'from' => $eventPayload['from']['phone_number'] ?? null,
            'to' => $eventPayload['to'][0]['phone_number'] ?? $eventPayload['to'] ?? null,
            'text' => $eventPayload['text'] ?? '',
            'media' => $eventPayload['media'] ?? [],
            'direction' => $eventPayload['direction'] ?? 'inbound',
            'received_at' => $eventPayload['received_at'] ?? now()->toIso8601String(),
        ];
    }
}
