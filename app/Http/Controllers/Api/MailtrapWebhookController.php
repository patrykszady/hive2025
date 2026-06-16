<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailTracking;
use App\Models\Project;
use App\Models\User;
use App\Support\IpNetworkMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class MailtrapWebhookController extends Controller
{
    /**
     * Cache of vendor team member emails keyed by vendor ID.
     *
     * @var array<int, list<string>>
     */
    protected array $vendorTeamEmailCache = [];

    /**
     * Cached list of internal/sender networks (config CIDRs + recorded sender IPs).
     *
     * @var list<string>|null
     */
    protected ?array $internalSenderNetworks = null;

    /**
     * Handle Mailtrap webhook payloads.
     *
     * Mailtrap may not always include custom_variables in webhook payloads.
     * To keep the UX consistent, we attempt to relink delivery/open/click events to the
     * corresponding 'sent' record by matching:
     * - tracking_id (preferred)
     * - mailtrap message_id (if stored on the 'sent' metadata)
     * - recipient + timestamp proximity (fallback)
     */
    public function handle(Request $request, string $token): Response
    {
        $expectedToken = (string) config('email_tracking.mailtrap_webhook_token');

        if ($expectedToken === '' || ! hash_equals($expectedToken, $token)) {
            return response('', 401);
        }

        $payload = $request->all();

        // Mailtrap may send a single event or a list of events.
        $events = $this->normalizeEvents($payload);

        $stats = [
            'events_total' => count($events),
            'events_processed' => 0,
            'events_persisted' => 0,
            'events_deduped' => 0,
            'events_ignored_untracked' => 0,
            'events_ignored_unknown' => 0,
            'events_ignored_bot' => 0,
            'events_ignored_excluded_recipient' => 0,
            'events_ignored_non_recipient' => 0,
            'events_ignored_sender' => 0,
            'events_failed' => 0,
        ];

        /** @var array<int, array<string, mixed>> $eventSummaries */
        $eventSummaries = [];

        /** @var array<int, int|null> $projectVendorCache */
        $projectVendorCache = [];

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $normalized = $this->normalizeEvent($event);

            if (! $normalized) {
                continue;
            }

            $stats['events_processed']++;

            [$correlationId, $eventType, $recipientEmail, $linkUrl, $eventAt, $providerMessageId, $metadata] = $normalized;

            if ($this->isExcludedRecipient($recipientEmail)) {
                $stats['events_ignored_excluded_recipient']++;
                continue;
            }

            // Ignore events we don't understand; they don't map to UX.
            if ($eventType === 'mailtrap_webhook_received') {
                $stats['events_ignored_unknown']++;
                continue;
            }

            $trackingId = Arr::get($metadata, 'tracking_id');
            $providerUserAgent = $this->getString($event, ['user_agent', 'userAgent']);
            $providerEventId = $this->getString($event, ['event_id', 'eventId']);
            $eventIp = $this->getString($event, ['ip', 'client_ip', 'clientIp']);

            // Match to our tracked 'sent' event first; Mailtrap webhooks do not always include custom_variables.
            $sent = $this->findBestSentMatch(
                trackingId: is_string($trackingId) ? $trackingId : null,
                providerMessageId: $providerMessageId,
                recipientEmail: $recipientEmail,
                eventAt: $eventAt,
            );

            // If this webhook event can't be linked to a tracked `sent` row created by our app/UI,
            // we ignore it entirely (no persistence, no logging).
            if (! $sent) {
                $stats['events_ignored_untracked']++;
                continue;
            }

            $eventSummaries[] = array_filter([
                'event_type' => $eventType,
                'recipient_email' => $recipientEmail,
                'tracking_id' => is_string($trackingId) ? $trackingId : null,
                'message_id' => $providerMessageId,
                'event_id' => $this->getString($event, ['event_id', 'eventId']),
                'timestamp' => $eventAt?->toIso8601String(),
                'user_agent' => $providerUserAgent,
            ], static fn ($v) => $v !== null);

            $baselineAt = $sent?->event_at?->toImmutable();

            if ($baselineAt === null && $eventAt !== null && (int) config('email_tracking.mailtrap_bot_open_within_seconds', 0) > 0) {
                $fallbackBaseline = EmailTracking::query()
                    ->where('message_id', $correlationId)
                    ->whereIn('event_type', ['sent', 'delivered'])
                    ->orderByDesc('event_at')
                    ->first();

                $baselineAt = $fallbackBaseline?->event_at?->toImmutable();
            }

            if ($this->shouldIgnoreAsBot($eventType, $eventAt, $baselineAt, $providerUserAgent)) {
                $stats['events_ignored_bot']++;
                continue;
            }

            if ($this->shouldIgnoreAsNonRecipient($eventType, $recipientEmail, $sent)) {
                $stats['events_ignored_non_recipient']++;
                continue;
            }

            if ($this->shouldIgnoreAsSenderOpen($eventType, $recipientEmail, $sent)) {
                $stats['events_ignored_sender']++;
                continue;
            }

            if ($this->shouldIgnoreAsSenderIpOpen($eventType, $eventIp, $sent)) {
                $stats['events_ignored_sender']++;
                continue;
            }

            // Ignore opens/clicks from team members of the vendor that sent the email.
            $sentVendorId = $sent?->belongs_to_vendor_id ? (int) $sent->belongs_to_vendor_id : null;
            if ($this->shouldIgnoreAsVendorTeamMember($eventType, $recipientEmail, $sentVendorId)) {
                $stats['events_ignored_sender']++;
                continue;
            }

            $canonicalMessageId = $sent?->message_id ?: $correlationId;

            $projectId = Arr::get($metadata, 'project_id');
            if ($projectId !== null) {
                $projectId = (int) $projectId ?: null;
            }
            if (! $projectId && $sent?->project_id) {
                $projectId = (int) $sent->project_id;
            }

            $leadId = Arr::get($metadata, 'lead_id');
            if ($leadId !== null) {
                $leadId = (int) $leadId ?: null;
            }
            if (! $leadId && $sent?->lead_id) {
                $leadId = (int) $sent->lead_id;
            }

            $belongsToVendorId = Arr::get($metadata, 'belongs_to_vendor_id') ?: ($sent?->belongs_to_vendor_id ?? null);
            if (! $belongsToVendorId && $projectId) {
                if (! array_key_exists($projectId, $projectVendorCache)) {
                    $projectVendorCache[$projectId] = Project::query()
                        ->whereKey($projectId)
                        ->value('belongs_to_vendor_id');
                }

                $belongsToVendorId = $projectVendorCache[$projectId];
            }

            $threadId = Arr::get($metadata, 'thread_id');
            if (! $threadId && $sent?->thread_id) {
                $threadId = (string) $sent->thread_id;
            }

            $emailTemplateName = Arr::get($metadata, 'email_template_name');
            if (! $emailTemplateName && $sent?->email_template_name) {
                $emailTemplateName = (string) $sent->email_template_name;
            }

            // If webhook events arrived identified only by provider message id (mailtrap:...),
            // update existing rows so the project UI can group by our canonical tracking id.
            if ($sent && $canonicalMessageId !== $correlationId) {
                EmailTracking::query()
                    ->where('message_id', $correlationId)
                    ->update([
                        'project_id' => $projectId,
                        'message_id' => $canonicalMessageId,
                        'thread_id' => $threadId,
                        'email_template_name' => $emailTemplateName,
                    ]);

                $correlationId = $canonicalMessageId;
            }

            // Basic dedupe (same correlationId + recipient + eventType around eventAt)
            if (is_string($providerEventId) && $providerEventId !== '') {
                $alreadySeen = EmailTracking::query()
                    ->where('metadata->mailtrap_event_id', $providerEventId)
                    ->exists();

                if ($alreadySeen) {
                    $stats['events_deduped']++;
                    continue;
                }
            } else {
                // Fallback dedupe if provider didn't include an event id.
                $windowStart = $eventAt?->subMinute() ?? now()->subMinute();
                $windowEnd = $eventAt?->addMinute() ?? now();

                $recentQuery = EmailTracking::query()
                    ->where('message_id', $correlationId)
                    ->where('event_type', $eventType)
                    ->whereBetween('event_at', [$windowStart, $windowEnd]);

                if ($recipientEmail !== null) {
                    $recentQuery->whereJsonContains('recipient_emails', $recipientEmail);
                }

                if ($recentQuery->exists()) {
                    $stats['events_deduped']++;
                    continue;
                }
            }

            try {
                $record = EmailTracking::create([
                    'belongs_to_vendor_id' => $belongsToVendorId,
                    'project_id' => $projectId,
                    'lead_id' => $leadId,
                    'message_id' => $correlationId,
                    'thread_id' => $threadId,
                    'email_template_name' => $emailTemplateName,
                    'event_type' => $eventType,
                    'recipient_emails' => $recipientEmail ? [$recipientEmail] : null,
                    'link_url' => $linkUrl,
                    'ip_address' => $eventIp ?: $request->ip(),
                    'user_agent' => $providerUserAgent ?: $request->userAgent(),
                    'metadata' => array_merge($metadata, [
                        'source' => 'mailtrap_webhook',
                        'provider' => 'mailtrap',
                        'mailtrap_message_id' => $providerMessageId,
                        'mailtrap_event_id' => $providerEventId,
                        'linked_sent_id' => $sent?->id,
                        'event_ip' => $eventIp,
                        'webhook_ip' => $request->ip(),
                        'webhook_user_agent' => $request->userAgent(),
                    ]),
                    'event_at' => $eventAt ?? now(),
                ]);

                $stats['events_persisted']++;
            } catch (Throwable $exception) {
                $stats['events_failed']++;
                Log::channel('mailtrap')->error('Mailtrap webhook failed to persist', [
                    'message_id' => $correlationId,
                    'event_type' => $eventType,
                    'recipient_email' => $recipientEmail,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        // Only log webhooks that resulted in persisted records or failures.
        // Untracked/ignored events should not pollute logs.
        if ($stats['events_persisted'] > 0 || $stats['events_failed'] > 0) {
            Log::channel('mailtrap')->info('Mailtrap webhook received', [
                'ip' => $request->ip(),
                'hookdeck_original_ip' => $request->header('x-hookdeck-original-ip'),
                'user_agent' => $request->userAgent(),
                'content_type' => $request->header('content-type'),
                'content_length' => $request->header('content-length'),
                'payload_keys' => array_keys($payload),
                'stats' => $stats,
                'events' => $eventSummaries,
            ]);
        }

        return response('', 200);
    }

    protected function shouldIgnoreAsBot(string $eventType, ?CarbonImmutable $eventAt, ?CarbonImmutable $baselineAt, ?string $providerUserAgent): bool
    {
        if (! (bool) config('email_tracking.mailtrap_filter_bots', true)) {
            return false;
        }

        if (! in_array($eventType, ['opened', 'link_clicked'], true)) {
            return false;
        }

        if (is_string($providerUserAgent) && $providerUserAgent !== '') {
            $ua = strtolower($providerUserAgent);
            $substrings = (array) config('email_tracking.mailtrap_bot_ua_substrings', []);

            foreach ($substrings as $substring) {
                if (! is_string($substring) || $substring === '') {
                    continue;
                }

                if (str_contains($ua, strtolower($substring))) {
                    return true;
                }
            }
        }

        $seconds = (int) config('email_tracking.mailtrap_bot_open_within_seconds', 0);
        if ($seconds <= 0 || $eventAt === null || $baselineAt === null) {
            return false;
        }

        $diffSeconds = $baselineAt->diffInSeconds($eventAt);

        return $diffSeconds <= $seconds;
    }

    protected function shouldIgnoreAsSenderOpen(string $eventType, ?string $recipientEmail, ?EmailTracking $sent): bool
    {
        if (! (bool) config('email_tracking.mailtrap_filter_sender_opens', true)) {
            return false;
        }

        if (! in_array($eventType, ['opened', 'link_clicked'], true)) {
            return false;
        }

        if (! is_string($recipientEmail) || trim($recipientEmail) === '') {
            // We can't attribute this to a recipient; safest is to ignore.
            return true;
        }

        $recipientEmail = strtolower(trim($recipientEmail));

        $senderEmail = Arr::get($sent?->metadata ?? [], 'sender_email');
        if (is_string($senderEmail) && $senderEmail !== '' && $recipientEmail === strtolower(trim($senderEmail))) {
            return true;
        }

        $fromEmail = Arr::get($sent?->metadata ?? [], 'from_email');
        if (is_string($fromEmail) && $fromEmail !== '' && $recipientEmail === strtolower(trim($fromEmail))) {
            return true;
        }

        // Check if the recipient's domain is an internal/staff domain
        if ($this->isInternalDomain($recipientEmail)) {
            return true;
        }

        return false;
    }

    /**
     * Ignore opens/clicks whose originating IP belongs to a staff/sender network.
     *
     * Mailtrap attributes an open to the original recipient, but reports the IP of
     * whoever actually loaded the tracking pixel. When a sender views their own
     * "sent" copy, the recipient's pixel fires from the staff network, producing a
     * false "opened" event. We compare the event IP against the per-message
     * sender_ip and any known internal networks (config + recorded sender IPs).
     */
    protected function shouldIgnoreAsSenderIpOpen(string $eventType, ?string $eventIp, ?EmailTracking $sent): bool
    {
        if (! (bool) config('email_tracking.filter_sender_ip_opens', true)) {
            return false;
        }

        if (! (bool) config('email_tracking.mailtrap_filter_sender_opens', true)) {
            return false;
        }

        if (! in_array($eventType, ['opened', 'link_clicked'], true)) {
            return false;
        }

        if (! is_string($eventIp) || trim($eventIp) === '') {
            return false;
        }

        $eventIp = trim($eventIp);

        $senderIp = Arr::get($sent?->metadata ?? [], 'sender_ip');
        if (is_string($senderIp) && $senderIp !== '' && IpNetworkMatcher::sameSenderNetwork($eventIp, $senderIp)) {
            return true;
        }

        foreach ($this->internalSenderNetworks() as $network) {
            if (str_contains($network, '/')) {
                if (IpNetworkMatcher::inCidr($eventIp, $network)) {
                    return true;
                }
            } elseif (IpNetworkMatcher::sameSenderNetwork($eventIp, $network)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the set of known internal/sender networks: explicit CIDRs from config
     * plus distinct sender_ip values recorded on tracked 'sent' events.
     *
     * @return list<string>
     */
    protected function internalSenderNetworks(): array
    {
        if ($this->internalSenderNetworks !== null) {
            return $this->internalSenderNetworks;
        }

        $networks = array_values(array_filter(
            (array) config('email_tracking.internal_ip_networks', []),
            static fn ($value): bool => is_string($value) && trim($value) !== ''
        ));

        $recordedSenderIps = EmailTracking::query()
            ->where('event_type', 'sent')
            ->whereNotNull('metadata->sender_ip')
            ->orderByDesc('id')
            ->limit(1000)
            ->pluck('metadata')
            ->map(static fn ($metadata) => Arr::get((array) $metadata, 'sender_ip'))
            ->filter(static fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(static fn (string $value): string => trim($value))
            ->all();

        $this->internalSenderNetworks = array_values(array_unique(array_merge($networks, $recordedSenderIps)));

        return $this->internalSenderNetworks;
    }

    /**
     * Check if an email address belongs to an internal/staff domain.
     */
    protected function isInternalDomain(string $email): bool
    {
        $internalDomains = (array) config('email_tracking.internal_domains', []);

        if (empty($internalDomains)) {
            return false;
        }

        $emailDomain = Str::after($email, '@');

        if ($emailDomain === '' || $emailDomain === $email) {
            return false;
        }

        $emailDomain = strtolower($emailDomain);

        foreach ($internalDomains as $internalDomain) {
            if (! is_string($internalDomain) || $internalDomain === '') {
                continue;
            }

            if ($emailDomain === strtolower(trim($internalDomain))) {
                return true;
            }
        }

        return false;
    }

    protected function shouldIgnoreAsNonRecipient(string $eventType, ?string $recipientEmail, ?EmailTracking $sent): bool
    {
        if (! in_array($eventType, ['opened', 'link_clicked'], true)) {
            return false;
        }

        if (! $sent) {
            // If we couldn't match a 'sent' row, we can't reliably assert who the intended recipients were.
            return false;
        }

        if (! is_string($recipientEmail) || trim($recipientEmail) === '') {
            return true;
        }

        $recipientEmail = strtolower(trim($recipientEmail));

        $sentRecipients = is_array($sent->recipient_emails) ? $sent->recipient_emails : [];
        $sentRecipients = collect($sentRecipients)
            ->filter(fn ($email) => is_string($email) && trim($email) !== '')
            ->map(fn ($email) => strtolower(trim($email)))
            ->unique()
            ->values()
            ->all();

        return ! in_array($recipientEmail, $sentRecipients, true);
    }

    protected function findBestSentMatch(
        ?string $trackingId,
        ?string $providerMessageId,
        ?string $recipientEmail,
        ?CarbonImmutable $eventAt,
    ): ?EmailTracking {
        if (is_string($trackingId) && $trackingId !== '') {
            return EmailTracking::query()
                ->where('event_type', 'sent')
                ->where('message_id', $trackingId)
                ->orderByDesc('event_at')
                ->first();
        }

        if (is_string($providerMessageId) && $providerMessageId !== '') {
            $byProviderId = EmailTracking::query()
                ->where('event_type', 'sent')
                ->where('metadata->mailtrap_message_id', $providerMessageId)
                ->orderByDesc('event_at')
                ->first();

            if ($byProviderId) {
                return $byProviderId;
            }

            $byProviderIdArray = EmailTracking::query()
                ->where('event_type', 'sent')
                ->whereJsonContains('metadata->mailtrap_message_ids', $providerMessageId)
                ->orderByDesc('event_at')
                ->first();

            if ($byProviderIdArray) {
                return $byProviderIdArray;
            }
        }

        if (! $recipientEmail || $eventAt === null) {
            return null;
        }

        return EmailTracking::query()
            ->where('event_type', 'sent')
            ->whereJsonContains('recipient_emails', $recipientEmail)
            ->whereBetween('event_at', [$eventAt->subMinutes(10), $eventAt->addMinutes(2)])
            ->orderByDesc('event_at')
            ->first();
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    protected function normalizeEvents(array $payload): array
    {
        if (isset($payload['events']) && is_array($payload['events'])) {
            return array_values(array_filter($payload['events'], 'is_array'));
        }

        if (isset($payload[0]) && is_array($payload[0])) {
            return array_values(array_filter($payload, 'is_array'));
        }

        return [$payload];
    }

    /**
     * @return array{0:string,1:string,2:?string,3:?string,4:?\Carbon\CarbonImmutable,5:?string,6:array<string,mixed>}|null
     */
    protected function normalizeEvent(array $event): ?array
    {
        $eventName = $this->getString($event, ['event_type', 'event', 'type', 'name']);

        $eventType = 'mailtrap_webhook_received';
        if ($eventName !== null) {
            $eventType = match (strtolower($eventName)) {
                'open', 'opened' => 'opened',
                'click', 'clicked', 'link_click', 'link_clicked' => 'link_clicked',
                'delivery', 'delivered' => 'delivered',
                'bounce', 'bounced' => 'bounced',
                default => 'mailtrap_webhook_received',
            };
        }

        $recipientEmail = $this->getString($event, ['email', 'recipient', 'recipient_email']);
        $providerMessageId = $this->getString($event, ['message_id', 'messageId', 'mailtrap_message_id']);
        $linkUrl = $this->getString($event, ['url', 'link', 'link_url']);

        $customVariables = Arr::get($event, 'custom_variables');
        if (! is_array($customVariables)) {
            $customVariables = Arr::get($event, 'customVariables');
        }
        if (! is_array($customVariables)) {
            $customVariables = [];
        }

        $trackingId = $this->getString($customVariables, [
            'tracking_id',
            'trackingId',
            // Some integrations prefix variables (we already use this prefix in outbound headers).
            'prefix_tracking_id',
            'prefixTrackingId',
            'custom_variables_prefix_tracking_id',
            'customVariablesPrefixTrackingId',
        ]);

        if (! $trackingId) {
            $trackingId = $this->getString($event, ['tracking_id', 'trackingId']);
        }

        $correlationId = $trackingId
            ?? ($providerMessageId ? 'mailtrap:' . $providerMessageId : (string) Str::uuid());

        $timestamp = Arr::get($event, 'timestamp');
        $eventAt = $this->parseEventAt($timestamp);

        $metadata = [
            'tracking_id' => $trackingId,
            'project_id' => $this->getInt($customVariables, ['project_id', 'projectId']),
            'estimate_id' => $this->getInt($customVariables, ['estimate_id', 'estimateId']),
            'email_template_name' => $this->getString($customVariables, ['email_template_name', 'template']),
            'mailtrap_event_name' => $eventName,
            // Keep a trimmed copy of the incoming event for debugging.
            'mailtrap_event' => Arr::only($event, [
                'event', 'type', 'name',
                'email', 'recipient', 'recipient_email',
                'message_id', 'messageId',
                'event_id', 'eventId',
                'ip',
                'user_agent',
                'url', 'link', 'link_url',
                'timestamp',
                'custom_variables', 'customVariables',
            ]),
        ];

        return [$correlationId, $eventType, $recipientEmail, $linkUrl, $eventAt, $providerMessageId, array_filter($metadata, static fn ($v) => $v !== null)];
    }

    /**
     * @param array<int,string> $keys
     */
    protected function getString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = Arr::get($data, $key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<int,string> $keys
     */
    protected function getInt(array $data, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = Arr::get($data, $key);
            if (is_int($value)) {
                return $value;
            }
            if (is_string($value) && $value !== '' && ctype_digit($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * Check if the recipient email belongs to a team member (employee) of the given vendor.
     * Only applies to opened/link_clicked events.
     */
    protected function shouldIgnoreAsVendorTeamMember(string $eventType, ?string $recipientEmail, ?int $vendorId): bool
    {
        if (! in_array($eventType, ['opened', 'link_clicked'], true)) {
            return false;
        }

        if (! is_string($recipientEmail) || trim($recipientEmail) === '' || $vendorId === null) {
            return false;
        }

        $recipientEmail = strtolower(trim($recipientEmail));

        $teamEmails = $this->getVendorTeamEmails($vendorId);

        return in_array($recipientEmail, $teamEmails, true);
    }

    /**
     * Get all email addresses for a vendor's team members (cached per request).
     *
     * @return list<string>
     */
    protected function getVendorTeamEmails(int $vendorId): array
    {
        if (array_key_exists($vendorId, $this->vendorTeamEmailCache)) {
            return $this->vendorTeamEmailCache[$vendorId];
        }

        $emails = User::query()
            ->whereHas('vendors', fn ($q) => $q->where('vendors.id', $vendorId))
            ->whereNotNull('email')
            ->pluck('email')
            ->map(fn (string $email): string => strtolower(trim($email)))
            ->filter(fn (string $email): bool => $email !== '')
            ->unique()
            ->values()
            ->all();

        $this->vendorTeamEmailCache[$vendorId] = $emails;

        return $emails;
    }

    protected function parseEventAt(mixed $timestamp): ?CarbonImmutable
    {
        if (is_int($timestamp)) {
            return CarbonImmutable::createFromTimestampUTC($timestamp);
        }

        if (is_string($timestamp) && $timestamp !== '') {
            try {
                // Accept RFC3339 / ISO8601 strings.
                return CarbonImmutable::parse($timestamp)->utc();
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    protected function excludedRecipientEmails(): array
    {
        return collect((array) config('email_tracking.excluded_recipients', []))
            ->filter(fn ($email) => is_string($email) && trim($email) !== '')
            ->map(fn (string $email): string => strtolower(trim($email)))
            ->unique()
            ->values()
            ->all();
    }

    protected function isExcludedRecipient(?string $email): bool
    {
        if (! is_string($email) || trim($email) === '') {
            return false;
        }

        return in_array(strtolower(trim($email)), $this->excludedRecipientEmails(), true);
    }
}
