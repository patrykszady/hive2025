<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * The app's sheet exports and bank statements are rendered while the user is
 * signed in (so tenant scopes apply), written under storage/app/exports and
 * handed back as a 10-minute signed link. Nothing reads them afterwards; this
 * removes any older than an hour.
 */
class PruneAppExports extends Command
{
    protected $signature = 'exports:prune {--minutes=60 : Delete files older than this}';

    protected $description = 'Delete app export files (sheets, bank statements) whose signed links have long expired.';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subMinutes(max(11, (int) $this->option('minutes')))->getTimestamp();
        $deleted = 0;

        foreach ($disk->allFiles('exports') as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $deleted++;
            }
        }

        $this->info("Deleted {$deleted} expired export file(s).");

        return self::SUCCESS;
    }
}
