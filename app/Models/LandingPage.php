<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A hand-created ad-campaign landing page (`/lp/{slug}`) for Hive's own
 * marketing site — "Free Trial for Chicago Contractors", "Switch From
 * Spreadsheets", the kind of page a Google/Facebook ad points at. Same
 * admin API contract as gs.construction's demand-driven `/remodeling/`
 * pages and dawnsellshomes.com's hand-created `/lp/` pages (@see
 * /home/patryk/web/dawnsellshomes' App\Models\LandingPage, the reference
 * this was ported from), with the proof gate dropped entirely rather than
 * relaxed: Hive has no home-improvement Projects/proof domain of that
 * kind at all, so there is no proof_project_ids column and no hasProof()
 * to satisfy — see LandingPageController::publish().
 *
 * These pages are built to receive paid traffic, not to rank — so this
 * one's shouldIndex() is ALWAYS false regardless of publish state. Every
 * page also stays out of App\Support\MarketingSitemap, so it is never in
 * the sitemap either.
 *
 * @property string $slug
 * @property string $title
 * @property string $h1
 * @property string $service
 * @property string $city
 * @property string|null $modifier
 * @property array|null $sections
 * @property array|null $faq
 * @property string $status
 * @property string $source
 */
class LandingPage extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    /**
     * The fixed campaign-type catalogue — SaaS/CRM ad campaigns for
     * contractors, not gs.construction's remodeling trades or
     * dawnsellshomes' real-estate campaigns. Shared between
     * Api\Admin\V1\LandingPageController (the `services` endpoint +
     * buildContent()'s copy) and the public landing-page view (the
     * campaign label shown in the hero), so both read from one place
     * rather than keeping two copies in step.
     *
     * @var array<string, string>
     */
    public const CAMPAIGN_TYPES = [
        'free-trial' => 'Free Trial',
        'switch-from-spreadsheets' => 'Switch From Spreadsheets',
        'quickbooks-sync' => 'QuickBooks Sync',
        'receipt-automation' => 'Receipt Automation',
        'homeowner-portal' => 'Homeowner Portal',
        'lien-waivers' => 'Lien Waivers & Compliance',
        'subcontractor-crm' => 'Subcontractor CRM',
        'project-scheduling' => 'Project Scheduling',
        'general-contractor-software' => 'General Contractor Software',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'faq' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    /** Hive has no home-improvement Projects/proof domain — there is nothing to be proof of. */
    public function hasProof(): bool
    {
        return false;
    }

    /**
     * Always false: a campaign landing page built for paid traffic must
     * never be indexed, regardless of status. Unlike gs.construction
     * (proof-gated) or jpeterson-design (status + an admin `indexed`
     * flag), this app has no path to "yes, index this" at all — see the
     * public route in routes/web.php, which relies on the 'web' group's
     * own NoIndexNonPublic middleware to always send noindex here (/lp is
     * outside its isPublicPage() allowlist).
     */
    public function shouldIndex(): bool
    {
        return false;
    }

    public function url(): string
    {
        return url('/lp/'.$this->slug);
    }


    /**
     * Management-API shape — same key set as gs.construction's/
     * dawnsellshomes' LandingPage::toApiArray(), so ss-systems' shared
     * Landing Pages screen renders this site's rows unchanged. `indexed`/
     * `has_proof`/`proof_count` are always false/false/0: Hive has no
     * admin-settable indexing flag and no Projects-proof domain of that
     * kind to draw from — see the class docblock.
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'h1' => $this->h1,
            'service' => $this->service,
            'city' => $this->city,
            'modifier' => $this->modifier,
            'meta_description' => $this->meta_description,
            'intro' => $this->intro,
            'sections' => $this->sections,
            'faq' => $this->faq,
            'status' => $this->status,
            'source' => $this->source,
            'indexed' => false,
            'has_proof' => false,
            'should_index' => $this->shouldIndex(),
            'proof_count' => 0,
            'url' => '/lp/'.$this->slug,
            'published_at' => optional($this->published_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
