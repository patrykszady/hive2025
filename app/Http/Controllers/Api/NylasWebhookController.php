<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailTracking;
use App\Models\Estimate;
use App\Services\NylasService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class NylasWebhookController extends Controller
{
    public function __construct(
        protected NylasService $nylasService
    ) {}

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
        $nylasRequestId = $request->header('X-Nylas-Request-Id');

        $type = data_get($payload, 'type');
        $data = data_get($payload, 'data');

        // For message.created/updated webhooks, only log if thread is tracked
        // This prevents excessive logging from all incoming messages
        $shouldLog = true;
        if (in_array($type, ['message.created', 'message.updated', 'message.created.truncated', 'message.updated.truncated'])) {
            $threadId = data_get($data, 'object.thread_id');
            if ($threadId) {
                $isTrackedThread = EmailTracking::query()
                    ->where('nylas_thread_id', $threadId)
                    ->where('event_type', 'sent')
                    ->exists();
                $shouldLog = $isTrackedThread;
            } else {
                $shouldLog = false;
            }
        }

        // Log incoming webhooks to dedicated channel (filtered for message.* types)
        if ($shouldLog) {
            Log::channel('nylas_webhooks')->info('Received webhook', [
                'type' => $type,
                'nylas_request_id' => $nylasRequestId,
                'payload' => $payload,
            ]);
        }

        if (!$type || !is_array($data)) {
            Log::channel('nylas')->warning('Invalid webhook payload', ['payload' => $payload]);

            return response()->json(['status' => 'ignored']);
        }

        $handlers = [
            'message.link_clicked' => 'handleMessageLinkClicked',
            'thread.replied' => 'handleThreadReplied',
            'message.bounced' => 'handleMessageBounced',
            'message.rejected' => 'handleMessageRejected',
            'message.created' => 'handleMessageCreated',
            'message.updated' => 'handleMessageCreated',
            'message.created.truncated' => 'handleMessageCreated',
            'message.updated.truncated' => 'handleMessageCreated',
        ];

        // Webhook types we intentionally ignore (no logging needed)
        $ignoredTypes = [
            'message.opened', // Opens tracked via custom pixel (EmailTrackingController)
        ];

        if (isset($handlers[$type])) {
            $handler = $handlers[$type];
            $payload['nylas_request_id'] = $nylasRequestId;
            $this->{$handler}($payload);
        } elseif (in_array($type, $ignoredTypes)) {
            // Silently ignore these webhook types
        } else {
            Log::channel('nylas')->info('Unhandled webhook type', ['type' => $type]);
        }

        return response()->json(['status' => 'ok']);
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
     * 
     * NOTE: This webhook is unreliable as it only fires when recipient loads
     * the Nylas tracking pixel. The message.created handler is the primary
     * mechanism for reply detection. This handler now defers to that logic
     * to ensure consistent behavior and avoid duplicate events.
     */
    protected function handleThreadReplied(array $payload): void
    {
        $data = $payload['data'] ?? [];
        $object = $data['object'] ?? [];

        // Skip from_self - these are our own replies in the thread
        if (!empty($object['from_self'])) {
            Log::channel('nylas')->debug('Skipping thread.replied - from_self', [
                'thread_id' => $object['thread_id'] ?? null,
            ]);
            return;
        }

        // Transform payload to look like message.created and delegate
        // This ensures consistent handling between both webhook types
        $messagePayload = [
            'type' => 'thread.replied',
            'data' => $data,
        ];

        Log::channel('nylas')->info('thread.replied webhook received, delegating to message handler', [
            'thread_id' => $object['thread_id'] ?? null,
            'message_id' => $object['id'] ?? $this->resolveMessageId($object),
        ]);

        $this->handleMessageCreated($messagePayload);
    }

    /**
     * Handle message.created/updated webhook for reliable reply detection.
     * 
     * This is the PRIMARY mechanism for detecting thread replies since thread.replied
     * webhook is unreliable (depends on recipient loading tracking pixel).
     * 
     * Handles:
     * - Reply chain tracking with sequence order
     * - OOO/auto-reply detection and filtering
     * - Bounce notification detection
     * - Shared mailbox sender detection
     * - Deduplication of events
     */
    protected function handleMessageCreated(array $payload): void
    {
        $webhookType = $payload['type'] ?? 'message.created';
        $data = $payload['data'] ?? [];
        $object = $data['object'] ?? [];
        $grantId = $data['grant_id'] ?? null;

        $threadId = $object['thread_id'] ?? null;
        $messageId = $object['id'] ?? null;
        $subject = $object['subject'] ?? '';
        $snippet = $object['snippet'] ?? '';

        // Only process if we have a thread_id to match against
        if (!$threadId) {
            return;
        }

        // Check if this thread is one we're tracking (has a 'sent' event)
        $trackedThread = EmailTracking::query()
            ->where('nylas_thread_id', $threadId)
            ->where('event_type', 'sent')
            ->first();

        if (!$trackedThread) {
            // Not a thread we're tracking, ignore
            return;
        }

        // Get sender email from the message
        $fromEmails = collect($object['from'] ?? [])
            ->map(fn (array $sender) => strtolower($sender['email'] ?? ''))
            ->filter();

        if ($fromEmails->isEmpty()) {
            return;
        }

        $fromEmail = $fromEmails->first();
        $fromName = strtolower($object['from'][0]['name'] ?? '');

        // === SENDER DETECTION (including shared mailboxes) ===
        if ($this->isFromSelfOrSharedMailbox($grantId, $fromEmails, $trackedThread)) {
            Log::channel('nylas')->debug('Skipping message.created - from self/shared mailbox', [
                'thread_id' => $threadId,
                'message_id' => $messageId,
                'from' => $fromEmail,
            ]);
            return;
        }

        // === BOUNCE NOTIFICATION DETECTION ===
        if ($this->isBounceNotification($fromEmail, $fromName, $subject)) {
            Log::channel('nylas')->info('Skipping reply - detected as bounce notification', [
                'thread_id' => $threadId,
                'message_id' => $messageId,
                'from' => $fromEmail,
            ]);
            return;
        }

        // === OOO / AUTO-REPLY DETECTION ===
        $autoReplyType = $this->detectAutoReplyType($subject, $snippet, $object);
        if ($autoReplyType) {
            // Log auto-replies but don't store in database
            Log::channel('nylas')->info('Skipping auto-reply message (not stored)', [
                'thread_id' => $threadId,
                'message_id' => $messageId,
                'from' => $fromEmail,
                'auto_reply_type' => $autoReplyType,
                'subject' => $subject,
                'project_id' => $trackedThread->project_id,
            ]);
            return;
        }

        // === DEDUPLICATION ===
        // Check if we already have a 'replied' event for this exact message_id
        $alreadyTracked = EmailTracking::query()
            ->where('nylas_message_id', $messageId)
            ->where('event_type', 'replied')
            ->exists();

        if ($alreadyTracked) {
            Log::channel('nylas')->debug('Reply already tracked for this message', [
                'thread_id' => $threadId,
                'message_id' => $messageId,
            ]);
            return;
        }

        // === REPLY CHAIN TRACKING ===
        // Count existing replies in this thread to track sequence
        $existingRepliesCount = EmailTracking::query()
            ->where('nylas_thread_id', $threadId)
            ->where('event_type', 'replied')
            ->count();
        
        $replyIndex = $existingRepliesCount + 1;

        Log::channel('nylas')->info('Reply detected via ' . $webhookType, [
            'thread_id' => $threadId,
            'message_id' => $messageId,
            'from' => $fromEmail,
            'reply_index' => $replyIndex,
            'project_id' => $trackedThread->project_id,
        ]);

        // Parse timestamp from the message
        $timestamp = isset($object['date']) 
            ? Carbon::createFromTimestamp($object['date']) 
            : now();

        $metadata = [
            'nylas_thread_id' => $threadId,
            'detection_source' => $webhookType,
            'reply_index' => $replyIndex,
            'original_subject' => $trackedThread->metadata['original_subject'] ?? $subject,
            'reply_summary' => [
                'subject' => $subject,
                'sender' => $object['from'] ?? null,
                'snippet' => $snippet,
                'in_reply_to' => $object['in_reply_to'] ?? null,
            ],
        ];

        $this->storeTrackingEvents(
            messageId: $messageId,
            eventType: 'replied',
            recipients: $fromEmails,
            metadata: $metadata,
            eventAt: $timestamp,
            ip: null,
            userAgent: null,
            isMessageLevel: false,
        );
    }

    /**
     * Check if the message is from the sender's own account or a shared mailbox.
     */
    protected function isFromSelfOrSharedMailbox(
        ?string $grantId, 
        Collection $fromEmails, 
        EmailTracking $trackedThread
    ): bool {
        // Method 1: Check against grant's associated email
        $senderEmail = $this->resolveSenderEmailFromGrant($grantId);
        if ($senderEmail && $fromEmails->contains(strtolower($senderEmail))) {
            return true;
        }

        // Method 2: Check against all company emails (shared mailboxes)
        $companyEmails = \App\Models\CompanyEmail::query()
            ->pluck('email')
            ->map(fn ($email) => strtolower($email));
        
        if ($fromEmails->intersect($companyEmails)->isNotEmpty()) {
            return true;
        }

        // Method 3: Check if sender matches the original 'sent' event's from address
        $originalSenderEmails = $trackedThread->metadata['from_emails'] ?? [];
        if (!empty($originalSenderEmails)) {
            $originalSenders = collect($originalSenderEmails)->map(fn ($e) => strtolower($e));
            if ($fromEmails->intersect($originalSenders)->isNotEmpty()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the message is a bounce/delivery failure notification.
     */
    protected function isBounceNotification(string $fromEmail, string $fromName, string $subject): bool
    {
        $subjectLower = strtolower($subject);

        // Check from email patterns
        $bounceEmailPatterns = [
            'mailer-daemon',
            'postmaster',
            'noreply@',
            'no-reply@',
            'microsoftexchange',
            'maildelivery',
            'mail-daemon',
        ];

        foreach ($bounceEmailPatterns as $pattern) {
            if (str_contains($fromEmail, $pattern)) {
                return true;
            }
        }

        // Check from name patterns  
        $bounceNamePatterns = [
            'mail delivery',
            'postmaster',
            'mailer daemon',
            'microsoft outlook',
            'mail system',
        ];

        foreach ($bounceNamePatterns as $pattern) {
            if (str_contains($fromName, $pattern)) {
                return true;
            }
        }

        // Check subject patterns
        $bounceSubjectPatterns = [
            'undeliverable',
            'delivery status notification',
            'delivery failure',
            'returned mail',
            'mail delivery failed',
            'delivery has failed',
            'undelivered mail',
            'message not delivered',
            'could not be delivered',
            'delivery problem',
            'failure notice',
        ];

        foreach ($bounceSubjectPatterns as $pattern) {
            if (str_contains($subjectLower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect if the message is an auto-reply (OOO, vacation, etc).
     * Returns the type of auto-reply or null if not an auto-reply.
     */
    protected function detectAutoReplyType(string $subject, string $snippet, array $object): ?string
    {
        $subjectLower = strtolower($subject);
        $snippetLower = strtolower($snippet);

        // Check X-Auto-Reply or similar headers if available
        $headers = $object['headers'] ?? [];
        foreach ($headers as $header) {
            $headerName = strtolower($header['name'] ?? '');
            $headerValue = strtolower($header['value'] ?? '');
            
            if ($headerName === 'x-auto-reply' || $headerName === 'auto-submitted') {
                if ($headerValue !== 'no') {
                    return 'auto_reply_header';
                }
            }
            
            if ($headerName === 'x-autoreply' || $headerName === 'x-autorespond') {
                return 'auto_reply_header';
            }
            
            // Check precedence header
            if ($headerName === 'precedence' && in_array($headerValue, ['auto_reply', 'bulk', 'junk'])) {
                return 'auto_reply_precedence';
            }
        }

        // Out of Office patterns
        $oooPatterns = [
            'out of office',
            'out of the office',
            'away from office',
            'away from the office',
            'automatic reply',
            'automatyczna odpowiedź',  // Polish
            'réponse automatique',     // French
            'respuesta automática',    // Spanish
            'automatische antwort',    // German
            'risposta automatica',     // Italian
        ];

        foreach ($oooPatterns as $pattern) {
            if (str_contains($subjectLower, $pattern) || str_contains($snippetLower, $pattern)) {
                return 'out_of_office';
            }
        }

        // Vacation patterns
        $vacationPatterns = [
            'on vacation',
            'on holiday',
            'on leave',
            'i am currently away',
            'i\'m currently away',
            'currently out',
            'will be back on',
            'will return on',
            'limited access to email',
            'i will respond when i return',
        ];

        foreach ($vacationPatterns as $pattern) {
            if (str_contains($subjectLower, $pattern) || str_contains($snippetLower, $pattern)) {
                return 'vacation';
            }
        }

        // Auto-response subject prefixes
        $autoSubjectPrefixes = [
            'auto:',
            'auto-reply:',
            'autoreply:',
            '[auto]',
            '[automatic reply]',
        ];

        foreach ($autoSubjectPrefixes as $prefix) {
            if (str_starts_with($subjectLower, $prefix)) {
                return 'auto_reply_subject';
            }
        }

        // Read receipt patterns
        $readReceiptPatterns = [
            'read:',
            'read receipt',
            'message read notification',
        ];

        foreach ($readReceiptPatterns as $pattern) {
            if (str_contains($subjectLower, $pattern)) {
                return 'read_receipt';
            }
        }

        return null;
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
        $recipients = $originalSentRecords->pluck('recipient_emails')->flatten()->unique();

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

        // Store complete Nylas webhook payload for debugging
        $metadata['nylas_webhook_payload'] = [
            'full_data' => $data,
            'full_object' => $object,
            'raw_timestamp' => $object['timestamp'] ?? null,
            'raw_recents' => $object['recents'] ?? null,
            'nylas_request_id' => $data['nylas_request_id'] ?? null,
            'processed_at' => now()->toIso8601String(),
            'server_timezone' => date_default_timezone_get(),
            'app_timezone' => config('app.timezone'),
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
            'timestamp' => $normalizedTimestamp ? Carbon::createFromTimestamp($normalizedTimestamp)->setTimezone('UTC') : now()->setTimezone('UTC'),
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
                // Deduplicate by opened_id to prevent Nylas duplicate webhook deliveries
                $openedId = $metadata['resolved_event_details']['opened_id'] ?? null;
                
                if ($openedId !== null) {
                    $duplicateByOpenedId = EmailTracking::query()
                        ->where('nylas_message_id', $messageId)
                        ->where('event_type', $eventType)
                        ->whereJsonContains('recipient_emails', $recipient)
                        ->whereRaw("JSON_EXTRACT(metadata, '$.resolved_event_details.opened_id') = ?", [$openedId])
                        ->exists();

                    if ($duplicateByOpenedId) {
                        Log::channel('nylas')->info('Skipping duplicate webhook by opened_id', [
                            'message_id' => $messageId,
                            'event_type' => $eventType,
                            'recipient' => $recipient,
                            'opened_id' => $openedId,
                        ]);
                        continue;
                    }
                }

                if ($eventType === 'opened' && $eventAt instanceof Carbon) {
                    // Check for very recent opens (within 5 seconds) - likely duplicates from same action
                    $shortWindowStart = (clone $eventAt)->subSeconds(5);
                    $veryRecentOpenExists = EmailTracking::query()
                        ->where('nylas_message_id', $messageId)
                        ->where('event_type', 'opened')
                        ->whereJsonContains('recipient_emails', $recipient)
                        ->whereBetween('event_at', [$shortWindowStart, $eventAt])
                        ->lockForUpdate() // Prevent race conditions
                        ->exists();

                    if ($veryRecentOpenExists) {
                        Log::channel('nylas')->info('Skipping duplicate open within 5 seconds', [
                            'message_id' => $messageId,
                            'recipient' => $recipient,
                            'event_at' => $eventAt->toDateTimeString(),
                        ]);
                        continue;
                    }

                    // Check for opens within 60 seconds - still track but note it
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
            '/(?:apple.*mail.*prefetch|outlook.*safelink|gmail.*image.*proxy|googleimageproxy|ggpht\.com)/i',
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
