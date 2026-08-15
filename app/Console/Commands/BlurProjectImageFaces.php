<?php

namespace App\Console\Commands;

use App\Models\ProjectTimelapseFrame;
use App\Services\FaceBlur;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * One-time (and re-runnable) privacy backfill: blur faces on every DISPLAY
 * copy already in the system — sequence copies and aligned copies of every
 * project image and timelapse frame, soft-deleted ones included (they are
 * recoverable, so they count). `original-*` archives are never touched.
 *
 * Safe to re-run: files without faces are left byte-identical, and blurred
 * faces are no longer detected on a second pass.
 */
class BlurProjectImageFaces extends Command
{
    protected $signature = 'images:blur-faces
        {--project= : only this project id}
        {--chunk=16 : files per python invocation}';

    protected $description = 'Detect and blur faces on all project image display copies (originals untouched)';

    public function handle(): int
    {
        $frames = ProjectTimelapseFrame::withTrashed()
            ->when($this->option('project'), fn ($q, $id) => $q->whereHas(
                'timelapse',
                fn ($t) => $t->withTrashed()->where('project_id', $id),
            ))
            ->get();

        $work = [];   // absolute path => frame

        foreach ($frames as $frame) {
            $disk = Storage::disk($frame->disk);

            foreach ([$frame->path, $frame->aligned_path] as $relative) {
                if ($relative && $disk->exists($relative)) {
                    $work[$disk->path($relative)] = $frame;
                }
            }
        }

        $this->info(count($work).' display files across '.$frames->count().' frames');

        $totalFaces = 0;
        $touched = [];

        foreach (array_chunk(array_keys($work), max(1, (int) $this->option('chunk'))) as $chunk) {
            $results = FaceBlur::blur(...$chunk);

            foreach ($chunk as $path) {
                $count = $results[basename($path)] ?? 0;

                if (is_int($count) && $count > 0) {
                    $totalFaces += $count;
                    $touched[$work[$path]->id] = $work[$path];
                    $this->line(basename($path).': '.$count.' face(s) blurred');
                }
            }
        }

        // updated_at is the immutable-URL cache buster — a rewritten file
        // must get a fresh URL or browsers keep serving the cached face.
        foreach ($touched as $frame) {
            $frame->touch();
        }

        $this->info("Done: {$totalFaces} face(s) blurred across ".count($touched).' frames.');

        return self::SUCCESS;
    }
}
