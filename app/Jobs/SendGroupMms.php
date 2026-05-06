<?php

namespace App\Jobs;

use App\Events\SmsMessageReceived;
use App\Models\SmsMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Intervention\Image\Facades\Image;

class SendGroupMms implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public int $messageId,
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new ThrottlesExceptions(3, 60),
        ];
    }

    public function handle(): void
    {
        $message = SmsMessage::find($this->messageId);

        if (! $message || $message->status === 'sent') {
            return;
        }

        // Rate limit: 1 Group MMS per 5 seconds per thread.
        // Prevents AT&T carrier-level throttling on 10DLC.
        $lockKey = 'sms-send-thread:' . $message->thread_id;
        $lock = Cache::lock($lockKey, 5);

        if (! $lock->get()) {
            // Another message is being sent to this thread — retry after 5s
            $this->release(5);

            return;
        }

        $apiKey = config('services.telnyx.api_key');
        $messagingProfileId = config('services.telnyx.messaging_profile_id');
        $participants = $message->to_numbers ?? [];
        $text = $message->text;

        // Split media: videos/audio go out as a public signed link in the text body
        // (carrier MMS caps make videos useless; recipients get full quality via link).
        // Images stay as media_urls (Telnyx auto-transcoding handles size).
        [$mediaUrls, $linkifiedText] = $this->splitMediaForDelivery($message->media_urls ?? [], $text);
        $text = $linkifiedText;

        // In dev, redirect all outbound SMS to the dev number
        if (app()->environment(['local', 'development']) && ($devTo = config('services.telnyx.dev_to'))) {
            Log::channel('telnyx')->info('Dev environment: redirecting group SMS', [
                'original_participants' => $participants,
                'redirected_to' => $devTo,
            ]);
            $participants = [$devTo];
        }

        try {
            if (! empty($mediaUrls)) {
                // Telnyx limits each MMS to 1 MB total media.
                // Send each image as a separate MMS; attach the text to the first one only.
                $results = [];

                foreach ($mediaUrls as $i => $singleUrl) {
                    $msgText = ($i === 0) ? $text : '';
                    $results[] = $this->sendGroupMms(
                        $apiKey, $messagingProfileId, $message->from_number, $participants, $msgText, [$singleUrl]
                    );

                    // Small delay between sends to avoid carrier throttling
                    if ($i < count($mediaUrls) - 1) {
                        usleep(500_000); // 500ms
                    }
                }

                $result = $results[0];
            } elseif (count($participants) > 1) {
                $result = $this->sendGroupMms($apiKey, $messagingProfileId, $message->from_number, $participants, $text, []);
            } else {
                $result = $this->sendSms($apiKey, $messagingProfileId, $message->from_number, $participants[0], $text);
            }

            $message->update([
                'provider_message_id' => $result['id'] ?? null,
                'status' => 'sent',
                'raw_payload' => $result,
            ]);
        } catch (\Exception $e) {
            Log::channel('telnyx')->error('Failed to send group message', [
                'message_id' => $this->messageId,
                'to' => $participants,
                'error' => $e->getMessage(),
            ]);

            $message->update(['status' => 'failed']);
        }

        // Broadcast update so conversation refreshes with the final status
        if ($message->thread_id) {
            try {
                SmsMessageReceived::dispatch($message->thread_id);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('SMS broadcast failed', [
                    'thread_id' => $message->thread_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function sendGroupMms(string $apiKey, ?string $messagingProfileId, string $from, array $to, string $text, array $mediaUrls = []): array
    {
        $payload = [
            'from' => $from,
            'to' => $to,
            'text' => $text,
            'subject' => ' ',
        ];

        if (! empty($mediaUrls)) {
            $payload['media_urls'] = $mediaUrls;
        }

        if ($messagingProfileId) {
            $payload['messaging_profile_id'] = $messagingProfileId;
        }

        $response = Http::withToken($apiKey)
            ->post('https://api.telnyx.com/v2/messages/group_mms', $payload);

        if ($response->failed()) {
            throw new \RuntimeException('Telnyx Group MMS API error: ' . $response->body());
        }

        return $response->json('data') ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function sendSms(string $apiKey, ?string $messagingProfileId, string $from, string $to, string $text): array
    {
        $payload = [
            'from' => $from,
            'to' => $to,
            'text' => $text,
        ];

        if ($messagingProfileId) {
            $payload['messaging_profile_id'] = $messagingProfileId;
        }

        $response = Http::withToken($apiKey)
            ->post('https://api.telnyx.com/v2/messages', $payload);

        if ($response->failed()) {
            throw new \RuntimeException('Telnyx API error: ' . $response->body());
        }

        return $response->json('data') ?? [];
    }

    /**
     * Split a message's media into:
     *  - $mediaUrls: image URLs that go in the MMS payload (Telnyx will auto-transcode)
     *  - $newText:   original text plus a public signed link for each video/audio attachment
     *
     * @param  array<int, string>  $rawUrls
     * @return array{0: array<int, string>, 1: string}
     */
    protected function splitMediaForDelivery(array $rawUrls, ?string $text): array
    {
        $mediaUrls = [];
        $videoLinks = [];

        foreach ($rawUrls as $url) {
            if (SmsMessage::isVideoUrl($url) || SmsMessage::isAudioUrl($url)) {
                $link = $this->buildPublicSignedLink($url);
                if ($link) {
                    $videoLinks[] = $link;
                }
                continue;
            }

            $prepared = $this->prepareMediaUrl($url);
            if ($prepared !== '' && $prepared !== null) {
                $mediaUrls[] = $prepared;
            }
        }

        $newText = (string) $text;
        if (! empty($videoLinks)) {
            $prefix = $newText !== '' ? $newText . "\n\n" : '';
            $label = count($videoLinks) === 1 ? '📹 Watch: ' : '📹 Watch:';
            $newText = $prefix . $label . (count($videoLinks) === 1 ? $videoLinks[0] : "\n" . implode("\n", $videoLinks));
        }

        return [$mediaUrls, $newText];
    }

    /**
     * Build a 30-day signed URL pointing at the public sms-media endpoint
     * for any /storage/... or sms-media/... path. Returns null if URL is already external
     * or not under our private files disk.
     */
    protected function buildPublicSignedLink(string $url): ?string
    {
        if (str_starts_with($url, 'http')) {
            return $url;
        }

        $relative = ltrim(preg_replace('#^/?storage/#', '', $url) ?? '', '/');
        if ($relative === '') {
            return null;
        }

        // Files now live on the 'files' disk; bail if missing
        if (! Storage::disk('files')->exists($relative)) {
            Log::channel('telnyx')->warning('Signed link target missing on files disk', ['path' => $relative]);
            return null;
        }

        try {
            $signed = URL::signedRoute('sms.media.public', ['filename' => $relative], now()->addDays(30));
        } catch (\Throwable $e) {
            return null;
        }

        // Force HTTPS public host in dev so the link is reachable from the recipient's phone
        $appUrl = config('app.url');
        if (str_contains((string) $appUrl, '127.0.0.1') || str_contains((string) $appUrl, 'localhost')) {
            $publicUrl = config('services.telnyx.public_url');
            if ($publicUrl) {
                $parsed = parse_url($signed);
                $signed = rtrim($publicUrl, '/') . ($parsed['path'] ?? '') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
            }
        }

        return $signed;
    }

    /**
     * Compress a local image for MMS delivery (must be < 1 MB) and return a public URL.
     */
    protected function prepareMediaUrl(string $url): string
    {
        // Already an absolute external URL — pass through
        if (! str_starts_with($url, '/')) {
            return $url;
        }

        // Convert /storage/sms-attachments/file.jpg → sms-attachments/file.jpg
        $relativePath = preg_replace('#^/storage/#', '', $url);
        $disk = Storage::disk('public');

        if (! $disk->exists($relativePath)) {
            Log::channel('telnyx')->warning('MMS attachment not found on disk', ['path' => $relativePath]);

            return '';
        }

        $fullPath = $disk->path($relativePath);
        $fileSize = filesize($fullPath);

        // If already under 900 KB, no compression needed
        if ($fileSize <= 900 * 1024) {
            return $this->toPublicUrl($url);
        }

        // Compress for MMS delivery
        try {
            $compressedPath = $this->compressForMms($relativePath, $fullPath);

            return $this->toPublicUrl('/storage/' . $compressedPath);
        } catch (\Throwable $e) {
            Log::channel('telnyx')->error('Failed to compress MMS image, using original', [
                'path' => $relativePath,
                'error' => $e->getMessage(),
            ]);

            return $this->toPublicUrl($url);
        }
    }

    /**
     * Compress an image to fit within the Telnyx 1 MB MMS limit.
     * Saves a compressed copy to sms-attachments/mms/ and returns the relative path.
     */
    protected function compressForMms(string $relativePath, string $fullPath): string
    {
        $extension = pathinfo($fullPath, PATHINFO_EXTENSION) ?: 'jpg';
        $compressedRelative = 'sms-attachments/mms/' . pathinfo($relativePath, PATHINFO_FILENAME) . '_mms.' . $extension;
        $disk = Storage::disk('public');

        // If already compressed previously, reuse it
        if ($disk->exists($compressedRelative) && $disk->size($compressedRelative) <= 950 * 1024) {
            return $compressedRelative;
        }

        $image = Image::make($fullPath);

        // Step 1: Resize if dimensions are very large (keep aspect ratio)
        $maxDimension = 1200;
        if ($image->width() > $maxDimension || $image->height() > $maxDimension) {
            $image->resize($maxDimension, $maxDimension, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
        }

        // Step 2: Progressively lower quality until under 900KB
        $targetBytes = 900 * 1024;

        foreach ([75, 60, 45, 30] as $quality) {
            $encoded = (string) $image->encode('jpg', $quality);

            if (strlen($encoded) <= $targetBytes) {
                $disk->put($compressedRelative, $encoded);

                Log::channel('telnyx')->info('Compressed MMS image', [
                    'original' => $relativePath,
                    'compressed' => $compressedRelative,
                    'quality' => $quality,
                    'size_kb' => round(strlen($encoded) / 1024),
                ]);

                return $compressedRelative;
            }
        }

        // Step 3: Even smaller dimensions as last resort
        $image->resize(800, 800, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        });

        $encoded = (string) $image->encode('jpg', 30);
        $disk->put($compressedRelative, $encoded);

        Log::channel('telnyx')->info('Compressed MMS image (aggressive)', [
            'original' => $relativePath,
            'compressed' => $compressedRelative,
            'size_kb' => round(strlen($encoded) / 1024),
        ]);

        return $compressedRelative;
    }

    /**
     * Convert a relative /storage/... path to an absolute public URL.
     * Uses APP_URL, but falls back to the Telnyx-accessible public URL config if APP_URL is localhost.
     */
    protected function toPublicUrl(string $path): string
    {
        $appUrl = config('app.url');

        // If APP_URL is localhost (common in dev), use the public tunnel URL
        if (str_contains($appUrl, '127.0.0.1') || str_contains($appUrl, 'localhost')) {
            $publicUrl = config('services.telnyx.public_url');

            if ($publicUrl) {
                return rtrim($publicUrl, '/') . $path;
            }
        }

        return url($path);
    }
}
