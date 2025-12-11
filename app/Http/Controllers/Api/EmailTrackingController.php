<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyEmail;
use App\Models\EmailTracking;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class EmailTrackingController extends Controller
{
    /**
     * Cache of company emails for sender detection.
     */
    protected static ?array $companyEmailsCache = null;

    /**
     * Handle tracking pixel request (email open).
     * Returns a 1x1 transparent GIF.
     */
    public function trackOpen(Request $request): Response
    {
        $token = $request->query('t');

        if (!$token) {
            return $this->transparentPixel();
        }

        $data = $this->decodeTrackingToken($token);

        if (!$data) {
            Log::warning('Email tracking: Invalid token received', ['token' => substr($token, 0, 50)]);
            return $this->transparentPixel();
        }

        $this->recordOpenEvent($data, $request);

        return $this->transparentPixel();
    }

    /**
     * Decode and validate the tracking token.
     */
    protected function decodeTrackingToken(string $token): ?array
    {
        try {
            $decoded = base64_decode($token, true);

            if ($decoded === false) {
                return null;
            }

            $data = json_decode($decoded, true);

            if (!is_array($data)) {
                return null;
            }

            // Validate signature
            $signature = $data['sig'] ?? null;
            unset($data['sig']);

            $expectedSignature = $this->generateSignature($data);

            if (!hash_equals($expectedSignature, $signature ?? '')) {
                Log::warning('Email tracking: Signature mismatch', ['data' => $data]);
                return null;
            }

            return $data;
        } catch (\Exception $e) {
            Log::error('Email tracking: Token decode error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Generate a signature for the tracking data.
     */
    protected function generateSignature(array $data): string
    {
        ksort($data);
        $payload = json_encode($data);

        return hash_hmac('sha256', $payload, config('app.key'));
    }

    /**
     * Record an open event in the database.
     */
    protected function recordOpenEvent(array $data, Request $request): void
    {
        $messageId = $data['mid'] ?? null;
        $recipientEmail = $data['r'] ?? null;
        $projectId = $data['pid'] ?? null;
        $threadId = $data['tid'] ?? null;
        $emailTemplateName = $data['tpl'] ?? null;

        if (!$messageId) {
            return;
        }

        $ipAddress = $request->ip();
        $userAgent = $request->userAgent() ?? '';

        // === SENDER DETECTION (multi-signal) ===
        $senderDetection = $this->detectSender($messageId, $ipAddress, $recipientEmail);
        if ($senderDetection['is_sender']) {
            Log::debug('Email tracking: Skipping open - detected as sender', [
                'message_id' => $messageId,
                'recipient' => $recipientEmail,
                'ip' => $ipAddress,
                'detection_reason' => $senderDetection['reason'],
            ]);
            return;
        }

        // === BOT/PREFETCH DETECTION ===
        $isPrefetch = $this->isPrefetchRequest($userAgent, $request);
        if ($isPrefetch) {
            Log::debug('Email tracking: Skipping prefetch/bot open', [
                'message_id' => $messageId,
                'recipient' => $recipientEmail,
                'ip' => $ipAddress,
                'user_agent' => substr($userAgent, 0, 100),
            ]);
            return;
        }

        // === DUPLICATE DETECTION ===
        $recentOpen = EmailTracking::query()
            ->where('nylas_message_id', $messageId)
            ->where('event_type', 'opened')
            ->where('recipient_emails', json_encode([$recipientEmail]))
            ->where('event_at', '>=', now()->subMinutes(5))
            ->exists();

        if ($recentOpen) {
            Log::debug('Email tracking: Duplicate open suppressed', [
                'message_id' => $messageId,
                'recipient' => $recipientEmail,
            ]);
            return;
        }

        // === RECORD THE OPEN ===
        EmailTracking::create([
            'project_id' => $projectId,
            'nylas_message_id' => $messageId,
            'nylas_thread_id' => $threadId,
            'email_template_name' => $emailTemplateName,
            'event_type' => 'opened',
            'recipient_emails' => $recipientEmail ? [$recipientEmail] : null,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'metadata' => [
                'source' => 'tracking_pixel',
            ],
            'event_at' => now(),
        ]);

        Log::info('Email tracking: Open recorded', [
            'message_id' => $messageId,
            'recipient' => $recipientEmail,
            'project_id' => $projectId,
        ]);
    }

    /**
     * Multi-signal sender detection.
     * 
     * Returns ['is_sender' => bool, 'reason' => string|null]
     */
    protected function detectSender(string $messageId, string $ipAddress, ?string $recipientEmail): array
    {
        // Signal 1: IP matches sender IP from when email was sent
        $sentRecord = EmailTracking::query()
            ->where('nylas_message_id', $messageId)
            ->where('event_type', 'sent')
            ->first();

        if ($sentRecord) {
            $senderIp = $sentRecord->metadata['sender_ip'] ?? null;
            if ($senderIp && $senderIp === $ipAddress) {
                return ['is_sender' => true, 'reason' => 'ip_matches_sender'];
            }
        }

        // Signal 2: Recipient email matches a company email exactly
        // (Someone viewing an email they sent to themselves/company)
        if ($recipientEmail && $this->isCompanyEmail($recipientEmail)) {
            return ['is_sender' => true, 'reason' => 'recipient_is_company_email'];
        }

        return ['is_sender' => false, 'reason' => null];
    }

    /**
     * Check if an email address is a company email.
     */
    protected function isCompanyEmail(string $email): bool
    {
        if (self::$companyEmailsCache === null) {
            self::$companyEmailsCache = CompanyEmail::query()
                ->pluck('email')
                ->map(fn ($e) => strtolower($e))
                ->toArray();
        }

        return in_array(strtolower($email), self::$companyEmailsCache, true);
    }

    /**
     * Detect if this is likely a prefetch/automated request.
     */
    protected function isPrefetchRequest(string $userAgent, Request $request): bool
    {
        // Check for prefetch headers
        if ($request->header('X-Purpose') === 'preview' ||
            $request->header('Purpose') === 'prefetch' ||
            $request->header('Sec-Purpose') === 'prefetch') {
            return true;
        }

        // Known prefetch/bot user agents
        $prefetchPatterns = [
            'GoogleImageProxy',
            'YahooMailProxy',
            'Outlook-iOS-Android',
        ];

        // Check for generic bot user agent (just "Mozilla/5.0" with nothing else meaningful)
        if (preg_match('/^Mozilla\/5\.0\s*$/', $userAgent)) {
            return true;
        }

        foreach ($prefetchPatterns as $pattern) {
            if (stripos($userAgent, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return a 1x1 transparent GIF pixel.
     */
    protected function transparentPixel(): Response
    {
        // 1x1 transparent GIF
        $pixel = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        return response($pixel, 200, [
            'Content-Type' => 'image/gif',
            'Content-Length' => strlen($pixel),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
        ]);
    }

    /**
     * Generate a tracking pixel URL for a specific recipient.
     * This is a static helper that can be called from email templates.
     */
    public static function generateTrackingUrl(
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

        $token = base64_encode(json_encode($data));

        return route('email.track.open', ['t' => $token]);
    }
}
