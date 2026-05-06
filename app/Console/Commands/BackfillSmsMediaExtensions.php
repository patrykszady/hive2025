<?php

namespace App\Console\Commands;

use App\Models\SmsMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BackfillSmsMediaExtensions extends Command
{
    protected $signature = 'sms:backfill-media-extensions {--dry-run : Show changes without applying} {--message= : Limit to a single message id} {--move-to-private : Move files from public to private storage}';

    protected $description = 'Sniff MIME of stored SMS media files with .bin (or empty) extensions and rename them with proper extensions, updating media_urls. Optionally move from public to private storage.';

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
        $moveToPrivate = (bool) $this->option('move-to-private');
        $publicDisk = Storage::disk('public');
        $privatesDisk = Storage::disk('files');

        $query = SmsMessage::query()->whereNotNull('media_urls');

        if ($id = $this->option('message')) {
            $query->where('id', $id);
        }

        $renamed = 0;
        $unchanged = 0;
        $missing = 0;
        $moved = 0;

        $query->orderBy('id')->chunkById(200, function ($messages) use ($publicDisk, $privatesDisk, $dryRun, $moveToPrivate, &$renamed, &$unchanged, &$missing, &$moved) {
            foreach ($messages as $message) {
                $urls = $message->media_urls ?? [];
                $changed = false;

                foreach ($urls as $i => $url) {
                    if (! str_starts_with($url, '/storage/') && ! $moveToPrivate) {
                        continue;
                    }

                    $relative = str_starts_with($url, '/storage/') ? substr($url, strlen('/storage/')) : null;

                    // Handle moving from public to private
                    if ($moveToPrivate && str_starts_with($url, '/storage/sms-')) {
                        $relative = substr($url, strlen('/storage/'));
                        $currentExt = strtolower(pathinfo($relative, PATHINFO_EXTENSION));

                        if (! $publicDisk->exists($relative)) {
                            $missing++;
                            $this->warn("Missing file in public (msg {$message->id}): {$relative}");

                            continue;
                        }

                        // Detect MIME and get proper extension
                        $absolute = $publicDisk->path($relative);
                        $mime = function_exists('mime_content_type') ? @mime_content_type($absolute) : null;

                        $newExt = $mime ? (self::MIME_MAP[strtolower($mime)] ?? null) : null;

                        if (! $newExt) {
                            $newExt = $currentExt === 'bin' || $currentExt === '' ? 'bin' : $currentExt;
                        }

                        $newRelative = preg_replace('/\.[^.]*$/', '.' . $newExt, $relative);
                        if ($newRelative === $relative && ($currentExt === 'bin' || $currentExt === '')) {
                            $newRelative = $relative . '.' . $newExt;
                        }

                        $this->info("msg {$message->id}: moving public/{$relative} ({$mime}) → private/{$newRelative}");

                        if (! $dryRun) {
                            if (! $privatesDisk->exists($newRelative)) {
                                $content = $publicDisk->get($relative);
                                $privatesDisk->put($newRelative, $content);
                                $publicDisk->delete($relative);
                            }
                            $urls[$i] = $newRelative;
                            $changed = true;
                        }

                        $moved++;
                        $renamed++;

                        continue;
                    }

                    if (! $relative || ! str_starts_with($relative, 'sms-')) {
                        continue;
                    }

                    $currentExt = strtolower(pathinfo($relative, PATHINFO_EXTENSION));

                    if (! in_array($currentExt, ['bin', ''], true)) {
                        $unchanged++;

                        continue;
                    }

                    if (! $publicDisk->exists($relative)) {
                        $missing++;
                        $this->warn("Missing file (msg {$message->id}): {$relative}");

                        continue;
                    }

                    $absolute = $publicDisk->path($relative);
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
                        if ($publicDisk->exists($newRelative)) {
                            $this->warn('  target exists, skipping rename');
                        } else {
                            $publicDisk->move($relative, $newRelative);
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
        $this->info("Renamed: {$renamed}, Unchanged: {$unchanged}, Missing: {$missing}, Moved: {$moved}" . ($dryRun ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }
}
