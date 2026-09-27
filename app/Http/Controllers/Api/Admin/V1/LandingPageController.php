<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Models\LandingPage;
use App\Support\HiveLandingPageContentBuilder;
use App\Http\Controllers\Controller;
use SsSystems\Platform\Pages\Landing\Contracts\LandingPageContentBuilder;
use SsSystems\Platform\Pages\Landing\Http\Concerns\ServesLandingPages;

/**
 * Management API for ss-systems' Livewire\Admin\LandingPages screen —
 * hand-created `/lp/` campaign pages for ads. Same index/services/store/
 * publish/unpublish/destroy contract as gs.construction's and
 * dawnsellshomes.com's LandingPageController (ported from
 * dawnsellshomes' version), with everything Projects-shaped removed
 * rather than relaxed — see App\Support\HiveLandingPageContentBuilder.
 *
 * Now on top of the kit's shared ServesLandingPages trait (kit 0.13.0,
 * docs/CONSOLIDATION-PLAN.md — see docs/audit-2026-09-27/admin-api-verify.md
 * for why the naive "6-method contract" framing needed the extra hooks
 * below):
 *
 *  - no proof requirement of any kind (HiveLandingPageContentBuilder::
 *    requiresProofToPublish() => false) — Hive has no home-improvement
 *    Projects/proof domain, so content is assembled from small static
 *    templates, and publish() never blocks on it.
 *  - no sitemap regeneration after publish/unpublish — this app's
 *    sitemap (App\Support\MarketingSitemap) is a cached in-process read
 *    built from route definitions + config('marketing.areas'), and
 *    landing pages are deliberately never added to it (see LandingPage's
 *    class docblock) — nothing to regenerate, hence no
 *    afterPublishToggle() override.
 *  - never errors on a repeat city+campaign slug — auto-suffixes instead
 *    (onSlugCollision() => 'suffix'), unlike gsc's 'refuse'.
 */
class LandingPageController extends Controller
{
    use ServesLandingPages;

    public function __construct(private readonly LandingPageContentBuilder $contentBuilder = new HiveLandingPageContentBuilder) {}

    protected function landingPageModel(): string
    {
        return LandingPage::class;
    }

    protected function contentBuilder(): LandingPageContentBuilder
    {
        return $this->contentBuilder;
    }

    protected function onSlugCollision(): string
    {
        return 'suffix';
    }
}
