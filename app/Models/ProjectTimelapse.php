<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A project's photo sequence — same shape as the gs.construction site's
 * model, so frames shot in Hive drop straight into the public site's
 * before/after/timelapse sliders.
 *
 * Deleting is SOFT: the collection row keeps its frames (untouched rows and
 * files), so a deleted timelapse is restorable wholesale.
 */
class ProjectTimelapse extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_id',
        'title',
        'display_mode',
        'kind',
        'sort_order',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withoutGlobalScopes();
    }

    public function frames(): HasMany
    {
        return $this->hasMany(ProjectTimelapseFrame::class)->orderBy('sort_order');
    }

    /**
     * The frame the sequence registers onto: the anchor chosen in the studio
     * ("Use as alignment anchor"), else the first frame by sort order.
     */
    public function anchorFrame(): ?ProjectTimelapseFrame
    {
        return ($this->anchor_frame_id ? $this->frames()->find($this->anchor_frame_id) : null)
            ?? $this->frames()->orderBy('id')->first();
    }

    /** The one sequence a project's camera writes into, created on demand. */
    /** A sequence: onion-skin capture, registration, playback. */
    public const KIND_TIMELAPSE = 'timelapse';

    /** Loose photos: upload and browse, no alignment, no playback. */
    public const KIND_GALLERY = 'gallery';

    public function isTimelapse(): bool
    {
        return $this->kind !== self::KIND_GALLERY;
    }

    /**
     * The catch-all album every project has: loose progress photos that
     * aren't part of any sequence. Always exists, always listed first.
     */
    public static function generalFor(Project $project): self
    {
        return static::firstOrCreate(
            ['project_id' => $project->id, 'title' => 'Project Images'],
            ['kind' => self::KIND_GALLERY, 'display_mode' => 'slider', 'sort_order' => 0],
        );
    }

    public static function defaultFor(Project $project): self
    {
        return static::firstOrCreate(
            ['project_id' => $project->id],
            ['title' => 'Timelapse', 'display_mode' => 'slider', 'sort_order' => 0],
        );
    }
}
