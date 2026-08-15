<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * One frame of a project timelapse — gs.construction-compatible shape. The
 * difference here: frames live on the private 'files' disk and reach the
 * browser through an authed streaming route, never Storage::url().
 */
class ProjectTimelapseFrame extends Model
{
    use SoftDeletes;

    /**
     * Who may fetch the UNBLURRED, full-resolution, EXIF-bearing archive
     * copy: the person who took the photo, and Admins of the vendor that
     * owns the project it was taken on. Everyone else — including other
     * vendors' admins collaborating on the job — gets the blurred display
     * copy like every other viewer.
     */
    public function archiveVisibleTo(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($this->taken_by_user_id && $this->taken_by_user_id === $user->id) {
            return true;
        }

        $project = $this->timelapse?->project;

        return $project
            && $user->getRoleForVendor($project->belongs_to_vendor_id) === 'Admin';
    }

    protected $casts = [
        'align_transform' => 'array',
        'shot_at' => 'datetime',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    protected $fillable = [
        'project_timelapse_id',
        'taken_by_user_id',
        'taken_by_name',
        'filename',
        'original_filename',
        'path',
        'original_path',
        'aligned_path',
        'align_transform',
        'disk',
        'shot_at',
        'latitude',
        'longitude',
        'location_accuracy',
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

    /**
     * Cache-buster for the frame's URLs. The pixels behind one URL DO change —
     * a re-run of the aligner replaces the registered copy, or drops it and
     * falls back to the original — and the route answers `immutable`, so
     * without this a browser would keep showing the superseded image for a
     * week.
     */
    public function getVersionAttribute(): int
    {
        return (int) ($this->updated_at?->timestamp ?? 0);
    }

    /**
     * Who this photo came from, for captions: the linked user when there is
     * one, else the name recorded at import (a client who texted it).
     */
    public function getTakerNameAttribute(): ?string
    {
        return $this->takenBy?->nickname
            ?: $this->takenBy?->first_name
            ?: $this->taken_by_name;
    }

    protected static function booted(): void
    {
        // The archive original's address is a secret, not an id — every frame
        // gets one at birth so no code path can forget.
        static::creating(function (self $frame) {
            $frame->archive_token ??= \Illuminate\Support\Str::random(48);
        });

        // All three copies go together — the archive original, the sequence
        // copy and the registered one. Leaving the original behind would
        // orphan a full-resolution file nothing can reach. A SOFT delete
        // keeps every file: the frame is recoverable until force-deleted.
        static::deleting(function (self $frame) {
            if (! $frame->isForceDeleting()) {
                return;
            }

            $disk = Storage::disk($frame->disk);

            foreach ([$frame->path, $frame->original_path, $frame->aligned_path] as $path) {
                if ($path) {
                    $disk->delete($path);
                }
            }
        });
    }
}
