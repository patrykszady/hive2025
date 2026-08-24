<?php

namespace App\Listeners;

use App\Models\EmailTracking;
use App\Models\Estimate;
use App\Models\Project;
use Illuminate\Mail\Events\MessageSent;

class StoreEmailTracking
{
    /**
     * Handle the event.
     */
    public function handle(MessageSent $event): void
    {
        $message = $event->message;
        
        // Extract correlation IDs from headers
        $headers = $message->getHeaders();

        // Internal record copies (office copy of an estimate or lead reply) ride
        // their own message so the client's tracking pixel stays theirs alone —
        // Mailtrap injects ONE pixel per message, so a same-envelope CC meant
        // that opening our own copy fired the client's pixel and the UI showed
        // "opened". The copy must not create a 'sent' row: without one, every
        // webhook event it generates is dropped as untracked.
        if ($headers->has('X-Hive-Internal-Copy')) {
            return;
        }
        $nylasMessageId = $headers->get('X-Nylas-Message-Id')?->getBodyAsString();
        $threadId = $headers->get('X-Nylas-Thread-Id')?->getBodyAsString();
        $metadataJson = $headers->get('X-Email-Metadata')?->getBodyAsString();

        $rfcMessageId = $headers->get('Message-ID')?->getBodyAsString() ?? $headers->get('Message-Id')?->getBodyAsString();
        if (is_string($rfcMessageId)) {
            $rfcMessageId = trim($rfcMessageId);
            $rfcMessageId = trim($rfcMessageId, "<>");
        } else {
            $rfcMessageId = null;
        }

        // Mailtrap correlation id (our own UUID)
        $trackingId = $headers->get('custom_variables_prefix_tracking_id')?->getBodyAsString();

        $metadata = [];
        if ($metadataJson) {
            $metadata = json_decode($metadataJson, true) ?? [];
        }

        $from = $message->getFrom();
        if (is_array($from) && isset($from[0])) {
            $fromEmail = $from[0]->getAddress();

            if (! isset($metadata['from_email'])) {
                $metadata['from_email'] = $fromEmail;
            }

            // Backward compatibility / fallback: if sender_email isn't explicitly set by the mailable,
            // default it to the actual From address.
            if (! isset($metadata['sender_email'])) {
                $metadata['sender_email'] = $fromEmail;
            }
        }

        if ($rfcMessageId) {
            $metadata['rfc_message_id'] = $rfcMessageId;
        }

        // The subject is what a human replies to. EmailReplyDetector matches
        // "Re: X" back to X when neither the Nylas thread nor the RFC headers
        // settle it, and that match needs the sent subject on record.
        $subject = trim((string) $message->getSubject());
        if ($subject !== '' && ! isset($metadata['subject'])) {
            $metadata['subject'] = \Illuminate\Support\Str::limit($subject, 500, '');
        }

        // Mailtrap's Symfony transport overwrites the *SentMessage* messageId with Mailtrap's provider message id(s).
        // Persist it so incoming Mailtrap webhooks can be relinked even if custom variables are missing.
        $providerMessageIdRaw = '';
        try {
            $providerMessageIdRaw = (string) $event->sent->getMessageId();
        } catch (\Throwable) {
            $providerMessageIdRaw = '';
        }

        $providerMessageIdRaw = trim($providerMessageIdRaw);
        if ($providerMessageIdRaw !== '') {
            $providerMessageIds = array_values(array_filter(array_map('trim', explode(',', trim($providerMessageIdRaw, "<>"))), static fn ($v) => $v !== ''));

            if (! empty($providerMessageIds)) {
                $metadata['mailtrap_message_id'] = $providerMessageIds[0];
                $metadata['mailtrap_message_ids'] = $providerMessageIds;
            }
        }

        if (! $trackingId && isset($metadata['tracking_id']) && is_string($metadata['tracking_id'])) {
            $trackingId = $metadata['tracking_id'];
        }

        $correlationId = $nylasMessageId ?: $trackingId;

        if (! $correlationId && isset($metadata['mailtrap_message_id']) && is_string($metadata['mailtrap_message_id']) && $metadata['mailtrap_message_id'] !== '') {
            $correlationId = 'mailtrap:' . $metadata['mailtrap_message_id'];
        }

        if (! $correlationId) {
            return;
        }

        if (! $threadId && isset($metadata['thread_id']) && is_string($metadata['thread_id'])) {
            $threadId = $metadata['thread_id'];
        }

        if (! $threadId && isset($metadata['nylas_thread_id']) && is_string($metadata['nylas_thread_id'])) {
            $threadId = $metadata['nylas_thread_id'];
        }

        if ($threadId) {
            $metadata['thread_id'] = $threadId;
            $metadata['nylas_thread_id'] = $threadId;
        }

        $projectId = $metadata['project_id'] ?? null;

        if ($projectId !== null) {
            $projectId = (int) $projectId ?: null;
        }

        if (!$projectId && isset($metadata['estimate_id'])) {
            $projectId = Estimate::query()
                ->whereKey($metadata['estimate_id'])
                ->value('project_id');
        }

        $leadId = $metadata['lead_id'] ?? null;
        if ($leadId !== null) {
            $leadId = (int) $leadId ?: null;
        }

        // Get recipients
        $recipients = [];
        if ($to = $message->getTo()) {
            foreach ($to as $address) {
                $recipients[] = $address->getAddress();
            }
        }

        if ($cc = $message->getCc()) {
            foreach ($cc as $address) {
                $recipients[] = $address->getAddress();
            }
        }

        if ($bcc = $message->getBcc()) {
            foreach ($bcc as $address) {
                $recipients[] = $address->getAddress();
            }
        }

        $recipients = array_values(array_unique(array_filter(array_map('strtolower', array_map('trim', $recipients)))));
        $excludedRecipients = $this->excludedRecipientEmails();
        $recipients = array_values(array_filter(
            $recipients,
            static fn (string $email): bool => ! in_array($email, $excludedRecipients, true)
        ));

        // Extract email template name from metadata
        $emailTemplateName = $metadata['email_template_name'] ?? null;

        $belongsToVendorId = $metadata['belongs_to_vendor_id'] ?? null;
        $belongsToVendorId = is_numeric($belongsToVendorId) ? (int) $belongsToVendorId : null;

        if (! $belongsToVendorId && $projectId) {
            $belongsToVendorId = Project::query()
                ->whereKey($projectId)
                ->value('belongs_to_vendor_id');
        }

        // Lead replies have no project — the vendor comes from the lead, else
        // the row is invisible behind EmailTrackingScope's vendor filter.
        if (! $belongsToVendorId && $leadId) {
            $belongsToVendorId = \App\Models\Lead::withoutGlobalScopes()
                ->whereKey($leadId)
                ->value('belongs_to_vendor_id');
        }

        // Create single tracking record with all recipients
        // This matches the webhook controller behavior and prevents duplicate "sent" events
        if (! empty($recipients)) {
            EmailTracking::create([
                'belongs_to_vendor_id' => $belongsToVendorId,
                'project_id' => $projectId,
                'lead_id' => $leadId,
                'message_id' => $correlationId,
                'thread_id' => $threadId,
                'email_template_name' => $emailTemplateName,
                'event_type' => 'sent',
                'recipient_emails' => $recipients,
                'metadata' => $metadata,
                'event_at' => now(),
            ]);
        }

        if ($projectId) {
            EmailTracking::query()
            ->where('message_id', $correlationId)
                ->whereNull('project_id')
                ->update(['project_id' => $projectId]);
        }

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
}
