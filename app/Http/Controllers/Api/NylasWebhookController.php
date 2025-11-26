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

        // Log all incoming webhooks to dedicated channel
        Log::channel('nylas_webhooks')->info('Received webhook', [
            'type' => data_get($payload, 'type'),
            'payload' => $payload,
        ]);

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

        // Try to get recipient_email from payload
        $recipientEmail = $object['recipient_email'] ?? null;
        
        if ($recipientEmail) {
            // Specific recipient provided - track per-recipient
            $recipients = collect([$recipientEmail]);
            $isMessageLevel = false;
        } else {
            // No specific recipient - could be sender viewing sent email or recipient opening without recipient_email
            $recipients = $this->resolveRecipientsForMessage($messageId);
            
            // If we found recipients, this is a sent message being viewed
            // When recipient_email is missing but we have sent records, it's the sender viewing their sent email
            if ($recipients->isNotEmpty()) {
                return;
            }
            
            // No recipients found and no recipient_email - can't track this
            return;
        }

        $eventDetails = $this->extractEventDetails($data);

        // Filter out automated/prefetch opens using Mailtrap-inspired logic
        if ($this->isPrefetchOrAutomatedOpen($eventDetails, $object)) {
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
            isMessageLevel: $isMessageLevel,
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

        // Try to get recipient_email from payload
        $recipientEmail = $object['recipient_email'] ?? null;
        
        if ($recipientEmail) {
            // Specific recipient provided - track per-recipient
            $recipients = collect([$recipientEmail]);
            $isMessageLevel = false;
        } else {
            // No specific recipient - could be sender clicking link in sent email
            $recipients = $this->resolveRecipientsForMessage($messageId);
            
            // If we found recipients, this is a sent message being viewed
            // When recipient_email is missing but we have sent records, it's the sender clicking in their sent email
            if ($recipients->isNotEmpty()) {
                return;
            }
            
            // No recipients found and no recipient_email - can't track this
            return;
        }

        $eventDetails = $this->extractEventDetails($data);

        // Filter out automated/prefetch clicks
        if ($this->isPrefetchOrAutomatedOpen($eventDetails, $object)) {
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
            isMessageLevel: $isMessageLevel,
            isMessageLevel: $isMessageLevel ?? false,
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

        // Check if this is a bounce notification from a mailer daemon
        $fromEmails = collect($object['from'] ?? [])
            ->map(fn (array $sender) => strtolower($sender['email'] ?? ''))
            ->filter();

        $isBounceNotification = $fromEmails->contains(function ($email) {
            return str_contains($email, 'mailer-daemon') || 
                   str_contains($email, 'postmaster') ||
                   str_contains($email, 'noreply');
        });

        if ($isBounceNotification) {
            // Handle as a bounce instead of a reply
            $this->handleBouncedAsReply($payload, $data, $object);
            return;
        }

        $threadId = $this->extractThreadId($object);
        $messageId = $this->resolveMessageId($object) ?? ($threadId ? 'thread:' . $threadId : null);

        if (!$messageId) {
            Log::channel('nylas')->warning('Unable to resolve identifiers for thread.replied webhook', ['payload' => $payload]);

            return;
        }

        // For thread.replied, the "from" field contains who replied, not who we sent to.
        // We should only track the person who actually replied, not all original recipients.
        $replyFrom = $fromEmails->values();

        if ($replyFrom->isEmpty()) {
            // When we can't determine who replied, get all original recipients for message-level tracking
            $replyFrom = $this->resolveRecipientsForMessage($messageId, null, $threadId);
            $isMessageLevel = true;
        } else {
            $isMessageLevel = false;
        }        $eventDetails = $this->extractEventDetails($data);

        $metadata = $this->buildMetadata($data, $object, $eventDetails);
        $metadata['reply_summary'] = [
            'subject' => $object['subject'] ?? null,
            'sender' => $object['from'] ?? null,
        ];

        $this->storeTrackingEvents(
            messageId: $messageId,
            eventType: 'replied',
            recipients: $replyFrom,
            metadata: $metadata,
            eventAt: $eventDetails['timestamp'],
            ip: $eventDetails['ip'],
            userAgent: $eventDetails['user_agent'],
            isMessageLevel: $isMessageLevel ?? false,
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
        );
    }

    /**
     * Handle bounce notifications that come as thread replies (e.g., from Yahoo MAILER-DAEMON).
     */
    protected function handleBouncedAsReply(array $payload, array $data, array $object): void
    {
        $threadId = $this->extractThreadId($object);
        
        // Get the original message ID from the thread
        $originalSentRecords = EmailTracking::query()
            ->where('nylas_thread_id', $threadId)
            ->where('event_type', 'sent')
            ->get();

        if ($originalSentRecords->isEmpty()) {
            Log::channel('nylas')->warning('Cannot find original sent email for bounce-as-reply', [
                'thread_id' => $threadId,
                'payload' => $payload,
            ]);
            return;
        }

        $messageId = $originalSentRecords->first()->nylas_message_id;
        $recipients = $originalSentRecords->pluck('recipient_email')->unique();

        $eventDetails = $this->extractEventDetails($data);

        $metadata = $this->buildMetadata($data, $object, $eventDetails);
        $metadata['bounce_source'] = 'thread_reply';
        $metadata['bounce_sender'] = $object['from'] ?? [];
        $metadata['bounce_subject'] = $object['subject'] ?? null;

        Log::channel('nylas')->info('Detected bounce via thread reply', [
            'message_id' => $messageId,
            'thread_id' => $threadId,
            'recipients' => $recipients->toArray(),
            'from' => $object['from'] ?? [],
        ]);

        $this->storeTrackingEvents(
            messageId: $messageId,
            eventType: 'bounced',
            recipients: $recipients,
            metadata: $metadata,
            eventAt: $eventDetails['timestamp'],
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
    protected function resolveRecipientsForMessage(string $messageId, ?string $recipientFromPayload = null, ?string $threadId = null): Collection
    {
        if ($recipientFromPayload) {
            return collect([$recipientFromPayload]);
        }

        $recipients = EmailTracking::query()
            ->where('nylas_message_id', $messageId)
            ->where('event_type', 'sent')
            ->get()
            ->pluck('recipient_emails')
            ->flatten()
            ->unique();

        if ($recipients->isEmpty() && $threadId) {
            $recipients = EmailTracking::query()
                ->where('nylas_thread_id', $threadId)
                ->where('event_type', 'sent')
                ->get()
                ->pluck('recipient_emails')
                ->flatten()
                ->unique();
        }

        return $recipients;
    }

    /**
     * Resolve the sender's email address from the grant_id in the webhook payload.
     */
    protected function resolveSenderEmailFromGrant(?string $grantId): ?string
    {
        if (!$grantId) {
            return null;
        }

        $companyEmail = \App\Models\CompanyEmail::where('grant_id', $grantId)->first();

        return $companyEmail?->email;
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
        bool $isMessageLevel = false,
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

        // Try to get email_template_name from existing sent record
        // First try by message_id (for opened/clicked on same message)
        $existingRecord = EmailTracking::query()
            ->where('nylas_message_id', $messageId)
            ->where('event_type', 'sent')
            ->first();
        
        // If not found and we have a thread_id, try by thread_id (for replies)
        if (!$existingRecord && $threadId) {
            $existingRecord = EmailTracking::query()
                ->where('nylas_thread_id', $threadId)
                ->where('event_type', 'sent')
                ->first();
        }
        
        $emailTemplateName = $existingRecord?->email_template_name;

        $eventAt = $eventAt ?? now();

        if ($isMessageLevel) {
            // Create single tracking record with all recipients in JSON array
            $recipientsArray = $recipients->values()->all();

            // Check if already stored
            $alreadyStored = EmailTracking::query()
                ->where('nylas_message_id', $messageId)
                ->where('event_type', $eventType)
                ->where('event_at', $eventAt)
                ->whereJsonLength('recipient_emails', count($recipientsArray))
                ->exists();

            if (!$alreadyStored) {
                EmailTracking::create([
                    'project_id' => $projectId,
                    'nylas_message_id' => $messageId,
                    'nylas_thread_id' => $threadId,
                    'email_template_name' => $emailTemplateName,
                    'event_type' => $eventType,
                    'recipient_emails' => $recipientsArray,
                    'link_url' => $linkUrl,
                    'ip_address' => $ip,
                    'user_agent' => $userAgent,
                    'metadata' => $metadata,
                    'event_at' => $eventAt,
                ]);
            }
        } else {
            // Create individual tracking records per recipient
            foreach ($recipients as $recipient) {
                if ($eventType === 'opened' && $eventAt instanceof Carbon) {
                    $windowStart = (clone $eventAt)->subSeconds(60);

                    $recentOpenExists = EmailTracking::query()
                        ->where('nylas_message_id', $messageId)
                        ->where('event_type', 'opened')
                        ->whereJsonContains('recipient_emails', $recipient)
                        ->whereBetween('event_at', [$windowStart, $eventAt])
                        ->exists();

                    if ($recentOpenExists) {
                        continue;
                    }
                }

                $alreadyStored = EmailTracking::query()
                    ->where('nylas_message_id', $messageId)
                    ->where('event_type', $eventType)
                    ->whereJsonContains('recipient_emails', $recipient)
                    ->where('event_at', $eventAt)
                    ->exists();

                if ($alreadyStored) {
                    continue;
                }

                EmailTracking::create([
                    'project_id' => $projectId,
                    'nylas_message_id' => $messageId,
                    'nylas_thread_id' => $threadId,
                    'email_template_name' => $emailTemplateName,
                    'event_type' => $eventType,
                    'recipient_emails' => [$recipient],
                    'link_url' => $linkUrl,
                    'ip_address' => $ip,
                    'user_agent' => $userAgent,
                    'metadata' => $metadata,
                    'event_at' => $eventAt,
                ]);
            }
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

    /**
     * Detect automated/prefetch opens using Mailtrap-inspired heuristics.
     * 
     * Mail clients (Apple Mail, Outlook, Gmail) often prefetch/preload emails
     * to show previews or check for safety. These should not count as real opens.
     * 
     * Detection strategies:
     * 1. User agent patterns - automated clients, security scanners, bots
     * 2. Known automated IP ranges (cloud providers, security services)
     * 3. Suspicious timing patterns (multiple opens within seconds)
     * 
     * Note: We no longer filter on opened_id = 0, as legitimate opens can have this value
     */
    protected function isPrefetchOrAutomatedOpen(array $eventDetails, array $object): bool
    {
        $userAgent = $eventDetails['user_agent'] ?? '';
        $openedId = $eventDetails['opened_id'] ?? null;
        $count = (int) ($object['message_data']['count'] ?? 0);

        // Check 2: User agent patterns indicating automated clients
        $automatedPatterns = [
            // Security/Link scanners
            '/(?:safe|security|scanner|link.*check|threat|virus|malware)/i',
            '/(?:barracuda|proofpoint|mimecast|ironport|forcepoint)/i',
            
            // Email prefetch clients
            '/(?:apple.*mail.*prefetch|outlook.*safelink|gmail.*image.*proxy)/i',
            '/(?:microsoft.*safe.*link|office.*365.*atp)/i',
            
            // Bots and crawlers
            '/(?:bot|crawler|spider|scraper|curl|wget|python-requests)/i',
            
            // Headless browsers used for automation
            '/(?:headless|phantom|selenium|puppeteer)/i',
            
            // Generic automated indicators
            '/(?:auto|fetch|preload|preview|cache)/i',
        ];

        foreach ($automatedPatterns as $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return true;
            }
        }

        // Check 3: Empty or missing user agent (often automated)
        if (empty($userAgent) || $userAgent === 'Unknown' || $userAgent === 'null') {
            return true;
        }

        // Check 4: Known automated/cloud service IP patterns
        $ip = $eventDetails['ip'] ?? '';
        $ipAddresses = $eventDetails['ip_addresses'] ?? [];
        
        // Common patterns for security scanners and cloud services
        $automatedIpPatterns = [
            '/^(?:10|172\.(?:1[6-9]|2[0-9]|3[01])|192\.168)\./i', // Private IPs (often proxies/scanners)
            '/^(?:52|54|34|35|18)\./i', // AWS ranges (often used by security services)
            '/^(?:104\.(?:16|17|18|19|20|21|22|23|24|25|26|27))\./i', // Cloudflare
            '/^(?:209\.85|172\.217|74\.125)\./i', // Google ranges
        ];

        foreach (array_merge([$ip], $ipAddresses) as $checkIp) {
            foreach ($automatedIpPatterns as $pattern) {
                if (preg_match($pattern, $checkIp)) {
                    // Note: This is aggressive - consider logging for tuning
                    Log::channel('nylas')->debug('Potential automated IP detected', [
                        'ip' => $checkIp,
                        'user_agent' => $userAgent,
                    ]);
                    // Don't auto-reject based on IP alone unless combined with other signals
                    // return true;
                }
            }
        }

        // Check 5: opened_id of 0 is always suspicious (Nylas tracks legitimate opens with IDs)
        if ($openedId === 0) {
            return true;
        }

        return false;
    }
}
