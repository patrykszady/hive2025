<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailTracking;
use App\Models\Estimate;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class NylasWebhookController extends Controller
{
    /**
     * Respond to Nylas webhook challenge requests.
     */
    public function verify(Request $request): Response
    {
        $challenge = $request->query('challenge');

        if ($challenge) {
            return response($challenge, 200)
                ->header('Content-Type', 'text/plain');
        }

        return response()->noContent();
    }

    /**
     * Handle incoming webhook events from Nylas.
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        $type = data_get($payload, 'type');
        $data = data_get($payload, 'data');

        if (!$type || !is_array($data)) {
            Log::channel('nylas')->warning('Invalid webhook payload', ['payload' => $payload]);

            return response()->json(['status' => 'ignored']);
        }

        $handlers = [
            'message.opened' => 'handleMessageOpened',
            'message.link_clicked' => 'handleMessageLinkClicked',
            'thread.replied' => 'handleThreadReplied',
            'message.bounced' => 'handleMessageBounced',
            'message.rejected' => 'handleMessageRejected',
        ];

        if (isset($handlers[$type])) {
            $handler = $handlers[$type];
            $this->{$handler}($payload);
        } else {
            Log::channel('nylas')->info('Unhandled webhook type', ['type' => $type]);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Track message opened events.
     */
    protected function handleMessageOpened(array $payload): void
    {
        $data = $payload['data'] ?? [];
        $object = $data['object'] ?? [];

        $messageId = $this->resolveMessageId($object);

        if (!$messageId) {
            Log::channel('nylas')->warning('Missing message_id in message.opened webhook', ['payload' => $payload]);

            return;
        }

        $recipients = $this->resolveRecipientsForMessage($messageId, $object['recipient_email'] ?? null);

        if ($recipients->isEmpty()) {
            Log::channel('nylas')->warning('Unable to resolve recipient for message.opened webhook', [
                'message_id' => $messageId,
                'payload' => $payload,
            ]);

            return;
        }

        $eventDetails = $this->extractEventDetails($data);

        if (($eventDetails['opened_id'] ?? null) === 0 && (int) ($object['message_data']['count'] ?? 0) <= 1) {
            return;
        }

        $metadata = $this->buildMetadata($data, $object, $eventDetails);

        $this->storeTrackingEvents(
            messageId: $messageId,
            eventType: 'opened',
            recipients: $recipients,
            metadata: $metadata,
            eventAt: $eventDetails['timestamp'],
            ip: $eventDetails['ip'],
            userAgent: $eventDetails['user_agent'],
        );
    }

    /**
     * Track link click events.
     */
    protected function handleMessageLinkClicked(array $payload): void
    {
        $data = $payload['data'] ?? [];
        $object = $data['object'] ?? [];

        $messageId = $this->resolveMessageId($object);

        if (!$messageId) {
            Log::channel('nylas')->warning('Missing message_id in message.link_clicked webhook', ['payload' => $payload]);

            return;
        }

        $recipients = $this->resolveRecipientsForMessage($messageId, $object['recipient_email'] ?? null);

        if ($recipients->isEmpty()) {
            Log::channel('nylas')->warning('Unable to resolve recipient for message.link_clicked webhook', [
                'message_id' => $messageId,
                'payload' => $payload,
            ]);

            return;
        }

        $eventDetails = $this->extractEventDetails($data);

        if (($eventDetails['opened_id'] ?? null) === 0 && (int) ($object['message_data']['count'] ?? 0) <= 1) {
            return;
        }

        $linkUrl = $object['link_data']['url'] ?? null;

        $metadata = $this->buildMetadata($data, $object, $eventDetails);

        $this->storeTrackingEvents(
            messageId: $messageId,
            eventType: 'link_clicked',
            recipients: $recipients,
            metadata: $metadata,
            eventAt: $eventDetails['timestamp'],
            ip: $eventDetails['ip'],
            userAgent: $eventDetails['user_agent'],
            linkUrl: $linkUrl,
        );
    }

    /**
     * Track thread reply events.
     */
    protected function handleThreadReplied(array $payload): void
    {
        $data = $payload['data'] ?? [];
        $object = $data['object'] ?? [];

        if (!empty($object['from_self'])) {
            return;
        }

        $threadId = $this->extractThreadId($object);
        $messageId = $this->resolveMessageId($object) ?? ($threadId ? 'thread:' . $threadId : null);

        if (!$messageId) {
            Log::channel('nylas')->warning('Unable to resolve identifiers for thread.replied webhook', ['payload' => $payload]);

            return;
        }

        $recipients = collect($object['from'] ?? [])
            ->map(fn (array $sender) => $sender['email'] ?? null)
            ->filter()
            ->values();

        if ($recipients->isEmpty()) {
            $recipients = $this->resolveRecipientsForMessage($messageId);
        }

        if ($recipients->isEmpty() && $threadId) {
            $recipients = EmailTracking::query()
                ->where('nylas_thread_id', $threadId)
                ->where('event_type', 'sent')
                ->pluck('recipient_email');
        }

        if ($recipients->isEmpty()) {
            Log::channel('nylas')->warning('Unable to resolve reply participants for thread.replied webhook', [
                'message_id' => $messageId,
                'thread_id' => $threadId,
                'payload' => $payload,
            ]);

            return;
        }

        $eventDetails = $this->extractEventDetails($data);

        $metadata = $this->buildMetadata($data, $object, $eventDetails);
        $metadata['reply_summary'] = [
            'subject' => $object['subject'] ?? null,
            'sender' => $object['from'] ?? null,
        ];

        $this->storeTrackingEvents(
            messageId: $messageId,
            eventType: 'replied',
            recipients: $recipients,
            metadata: $metadata,
            eventAt: $eventDetails['timestamp'],
            ip: $eventDetails['ip'],
            userAgent: $eventDetails['user_agent'],
        );
    }

    /**
     * Track bounce events.
     */
    protected function handleMessageBounced(array $payload): void
    {
        $this->handleDeliveryFailure($payload, 'bounced');
    }

    /**
     * Track rejection events.
     */
    protected function handleMessageRejected(array $payload): void
    {
        $this->handleDeliveryFailure($payload, 'rejected');
    }

    /**
     * Handle delivery failures that share the same payload shape.
     */
    protected function handleDeliveryFailure(array $payload, string $eventType): void
    {
        $data = $payload['data'] ?? [];
        $object = $data['object'] ?? [];

        $messageId = $this->resolveMessageId($object);

        if (!$messageId) {
            Log::warning('Missing message_id in delivery failure webhook', [
                'event_type' => $eventType,
                'payload' => $payload,
            ]);

            return;
        }

        $recipients = $this->resolveRecipientsForMessage($messageId, $object['recipient_email'] ?? null);

        if ($recipients->isEmpty()) {
            $recipients = collect($object['bounce']['recipients'] ?? [])
                ->map(fn ($recipient) => is_array($recipient) ? ($recipient['email'] ?? null) : $recipient)
                ->filter()
                ->values();
        }

        if ($recipients->isEmpty()) {
            Log::warning('Unable to resolve recipients for delivery failure webhook', [
                'event_type' => $eventType,
                'message_id' => $messageId,
                'payload' => $payload,
            ]);

            return;
        }

        $eventDetails = $this->extractEventDetails($data);

        $metadata = $this->buildMetadata($data, $object, $eventDetails);
        $metadata['failure_details'] = $object['bounce'] ?? [];

        $this->storeTrackingEvents(
            messageId: $messageId,
            eventType: $eventType,
            recipients: $recipients,
            metadata: $metadata,
            eventAt: $eventDetails['timestamp'],
            ip: $eventDetails['ip'],
            userAgent: $eventDetails['user_agent'],
        );
    }

    /**
     * Build a metadata payload enriched with resolved event details.
     */
    protected function buildMetadata(array $data, array $object, array $eventDetails): array
    {
        $metadata = $data;

        $threadId = $this->extractThreadId($object);

        if ($threadId) {
            $metadata['nylas_thread_id'] = $threadId;
        }

        $metadata['resolved_event_details'] = [
            'ip_addresses' => $eventDetails['ip_addresses'],
            'opened_id' => $eventDetails['opened_id'],
        ];

        return $metadata;
    }

    /**
     * Resolve recipients for the event.
     */
    protected function resolveRecipientsForMessage(string $messageId, ?string $recipientFromPayload = null): Collection
    {
        if ($recipientFromPayload) {
            return collect([$recipientFromPayload]);
        }

        return EmailTracking::query()
            ->where('nylas_message_id', $messageId)
            ->where('event_type', 'sent')
            ->pluck('recipient_email');
    }

    /**
     * Extract key details from the webhook payload.
     */
    protected function extractEventDetails(array $data): array
    {
        $object = $data['object'] ?? [];

        $timestamp = $object['timestamp'] ?? null;
        $ip = $object['ip_address'] ?? null;
        $userAgent = $object['user_agent'] ?? null;

        $recents = collect($object['recents'] ?? [])
            ->sortByDesc(fn ($item) => $item['timestamp'] ?? 0)
            ->values();

        $recent = $recents->first();

        if ($recent) {
            $timestamp = $recent['timestamp'] ?? $timestamp;
            $ip = $recent['ip'] ?? $ip;
            $userAgent = $recent['user_agent'] ?? $userAgent;
        }

        $normalizedTimestamp = null;

        if ($timestamp !== null) {
            $normalizedTimestamp = (int) $timestamp;

            if ($normalizedTimestamp > 9999999999) {
                $normalizedTimestamp = (int) round($normalizedTimestamp / 1000);
            }
        }

        $ipAddresses = [];

        if ($ip) {
            $ipAddresses = collect(explode(',', $ip))
                ->map(fn (string $value) => trim($value))
                ->filter()
                ->values()
                ->all();

            $ip = $ipAddresses[0] ?? $ip;
        }

        return [
            'timestamp' => $normalizedTimestamp ? Carbon::createFromTimestamp($normalizedTimestamp) : now(),
            'ip' => $ip,
            'ip_addresses' => $ipAddresses,
            'user_agent' => $userAgent,
            'opened_id' => $recent['opened_id'] ?? null,
        ];
    }

    /**
     * Ensure tracking entries exist for the supplied event.
     */
    protected function storeTrackingEvents(
        string $messageId,
        string $eventType,
        Collection $recipients,
        array $metadata,
        ?Carbon $eventAt = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?string $linkUrl = null,
    ): void {
        $threadId = $metadata['nylas_thread_id'] ?? null;

        if (!$threadId) {
            $threadId = EmailTracking::query()
                ->where('nylas_message_id', $messageId)
                ->where('event_type', 'sent')
                ->value('nylas_thread_id');
        }

        if ($threadId) {
            $metadata['nylas_thread_id'] = $threadId;
        }

        $projectId = $this->resolveProjectId(
            messageId: $messageId,
            threadId: $threadId,
            metadata: $metadata,
        );

        $eventAt = $eventAt ?? now();

        foreach ($recipients as $recipient) {
            if ($eventType === 'opened' && $eventAt instanceof Carbon) {
                $windowStart = (clone $eventAt)->subSeconds(60);

                $recentOpenExists = EmailTracking::query()
                    ->where('nylas_message_id', $messageId)
                    ->where('event_type', 'opened')
                    ->where('recipient_email', $recipient)
                    ->whereBetween('event_at', [$windowStart, $eventAt])
                    ->exists();

                if ($recentOpenExists) {
                    continue;
                }
            }

            $alreadyStored = EmailTracking::query()
                ->where('nylas_message_id', $messageId)
                ->where('event_type', $eventType)
                ->where('recipient_email', $recipient)
                ->where('event_at', $eventAt)
                ->exists();

            if ($alreadyStored) {
                continue;
            }

            EmailTracking::create([
                'project_id' => $projectId,
                'nylas_message_id' => $messageId,
                'nylas_thread_id' => $threadId,
                'event_type' => $eventType,
                'recipient_email' => $recipient,
                'link_url' => $linkUrl,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'metadata' => $metadata,
                'event_at' => $eventAt,
            ]);
        }
    }

    /**
     * Resolve the project identifier associated with this tracking context.
     */
    protected function resolveProjectId(string $messageId, ?string $threadId, array $metadata): ?int
    {
        $metadataProjectId = $metadata['project_id'] ?? null;

        if ($metadataProjectId) {
            return (int) $metadataProjectId;
        }

        $metadataEstimateId = $metadata['estimate_id'] ?? null;

        if ($metadataEstimateId) {
            $projectId = Estimate::query()
                ->whereKey($metadataEstimateId)
                ->value('project_id');

            if ($projectId) {
                return (int) $projectId;
            }
        }

        $projectId = EmailTracking::query()
            ->where('nylas_message_id', $messageId)
            ->whereNotNull('project_id')
            ->value('project_id');

        if ($projectId) {
            return (int) $projectId;
        }

        if ($threadId) {
            $projectId = EmailTracking::query()
                ->where('nylas_thread_id', $threadId)
                ->whereNotNull('project_id')
                ->value('project_id');

            if ($projectId) {
                return (int) $projectId;
            }
        }

        return null;
    }

    /**
     * Extract a thread identifier from the webhook payload.
     */
    protected function extractThreadId(array $object): ?string
    {
        return $object['thread_id']
            ?? data_get($object, 'thread.id');
    }

    /**
     * Resolve a message identifier from the webhook payload.
     */
    protected function resolveMessageId(array $object): ?string
    {
        return $object['message_id']
            ?? $object['id']
            ?? $object['latest_message_id']
            ?? $object['last_message_id']
            ?? null;
    }
}
