<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyEmail;
use App\Models\EmailTracking;
use App\Models\Project;
use App\Support\IpNetworkMatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        // If Nylas opens tracking is enabled, prefer the webhook-based opens.
        // In compare mode, we still record pixel opens as a separate event_type=opened_pixel.
        $nylasConfig = config('nylas');
        $nylasOpensEnabled = (bool) Arr::get($nylasConfig, 'tracking.opens', false);
        $compareOpens = (bool) Arr::get($nylasConfig, 'tracking.compare_opens', false);
        $pixelEventType = ($nylasOpensEnabled && $compareOpens) ? 'opened_pixel' : 'opened';

        if ($nylasOpensEnabled && ! $compareOpens) {
            return $this->transparentPixel();
        }

        $token = $request->query('t');

        if (!$token) {
            return $this->transparentPixel();
        }

        $data = $this->decodeTrackingToken($token);

        if (!$data) {
            Log::warning('Email tracking: Invalid token received', ['token' => substr($token, 0, 50)]);
            return $this->transparentPixel();
        }

        $this->recordOpenEvent($data, $request, $pixelEventType);

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
    protected function recordOpenEvent(array $data, Request $request, string $eventType = 'opened'): void
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

        $sentContext = $this->resolveSentContext((string) $messageId, $recipientEmail);
        $canonicalMessageId = $sentContext['message_id'] ?? (string) $messageId;
        $canonicalThreadId = $sentContext['thread_id'] ?? $threadId;
        $belongsToVendorId = $sentContext['belongs_to_vendor_id'] ?? null;
        $leadId = $sentContext['lead_id'] ?? null;

        if (! $projectId && isset($sentContext['project_id'])) {
            $projectId = $sentContext['project_id'];
        }

        if (! $emailTemplateName && isset($sentContext['email_template_name'])) {
            $emailTemplateName = $sentContext['email_template_name'];
        }

        if (! $belongsToVendorId && $projectId) {
            $belongsToVendorId = Project::query()
                ->whereKey($projectId)
                ->value('belongs_to_vendor_id');
        }

        // === SENDER DETECTION (multi-signal) ===
        $senderDetection = $this->detectSender($canonicalMessageId, $ipAddress, $recipientEmail);
        if ($senderDetection['is_sender']) {
            Log::debug('Email tracking: Skipping open - detected as sender', [
                'message_id' => $canonicalMessageId,
                'recipient' => $recipientEmail,
                'ip' => $ipAddress,
                'detection_reason' => $senderDetection['reason'],
            ]);
            return;
        }

        // === BOT/PREFETCH DETECTION ===
        $isPrefetch = $this->isPrefetchRequest($userAgent, $request);
        if ($isPrefetch) {
            return;
        }

        // === DUPLICATE DETECTION ===
        $recentOpenQuery = EmailTracking::query()
            ->where('message_id', $canonicalMessageId)
            ->where('event_type', $eventType)
            ->where('event_at', '>=', now()->subMinutes(5))
            ;

        if ($recipientEmail) {
            $recentOpenQuery->whereJsonContains('recipient_emails', $recipientEmail);
        }

        $recentOpen = $recentOpenQuery->exists();

        if ($recentOpen) {
            Log::debug('Email tracking: Duplicate open suppressed', [
                'message_id' => $messageId,
                'recipient' => $recipientEmail,
            ]);
            return;
        }

        // === RECORD THE OPEN ===
        $proxy = $this->detectImageProxy($userAgent);

        EmailTracking::create([
            'belongs_to_vendor_id' => $belongsToVendorId,
            'project_id' => $projectId,
            'lead_id' => $leadId,
            'message_id' => $canonicalMessageId,
            'thread_id' => $canonicalThreadId,
            'email_template_name' => $emailTemplateName,
            'event_type' => $eventType,
            'recipient_emails' => $recipientEmail ? [$recipientEmail] : null,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'metadata' => [
                'source' => 'tracking_pixel',
                'pre_send_tracking_id' => str_starts_with((string) $messageId, 'pre_') ? (string) $messageId : null,
                'image_proxy' => $proxy,
            ],
            'event_at' => now(),
        ]);

        Log::channel('nylas')->info('Email tracking: Open recorded', [
            'message_id' => $canonicalMessageId,
            'thread_id' => $canonicalThreadId,
            'recipient' => $recipientEmail,
            'project_id' => $projectId,
        ]);
    }

    /**
     * Resolve a pre-send tracking id (pre_...) into the real Nylas message/thread id.
     *
     * @return array{message_id?:string,thread_id?:string,project_id?:int,email_template_name?:string,belongs_to_vendor_id?:int}
     */
    protected function resolveSentContext(string $tokenMessageId, ?string $recipientEmail): array
    {
        $sent = EmailTracking::query()
            ->where('event_type', 'sent')
            ->where(function ($query) use ($tokenMessageId): void {
                $query
                    ->where('message_id', $tokenMessageId)
                    ->orWhere('metadata->pre_send_tracking_id', $tokenMessageId);
            })
            ->orderByDesc('event_at')
            ->first();

        if (! $sent) {
            return [];
        }

        if ($recipientEmail) {
            $recipientHit = is_array($sent->recipient_emails)
                && in_array($recipientEmail, $sent->recipient_emails, true);

            if (! $recipientHit) {
                return [];
            }
        }

        $context = [
            'message_id' => (string) ($sent->message_id ?? ''),
            'thread_id' => $sent->thread_id ? (string) $sent->thread_id : null,
            'project_id' => $sent->project_id ? (int) $sent->project_id : null,
            'lead_id' => $sent->lead_id ? (int) $sent->lead_id : null,
            'email_template_name' => $sent->email_template_name ? (string) $sent->email_template_name : null,
            'belongs_to_vendor_id' => $sent->belongs_to_vendor_id ? (int) $sent->belongs_to_vendor_id : null,
        ];

        return array_filter($context, static fn ($value) => $value !== null && $value !== '');
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
            ->where('message_id', $messageId)
            ->where('event_type', 'sent')
            ->first();

        if ($sentRecord) {
            $senderIp = $sentRecord->metadata['sender_ip'] ?? null;
            if (is_string($senderIp) && $senderIp !== '' && IpNetworkMatcher::sameSenderNetwork($ipAddress, $senderIp)) {
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

        // We do NOT treat email image proxies as prefetch.
        // Gmail/Yahoo/Outlook often proxy images for real human opens.

        $ua = trim($userAgent);

        // Check for generic bot user agent (just "Mozilla/5.0" with nothing else meaningful)
        if ($ua !== '' && preg_match('/^Mozilla\/5\.0\s*$/', $ua)) {
            return true;
        }

        // Link preview bots and crawlers (not email clients).
        $previewBots = [
            'Slackbot',
            'Discordbot',
            'Twitterbot',
            'facebookexternalhit',
            'WhatsApp',
            'TelegramBot',
            'SkypeUriPreview',
            'LinkedInBot',
            'Google-InspectionTool',
        ];

        foreach ($previewBots as $pattern) {
            if (stripos($ua, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    protected function detectImageProxy(string $userAgent): ?string
    {
        $ua = $userAgent;

        if (Str::contains($ua, 'GoogleImageProxy')) {
            return 'google_image_proxy';
        }

        if (Str::contains($ua, 'YahooMailProxy')) {
            return 'yahoo_mail_proxy';
        }

        if (Str::contains($ua, 'Outlook-iOS-Android')) {
            return 'outlook_mobile_proxy';
        }

        return null;
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
