<?php

namespace App\Console\Commands;

use App\Models\SmsMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RedownloadSmsMedia extends Command
{
    protected $signature = 'sms:redownload-media {--thread= : Only re-download for a specific thread} {--dry-run : Show what would be re-downloaded without downloading}';

    protected $description = 'Re-download missing SMS media files from original Telnyx URLs stored in raw_payload';

    public function handle(): int
    {
        $query = SmsMessage::query()
            ->whereNotNull('media_urls')
            ->where('media_urls', '!=', '[]')
            ->whereNotNull('raw_payload');

        if ($threadId = $this->option('thread')) {
            $query->where('thread_id', $threadId);
        }

        $messages = $query->orderBy('id')->get();
        $disk = Storage::disk('public');
        $fixed = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($messages as $message) {
            $mediaUrls = $message->media_urls;
            $rawPayload = is_string($message->raw_payload)
                ? json_decode($message->raw_payload, true)
                : $message->raw_payload;

            $originalMedia = $rawPayload['media'] ?? [];

            if (empty($originalMedia)) {
                continue;
            }

            $needsUpdate = false;
            $updatedUrls = [];

            foreach ($mediaUrls as $index => $localUrl) {
                // Skip external URLs (not local)
                if (! str_starts_with($localUrl, '/storage/')) {
                    $updatedUrls[] = $localUrl;
                    continue;
                }

                $relativePath = preg_replace('#^/storage/#', '', $localUrl);

                // File exists — skip
                if ($disk->exists($relativePath)) {
                    $updatedUrls[] = $localUrl;
                    $skipped++;
                    continue;
                }

                // Find matching original Telnyx URL
                $originalUrl = $originalMedia[$index]['url'] ?? null;

                // Skip if the original URL is our own server (outbound MMS)
                if (! $originalUrl || ! str_contains($originalUrl, 'tlnx-mms-media') && ! str_contains($originalUrl, 'telnyx')) {
                    $this->warn("  Message #{$message->id}: No Telnyx URL for index {$index}, original: " . ($originalUrl ?? 'none'));
                    $updatedUrls[] = $localUrl;
                    $failed++;
                    continue;
                }

                if ($this->option('dry-run')) {
                    $this->info("  Would download: {$originalUrl} → {$relativePath}");
                    $updatedUrls[] = $localUrl;
                    continue;
                }

                // Attempt download
                try {
                    $response = Http::timeout(30)->get($originalUrl);

                    if ($response->failed()) {
                        $this->error("  Message #{$message->id}: HTTP {$response->status()} for {$originalUrl}");
                        $updatedUrls[] = $localUrl;
                        $failed++;
                        continue;
                    }

                    $disk->put($relativePath, $response->body());
                    $updatedUrls[] = $localUrl;
                    $needsUpdate = false; // URL stays the same, just file was missing
                    $fixed++;
                    $this->info("  Message #{$message->id}: Downloaded {$relativePath}");
                } catch (\Exception $e) {
                    $this->error("  Message #{$message->id}: {$e->getMessage()}");
                    $updatedUrls[] = $localUrl;
                    $failed++;
                }
            }
        }

        $this->newLine();
        $this->info("Done. Fixed: {$fixed}, Failed: {$failed}, Already existed: {$skipped}");

        return self::SUCCESS;
    }
}
