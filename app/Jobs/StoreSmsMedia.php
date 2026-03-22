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

                $contentType = $response->header('Content-Type');
                $extension = match (true) {
                    str_contains($contentType, 'jpeg'), str_contains($contentType, 'jpg') => 'jpg',
                    str_contains($contentType, 'png') => 'png',
                    str_contains($contentType, 'gif') => 'gif',
                    str_contains($contentType, 'webp') => 'webp',
                    str_contains($contentType, 'mp4') => 'mp4',
                    str_contains($contentType, 'pdf') => 'pdf',
                    default => 'bin',
                };

                $filename = 'sms-media/' . now()->format('Y/m') . '/' . Str::uuid() . '.' . $extension;

                Storage::disk('public')->put($filename, $response->body());

                $storedUrls[] = '/storage/' . $filename;
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
