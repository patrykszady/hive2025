<?php

namespace App\Console\Commands;

use App\Models\SmsMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BackfillSmsMediaExtensions extends Command
{
    protected $signature = 'sms:backfill-media-extensions {--dry-run : Show changes without applying} {--message= : Limit to a single message id}';

    protected $description = 'Sniff MIME of stored SMS media files with .bin (or empty) extensions and rename them with proper extensions, updating media_urls.';

    private const MIME_MAP = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'video/3gpp' => '3gp',
        'video/3gpp2' => '3gp',
        'video/webm' => 'webm',
        'video/x-matroska' => 'mkv',
        'video/ogg' => 'ogv',
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/aac' => 'aac',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/ogg' => 'ogg',
        'audio/amr' => 'amr',
        'application/pdf' => 'pdf',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $disk = Storage::disk('public');

        $query = SmsMessage::query()->whereNotNull('media_urls');

        if ($id = $this->option('message')) {
            $query->where('id', $id);
        }

        $renamed = 0;
        $unchanged = 0;
        $missing = 0;

        $query->orderBy('id')->chunkById(200, function ($messages) use ($disk, $dryRun, &$renamed, &$unchanged, &$missing) {
            foreach ($messages as $message) {
                $urls = $message->media_urls ?? [];
                $changed = false;

                foreach ($urls as $i => $url) {
                    if (! str_starts_with($url, '/storage/')) {
                        continue;
                    }

                    $relative = substr($url, strlen('/storage/'));
                    $currentExt = strtolower(pathinfo($relative, PATHINFO_EXTENSION));

                    if (! in_array($currentExt, ['bin', ''], true)) {
                        $unchanged++;

                        continue;
                    }

                    if (! $disk->exists($relative)) {
                        $missing++;
                        $this->warn("Missing file (msg {$message->id}): {$relative}");

                        continue;
                    }

                    $absolute = $disk->path($relative);
                    $mime = function_exists('mime_content_type') ? @mime_content_type($absolute) : null;

                    if (! $mime) {
                        $this->warn("Could not detect MIME (msg {$message->id}): {$relative}");
                        $unchanged++;

                        continue;
                    }

                    $newExt = self::MIME_MAP[strtolower($mime)] ?? null;

                    if (! $newExt || $newExt === $currentExt) {
                        $this->line("No mapping or already correct ({$mime}): {$relative}");
                        $unchanged++;

                        continue;
                    }

                    $newRelative = preg_replace('/\.[^.]*$/', '.' . $newExt, $relative);

                    if ($newRelative === $relative) {
                        $newRelative = $relative . '.' . $newExt;
                    }

                    $this->info("msg {$message->id}: {$relative} ({$mime}) -> {$newRelative}");

                    if (! $dryRun) {
                        if ($disk->exists($newRelative)) {
                            $this->warn('  target exists, skipping rename');
                        } else {
                            $disk->move($relative, $newRelative);
                        }
                        $urls[$i] = '/storage/' . $newRelative;
                        $changed = true;
                    }

                    $renamed++;
                }

                if ($changed) {
                    $message->update(['media_urls' => $urls]);
                }
            }
        });

        $this->newLine();
        $this->info("Renamed: {$renamed}, Unchanged: {$unchanged}, Missing: {$missing}" . ($dryRun ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }
}
