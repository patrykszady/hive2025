<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\SmsMessage;
use App\Models\User;
use App\Models\Vendor;
use App\Services\MicrosoftTeamsService;
use App\Services\TelnyxService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Telnyx Messaging Webhook Controller
 *
 * Handles inbound SMS/MMS webhooks from Telnyx.
 * When someone replies to a text, this receives and stores the message.
 */
class TelnyxMessagingController extends Controller
{
    public function __construct(
        protected TelnyxService $messagingService,
        protected MicrosoftTeamsService $teams
    ) {}

    /**
     * Handle Telnyx messaging webhooks.
     *
     * Event types:
     * - message.received: Inbound SMS/MMS received
     * - message.sent: Outbound message sent (delivery receipt)
     * - message.finalized: Message processing complete
     */
    public function handleWebhook(Request $request): Response
    {
        $payload = $request->all();
        $data = $payload['data'] ?? $payload;
        $eventType = $data['event_type'] ?? 'unknown';

        Log::channel('telnyx')->debug('Messaging webhook received', [
            'event_type' => $eventType,
            'delivery_id' => $data['id'] ?? null,
        ]);

        return match ($eventType) {
            'message.received' => $this->handleMessageReceived($data),
            'message.sent' => $this->handleMessageSent($data),
            'message.finalized' => $this->handleMessageFinalized($data),
            default => $this->handleUnknownEvent($eventType, $data),
        };
    }

    /**
     * Handle an inbound SMS/MMS message.
     */
    protected function handleMessageReceived(array $data): Response
    {
        $parsed = $this->messagingService->parseInboundMessage($data);

        $from = $parsed['from'] ?? null;
        $to = $parsed['to'] ?? null;
        $text = $parsed['text'] ?? '';
        $keyword = strtoupper(trim((string) strtok(trim($text), " \t\n\r\0\x0B")));

        $recipientInfo = $this->resolveRecipient($from);
        $recipientName = $recipientInfo['name'];
        $scheduleUrl = $recipientInfo['url'];
        $supportNumber = (string) config('services.telnyx.from');

        Log::channel('telnyx')->info('Inbound SMS received', [
            'from' => $from,
            'to' => $to,
            'text_preview' => substr($text, 0, 50),
            'has_media' => ! empty($parsed['media']),
        ]);

        SmsMessage::query()->create([
            'provider' => 'telnyx',
            'provider_message_id' => $parsed['id'] ?? null,
            'direction' => 'inbound',
            'from_number' => $from,
            'to_numbers' => $to ? [$to] : null,
            'text' => $text,
            'raw_payload' => $data,
        ]);

        if ($from && $text !== '') {
            $this->teams->postText("Inbound SMS from {$from}: {$text}");
        }

        try {
            if ($from && in_array($keyword, ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'], true)) {
                $defaultStopReply = sprintf(
                    "%s, you will NOT receive project text updates. View your schedule at %s and enable browser notifications. Reply START to re-enable.",
                    $recipientName,
                    $scheduleUrl
                );
                $stopReply = (string) (config('services.telnyx.auto_replies.stop') ?: $defaultStopReply);

                $this->messagingService->sendSms(
                    to: $from,
                    text: $stopReply,
                    from: $to
                );

                Log::channel('telnyx')->info('Processed inbound opt-out keyword', [
                    'from' => $from,
                    'to' => $to,
                    'keyword' => $keyword,
                ]);

                return response('', 200);
            }

            if ($from && in_array($keyword, ['START', 'UNSTOP', 'YES'], true)) {
                $defaultStartReply = sprintf(
                    "%s, you agree to receive project updates. Msg & data rates may apply. STOP to opt out, HELP for help.",
                    $recipientName
                );
                $startReply = (string) (config('services.telnyx.auto_replies.start') ?: $defaultStartReply);

                $this->messagingService->sendSms(
                    to: $from,
                    text: $startReply,
                    from: $to
                );

                Log::channel('telnyx')->info('Processed inbound re-subscribe keyword', [
                    'from' => $from,
                    'to' => $to,
                    'keyword' => $keyword,
                ]);

                return response('', 200);
            }

            if ($from && $keyword === 'HELP') {
                $defaultHelpReply = sprintf(
                    "%s please call or text us at %s so we can help you with your project schedule notifications.",
                    $recipientName,
                    $supportNumber
                );
                $helpText = (string) (config('services.telnyx.auto_replies.help') ?: $defaultHelpReply);

                $this->messagingService->sendSms(
                    to: $from,
                    text: $helpText,
                    from: $to
                );

                Log::channel('telnyx')->info('Processed inbound help keyword', [
                    'from' => $from,
                    'to' => $to,
                ]);

                return response('', 200);
            }
        } catch (\Throwable $e) {
            Log::channel('telnyx')->error('Failed handling inbound SMS keyword', [
                'error' => $e->getMessage(),
                'from' => $from,
                'to' => $to,
                'keyword' => $keyword,
            ]);

            return response('', 200);
        }

        return response('', 200);
    }

    /**
     * Resolve the recipient's name and schedule URL based on their phone number.
     *
     * Searches User (team), Vendor, then Client users to find a match.
     *
     * @return array{name: string, url: string}
     */
    private function resolveRecipient(?string $from): array
    {
        $baseUrl = (string) (config('app.dev_webhook_url') ?: config('app.url'));
        $fallback = [
            'name' => (string) config('app.name'),
            'url' => $baseUrl,
        ];

        if (! $from) {
            return $fallback;
        }

        $normalized = $this->messagingService->normalizePhone($from);
        $digits = preg_replace('/\D/', '', $normalized);

        // 1. Check if it's a team member (User with cell_phone)
        $user = User::query()
            ->where('cell_phone', 'LIKE', "%{$digits}%")
            ->first();

        if ($user) {
            return [
                'name' => $user->first_name ?: $fallback['name'],
                'url' => $baseUrl . '/dashboard',
            ];
        }

        // 2. Check if it's a vendor (business_phone)
        $vendor = Vendor::query()
            ->where('business_phone', 'LIKE', "%{$digits}%")
            ->first();

        if ($vendor) {
            $token = $vendor->getOrCreateAvailabilityToken();

            return [
                'name' => $vendor->short_name ?: $vendor->business_name ?: $fallback['name'],
                'url' => $baseUrl . '/v/' . $token,
            ];
        }

        // 3. Check if it's a client user (via client->users relationship)
        //    Client users are stored in the users table with cell_phone, linked via client_user pivot.
        //    We already checked users above, so now check if that user belongs to a client.
        $clientUser = User::query()
            ->whereHas('clients')
            ->where('cell_phone', 'LIKE', "%{$digits}%")
            ->with('clients.projects')
            ->first();

        if ($clientUser) {
            // Try to get the client's most recent project schedule token
            $client = $clientUser->clients->first();
            $project = $client?->projects()->latest()->first();
            $token = $project?->getOrCreateScheduleToken();

            return [
                'name' => $clientUser->first_name ?: $fallback['name'],
                'url' => $token ? ($baseUrl . '/s/' . $token) : $baseUrl,
            ];
        }

        return $fallback;
    }

    /**
     * Handle message.sent event (delivery receipt).
     */
    protected function handleMessageSent(array $data): Response
    {
        $deliveryId = $data['id'] ?? null;
        $payload = $data['payload'] ?? [];
        $errors = $payload['errors'] ?? [];

        $from = $payload['from']['phone_number'] ?? null;
        $to = $payload['to'] ?? null;
        $text = $payload['text'] ?? null;

        if ($text !== null) {
            $toNumbers = [];
            if (is_array($to)) {
                foreach ($to as $recipient) {
                    $toNumbers[] = $recipient['phone_number'] ?? $recipient;
                }
            } elseif (is_string($to) && $to !== '') {
                $toNumbers = [$to];
            }

            $providerMessageId = $payload['id'] ?? $deliveryId;
            if ($providerMessageId) {
                SmsMessage::query()->updateOrCreate(
                    [
                        'provider' => 'telnyx',
                        'provider_message_id' => $providerMessageId,
                    ],
                    [
                        'direction' => 'outbound',
                        'from_number' => $from,
                        'to_numbers' => $toNumbers ?: null,
                        'text' => $text,
                        'raw_payload' => $data,
                    ]
                );
            }
        }

        if (! empty($errors)) {
            Log::channel('telnyx')->error('SMS sending error', [
                'delivery_id' => $deliveryId,
                'payload' => $payload,
            ]);
        } else {
            Log::channel('telnyx')->info('Outbound SMS sent', [
                'delivery_id' => $deliveryId,
                'payload' => $payload,
            ]);
        }

        return response('', 200);
    }

    /**
     * Handle message.finalized event.
     */
    protected function handleMessageFinalized(array $data): Response
    {
        $deliveryId = $data['id'] ?? null;
        $payload = $data['payload'] ?? [];
        $status = $payload['to'][0]['status'] ?? 'unknown';
        $errors = $payload['errors'] ?? [];

        if ($status === 'delivery_failed' || ! empty($errors)) {
            Log::channel('telnyx')->error('SMS delivery failed', [
                'delivery_id' => $deliveryId,
                'payload' => $payload,
            ]);
        } else {
            Log::channel('telnyx')->debug('Message finalized', [
                'delivery_id' => $deliveryId,
                'payload' => $payload,
            ]);
        }

        return response('', 200);
    }

    /**
     * Handle unknown event types.
     */
    protected function handleUnknownEvent(string $eventType, array $data): Response
    {
        Log::channel('telnyx')->debug('Unhandled messaging event', [
            'event_type' => $eventType,
        ]);

        return response('', 200);
    }

    /**
     * API: Send a reply message from the UI.
     */
    public function sendReply(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'to' => 'required|string',
            'text' => 'required|string|max:1600',
            'media_urls' => 'nullable|array',
            'media_urls.*' => 'url',
        ]);

        try {
            $result = $this->messagingService->sendSms(
                to: $validated['to'],
                text: $validated['text'],
                mediaUrls: $validated['media_urls'] ?? []
            );

            return response()->json([
                'success' => true,
                'external_id' => $result['id'] ?? null,
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            Log::channel('telnyx')->error('Failed to send reply', [
                'error' => $e->getMessage(),
                'to' => $validated['to'],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send message: ' . $e->getMessage(),
            ], 500);
        }
    }
}
