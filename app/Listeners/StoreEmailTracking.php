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
        
        // Extract Nylas message ID from headers
        $headers = $message->getHeaders();
        $nylasMessageId = $headers->get('X-Nylas-Message-Id')?->getBodyAsString();
        $nylasThreadId = $headers->get('X-Nylas-Thread-Id')?->getBodyAsString();
        $metadataJson = $headers->get('X-Email-Metadata')?->getBodyAsString();

        if (!$nylasMessageId) {
            return;
        }

        $metadata = [];
        if ($metadataJson) {
            $metadata = json_decode($metadataJson, true) ?? [];
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

        // Create initial tracking record for each recipient (sent status)
        // This gives us the association before any opens/clicks happen
        foreach ($recipients as $recipientEmail) {
            EmailTracking::create([
                'project_id' => $projectId,
                'nylas_message_id' => $nylasMessageId,
                'nylas_thread_id' => $nylasThreadId,
                'email_template_name' => $emailTemplateName,
                'event_type' => 'sent',
                'recipient_emails' => [$recipientEmail],
                'metadata' => $metadata,
                'event_at' => now(),
            ]);
        }

        if ($projectId) {
            EmailTracking::query()
                ->where('nylas_message_id', $nylasMessageId)
                ->whereNull('project_id')
                ->update(['project_id' => $projectId]);
        }

    }
}
