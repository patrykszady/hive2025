<?php

namespace App\Models;

use App\Observers\TestimonialObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SsSystems\Platform\Reviews\DisplayName;

/**
 * Customer reviews of Hive itself — the central admin's Reviews screen and
 * the "What contractors say" section on the marketing home page both read
 * this table. The table starts empty; nothing here is seeded.
 */
#[ObservedBy([TestimonialObserver::class])]
class Testimonial extends Model
{
    protected $fillable = [
        'name',
        'role',
        'body',
        'rating',
        'platform',
        'review_url',
        'external_id',
        'review_date',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_published' => 'boolean',
            'review_date' => 'date',
        ];
    }

    /**
     * The name the public page shows: "First L" (last-name initial only),
     * the same rule as gs.construction's — the admin's review form promises
     * exactly that ("Public pages will show 'First L' only"). "First & First
     * Last" becomes "First & First L"; a single word, or one already ending
     * in an initial, is left alone. The admin API still carries the full name.
     *
     * The rule itself now lives in the kit (SsSystems\Platform\Reviews\
     * DisplayName) — this app's own copy trimmed the name up front where
     * gsc/jpeterson's early-return branches did not; nothing here (or on any
     * other site) ever depended on that difference, so this now calls the
     * shared, gsc-sourced version.
     */
    public function getDisplayNameAttribute(): string
    {
        return DisplayName::from((string) $this->name);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * Management-API shape the central admin's Reviews screen reads (see
     * ss-systems' App\Livewire\Admin\TestimonialList/Form and gsc's
     * Testimonial::toApiArray(), the canonical contract), translated from
     * this table's own column names (name/role/body/rating/is_published).
     *
     * This app has no Projects domain, so — like dawnsellshomes.com —
     * there is no review_urls pivot (multi-platform links) or projects
     * relation (linked-project picker): review_urls/project_ids always
     * serialize empty, and public_url stays null (no per-review public
     * page exists here; published reviews render as a single "What
     * contractors say" section on the marketing home page). project_type
     * is always null for the same reason: this app has no per-review
     * "type" concept, only the imported role/company text
     * (project_location).
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'reviewer_name' => $this->name,
            'project_location' => $this->role,
            'project_type' => null,
            'review_description' => $this->body,
            'review_date' => optional($this->review_date)->format('Y-m-d'),
            'review_url' => $this->review_url,
            'external_id' => $this->external_id,
            'star_rating' => $this->rating,
            'is_hidden' => ! $this->is_published,
            'review_urls' => [],
            'project_ids' => [],
            'public_url' => null,
        ];
    }
}
