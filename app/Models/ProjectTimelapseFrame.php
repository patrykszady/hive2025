<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One frame of a project timelapse — gs.construction-compatible shape. The
 * difference here: frames live on the private 'files' disk and reach the
 * browser through an authed streaming route, never Storage::url().
 */
class ProjectTimelapseFrame extends Model
{
    protected $casts = ['shot_at' => 'datetime'];

    protected $fillable = [
        'project_timelapse_id',
        'taken_by_user_id',
        'filename',
        'original_filename',
        'path',
        'original_path',
        'aligned_path',
        'disk',
        'shot_at',
        'sort_order',
    ];

    public function timelapse(): BelongsTo
    {
        return $this->belongsTo(ProjectTimelapse::class, 'project_timelapse_id');
    }

    public function takenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_by_user_id')->withoutGlobalScopes();
    }

    /** What viewers should show: the registered copy when one exists. */
    public function getDisplayPathAttribute(): string
    {
        return $this->aligned_path ?: $this->path;
    }

    protected static function booted(): void
    {
        // All three copies go together — the archive original, the sequence
        // copy and the registered one. Leaving the original behind would
        // orphan a full-resolution file nothing can reach.
        static::deleting(function (self $frame) {
            $disk = Storage::disk($frame->disk);

            foreach ([$frame->path, $frame->original_path, $frame->aligned_path] as $path) {
                if ($path) {
                    $disk->delete($path);
                }
            }
        });
    }
}
