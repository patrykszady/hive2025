<?php

namespace App\Jobs;

use App\Models\SmsMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StoreSmsMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $messageId) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $message = SmsMessage::find($this->messageId);

        if (! $message || empty($message->media_urls)) {
            return;
        }

        $storedUrls = [];

        foreach ($message->media_urls as $url) {
            try {
                $response = Http::timeout(30)->get($url);

                if ($response->failed()) {
                    Log::channel('telnyx')->error('Failed to download MMS media', [
                        'message_id' => $this->messageId,
                        'url' => $url,
                        'status' => $response->status(),
                    ]);
                    $storedUrls[] = $url;

                    continue;
                }

                $contentType = strtolower((string) $response->header('Content-Type'));
                $extension = match (true) {
                    str_contains($contentType, 'jpeg'), str_contains($contentType, 'jpg') => 'jpg',
                    str_contains($contentType, 'png') => 'png',
                    str_contains($contentType, 'gif') => 'gif',
                    str_contains($contentType, 'webp') => 'webp',
                    str_contains($contentType, 'heic') => 'heic',
                    str_contains($contentType, 'heif') => 'heif',
                    str_contains($contentType, 'mp4') => 'mp4',
                    str_contains($contentType, 'quicktime') => 'mov',
                    str_contains($contentType, '3gpp'), str_contains($contentType, '3gp') => '3gp',
                    str_contains($contentType, 'webm') => 'webm',
                    str_contains($contentType, 'matroska') => 'mkv',
                    str_contains($contentType, 'video/ogg') => 'ogv',
                    str_contains($contentType, 'video') => 'mp4',
                    str_contains($contentType, 'mpeg') && str_contains($contentType, 'audio') => 'mp3',
                    str_contains($contentType, 'audio/mp4') => 'm4a',
                    str_contains($contentType, 'aac') => 'aac',
                    str_contains($contentType, 'wav') => 'wav',
                    str_contains($contentType, 'audio/ogg') => 'ogg',
                    str_contains($contentType, 'amr') => 'amr',
                    str_contains($contentType, 'audio') => 'mp3',
                    str_contains($contentType, 'pdf') => 'pdf',
                    default => 'bin',
                };

                $filename = 'sms-media/' . now()->format('Y/m') . '/' . Str::uuid() . '.' . $extension;

                Storage::disk('files')->put($filename, $response->body());

                $storedUrls[] = $filename;

                // Queue browser-friendly transcode for video formats that Edge/Chrome
                // can't play natively (carrier MMS commonly delivers 3GPP/H.263).
                if (in_array($extension, ['3gp', 'mov', 'avi', 'mkv', 'amr'], true)) {
                    TranscodeSmsVideo::dispatch($this->messageId, $filename)->afterCommit();
                }
            } catch (\Exception $e) {
                Log::channel('telnyx')->error('Exception downloading MMS media', [
                    'message_id' => $this->messageId,
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                $storedUrls[] = $url;
            }
        }

        $message->update(['media_urls' => $storedUrls]);
    }
}
