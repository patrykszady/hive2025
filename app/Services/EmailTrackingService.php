<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class EmailTrackingService
{
    /**
     * Generate a signed tracking token for email opens.
     */
    public function generateTrackingToken(
        string $messageId,
        string $recipientEmail,
        ?int $projectId = null,
        ?string $threadId = null,
        ?string $emailTemplateName = null
    ): string {
        $data = [
            'mid' => $messageId,
            'r' => $recipientEmail,
        ];

        if ($projectId) {
            $data['pid'] = $projectId;
        }

        if ($threadId) {
            $data['tid'] = $threadId;
        }

        if ($emailTemplateName) {
            $data['tpl'] = $emailTemplateName;
        }

        // Add timestamp to make each URL unique (helps avoid caching)
        $data['ts'] = time();

        // Generate signature
        ksort($data);
        $signature = hash_hmac('sha256', json_encode($data), config('app.key'));
        $data['sig'] = $signature;

        return base64_encode(json_encode($data));
    }

    /**
     * Generate a tracking pixel URL for a specific recipient.
     */
    public function generateTrackingUrl(
        string $messageId,
        string $recipientEmail,
        ?int $projectId = null,
        ?string $threadId = null,
        ?string $emailTemplateName = null
    ): string {
        $token = $this->generateTrackingToken(
            $messageId,
            $recipientEmail,
            $projectId,
            $threadId,
            $emailTemplateName
        );

        return route('email.track.open', ['t' => $token]);
    }

    /**
     * Generate the tracking pixel HTML img tag.
     */
    public function generateTrackingPixelHtml(
        string $messageId,
        string $recipientEmail,
        ?int $projectId = null,
        ?string $threadId = null,
        ?string $emailTemplateName = null
    ): string {
        $url = $this->generateTrackingUrl(
            $messageId,
            $recipientEmail,
            $projectId,
            $threadId,
            $emailTemplateName
        );

        // Use a hidden 1x1 pixel image
        return sprintf(
            '<img src="%s" alt="" width="1" height="1" style="display:block;width:1px;height:1px;border:0;" />',
            htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Inject tracking pixel into HTML email body.
     * Adds a unique tracking pixel for each recipient.
     */
    public function injectTrackingPixel(
        string $htmlBody,
        string $messageId,
        string $recipientEmail,
        ?int $projectId = null,
        ?string $threadId = null,
        ?string $emailTemplateName = null
    ): string {
        $pixel = $this->generateTrackingPixelHtml(
            $messageId,
            $recipientEmail,
            $projectId,
            $threadId,
            $emailTemplateName
        );

        // Try to inject before </body> tag
        if (stripos($htmlBody, '</body>') !== false) {
            return preg_replace(
                '/<\/body>/i',
                $pixel . '</body>',
                $htmlBody,
                1
            );
        }

        // If no </body> tag, append to the end
        return $htmlBody . $pixel;
    }

    /**
     * Generate a unique pre-send message ID for tracking.
     * This is used when we don't yet have the Nylas message ID.
     */
    public function generatePreSendMessageId(): string
    {
        return 'pre_' . bin2hex(random_bytes(16));
    }
}
