<?php

namespace App\Mail\Transport;

use App\Models\EmailTracking;
use App\Services\EmailTrackingService;
use App\Services\NylasService;
use Illuminate\Support\Facades\Log;
use JsonException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

class NylasTransport extends AbstractTransport
{
    public function __construct(
        protected NylasService $nylasService,
        protected string $grantId,
    ) {
        parent::__construct();
    }

    /**
     * Send the given message.
     */
    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        // Extract metadata from the original message if available
        $originalMessage = $message->getOriginalMessage();
        $metadata = $this->extractMetadata($email, $originalMessage);

        // Generate a pre-send tracking ID for tracking pixels
        $preSendTrackingId = $this->generatePreSendTrackingId();
        $metadata['pre_send_tracking_id'] = $preSendTrackingId;

        // Build payload with tracking pixels injected
        $payload = $this->buildPayload($email, $metadata, $preSendTrackingId);

        try {
            $response = $this->nylasService->sendEmail($this->grantId, $payload);

            if (!($response['success'] ?? false)) {
                throw new \Exception('Nylas API error: ' . ($response['error'] ?? 'Unknown error'));
            }

            $messageData = $response['data']['data'] ?? $response['data'];
            $messageId = is_array($messageData)
                ? ($messageData['id'] ?? $messageData['message_id'] ?? null)
                : null;
            $threadId = is_array($messageData)
                ? ($messageData['thread_id'] ?? null)
                : null;

            if ($threadId) {
                $metadata['nylas_thread_id'] = $threadId;
            }

            // Update any tracking records that used the pre-send ID with the real message ID
            if ($messageId && $preSendTrackingId) {
                EmailTracking::query()
                    ->where('nylas_message_id', $preSendTrackingId)
                    ->update([
                        'nylas_message_id' => $messageId,
                        'nylas_thread_id' => $threadId,
                    ]);
            }

            // Store message ID and metadata in the sent message for later retrieval
            $headers = $message->getOriginalMessage()->getHeaders();

            if ($messageId) {
                $headers->remove('X-Nylas-Message-Id');
                $headers->addTextHeader('X-Nylas-Message-Id', $messageId);
            }

            if (!empty($metadata)) {
                $headers->remove('X-Email-Metadata');
                $headers->addTextHeader('X-Email-Metadata', json_encode($metadata));
            }

            if ($threadId) {
                $headers->remove('X-Nylas-Thread-Id');
                $headers->addTextHeader('X-Nylas-Thread-Id', $threadId);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send email via Nylas', [
                'grant_id' => $this->grantId,
                'subject' => $email->getSubject(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Extract metadata payload from the outgoing message.
     */
    protected function extractMetadata(Email $email, object $originalMessage): array
    {
        $metadata = [];

        if (method_exists($originalMessage, 'getMetadata')) {
            $metadata = $originalMessage->getMetadata();
        }

        if (empty($metadata)) {
            $metadata = $this->decodeMetadataHeader($originalMessage->getHeaders()->get('X-Email-Metadata'));
        }

        if (empty($metadata)) {
            $metadata = $this->decodeMetadataHeader($email->getHeaders()->get('X-Email-Metadata'));
        }

        return is_array($metadata) ? $metadata : [];
    }

    /**
     * Decode metadata from a text header value.
     */
    protected function decodeMetadataHeader(?object $header): array
    {
        if (!$header) {
            return [];
        }

        $body = $header->getBodyAsString();

        if ($body === null || $body === '') {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Build the Nylas API payload from the Symfony email message.
     */
    protected function buildPayload(Email $email, array $metadata = [], ?string $preSendTrackingId = null): array
    {
        $recipients = $email->getTo();

        $payload = [
            'subject' => $email->getSubject(),
            'to' => $this->formatAddresses($recipients),
            'tracking_options' => [
                'opens' => false,  // Disable Nylas tracking - we use our own
                'links' => true,
                'thread_replies' => true,
            ],
        ];

        // From address
        if ($from = $email->getFrom()) {
            $payload['from'] = $this->formatAddresses($from);
        }

        // Reply-to
        if ($replyTo = $email->getReplyTo()) {
            $payload['reply_to'] = $this->formatAddresses($replyTo);
        }

        // CC
        if ($cc = $email->getCc()) {
            $payload['cc'] = $this->formatAddresses($cc);
        }

        // BCC
        if ($bcc = $email->getBcc()) {
            $payload['bcc'] = $this->formatAddresses($bcc);
        }

        // Body (HTML preferred, fallback to text)
        $htmlBody = $email->getHtmlBody();
        if (!$htmlBody && ($textBody = $email->getTextBody())) {
            $htmlBody = nl2br(e($textBody));
        }

        // Inject tracking pixels for each recipient
        if ($htmlBody && $preSendTrackingId) {
            $htmlBody = $this->injectTrackingPixels(
                $htmlBody,
                $preSendTrackingId,
                $recipients,
                $metadata
            );
        }

        if ($htmlBody) {
            $payload['body'] = $htmlBody;
        }

        // Attachments
        $attachments = [];
        foreach ($email->getAttachments() as $attachment) {
            $attachments[] = [
                'filename' => $attachment->getFilename() ?: 'attachment',
                'content_type' => $attachment->getContentType(),
                'content' => base64_encode($attachment->getBody()),
                'size' => strlen($attachment->getBody()),
            ];
        }

        if (!empty($attachments)) {
            $payload['attachments'] = $attachments;
        }

        return $payload;
    }

    /**
     * Inject tracking pixels into the HTML body for each recipient.
     */
    protected function injectTrackingPixels(string $htmlBody, string $messageId, array $recipients, array $metadata): string
    {
        $trackingService = app(EmailTrackingService::class);

        $projectId = $metadata['project_id'] ?? null;
        $threadId = $metadata['nylas_thread_id'] ?? null;
        $emailTemplateName = $metadata['email_template_name'] ?? null;

        // Collect all tracking pixels
        $pixels = '';
        foreach ($recipients as $address) {
            $recipientEmail = $address->getAddress();
            $pixels .= $trackingService->generateTrackingPixelHtml(
                $messageId,
                $recipientEmail,
                $projectId ? (int) $projectId : null,
                $threadId,
                $emailTemplateName
            );
        }

        // Inject before </body> tag if it exists
        if (stripos($htmlBody, '</body>') !== false) {
            return preg_replace(
                '/<\/body>/i',
                $pixels . '</body>',
                $htmlBody,
                1
            );
        }

        // Otherwise append to end
        return $htmlBody . $pixels;
    }

    /**
     * Generate a unique pre-send tracking ID.
     */
    protected function generatePreSendTrackingId(): string
    {
        return 'pre_' . bin2hex(random_bytes(16));
    }

    /**
     * Format email addresses for Nylas API.
     */
    protected function formatAddresses(array $addresses): array
    {
        return collect($addresses)->map(function (Address $address) {
            return [
                'email' => $address->getAddress(),
                'name' => $address->getName(),
            ];
        })->all();
    }

    /**
     * Get the string representation of the transport.
     */
    public function __toString(): string
    {
        return 'nylas';
    }
}
