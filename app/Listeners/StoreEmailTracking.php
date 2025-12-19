<?php

namespace App\Listeners;

use App\Models\EmailTracking;
use App\Models\Estimate;
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
        $nylasMessageId = $headers->get('X-Nylas-Message-Id')?->getBodyAsString();
        $nylasThreadId = $headers->get('X-Nylas-Thread-Id')?->getBodyAsString();
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

        if ($rfcMessageId) {
            $metadata['rfc_message_id'] = $rfcMessageId;
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

        if (!$nylasThreadId && isset($metadata['nylas_thread_id'])) {
            $nylasThreadId = $metadata['nylas_thread_id'];
        }

        if ($nylasThreadId) {
            $metadata['nylas_thread_id'] = $nylasThreadId;
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

        // Get recipients
        $recipients = [];
        if ($to = $message->getTo()) {
            foreach ($to as $address) {
                $recipients[] = $address->getAddress();
            }
        }

        // Extract email template name from metadata
        $emailTemplateName = $metadata['email_template_name'] ?? null;

        // Create single tracking record with all recipients
        // This matches the webhook controller behavior and prevents duplicate "sent" events
        if (!empty($recipients)) {
            EmailTracking::create([
                'project_id' => $projectId,
                'nylas_message_id' => $correlationId,
                'nylas_thread_id' => $nylasThreadId,
                'email_template_name' => $emailTemplateName,
                'event_type' => 'sent',
                'recipient_emails' => $recipients,
                'metadata' => $metadata,
                'event_at' => now(),
            ]);
        }

        if ($projectId) {
            EmailTracking::query()
            ->where('nylas_message_id', $correlationId)
                ->whereNull('project_id')
                ->update(['project_id' => $projectId]);
        }

    }
}
