<?php

namespace App\Support\Seo;

use SsSystems\Platform\Seo\Contracts\SeoSiteProfile;

/**
 * What the kit's SEO tools know about hive.contractors (2026-11-01, the
 * same wiring J. Peterson Design got the same day — see that site's
 * JpetersonSeoProfile for the pattern this follows). Unlike every other
 * adopter of this contract, hive.contractors is a SaaS marketing site for a
 * construction-management product, not a local service business with an
 * office or a service area:
 *
 *   - latitude()/longitude() are null on purpose — there is no map-pack
 *     grid to scan for a software product with no physical location, and
 *     seo:map-pack-grid refuses cleanly (its own "needs latitude(),
 *     longitude() and mapsQueries()" message) rather than being scheduled;
 *   - mapsQueries()/towns() are empty for the same reason — no served
 *     towns, no "near me" search to track;
 *   - trackedQueries() is empty on purpose, same as J. Peterson's: the kit
 *     derives tracked searches from this site's own Search Console top
 *     queries instead of a maintained list;
 *   - competitorExclusions() carries J. Peterson's aggregator list PLUS the
 *     software-review directories (G2, Capterra, GetApp, Software Advice,
 *     Product Hunt) that would otherwise be misread as competitors every
 *     time this site's name turns up next to them in a SERP or an AI
 *     answer.
 */
final class HiveSeoProfile implements SeoSiteProfile
{
    private const AGGREGATORS = [
        'yelp.com', 'houzz.com', 'angi.com', 'angieslist.com', 'homeadvisor.com', 'thumbtack.com', 'porch.com',
        'facebook.com', 'instagram.com', 'pinterest.com', 'linkedin.com', 'youtube.com', 'nextdoor.com',
        'bbb.org', 'yellowpages.com', 'mapquest.com', 'manta.com', 'buildzoom.com', 'bark.com', 'expertise.com',
        'wikipedia.org', 'reddit.com', 'zillow.com', 'realtor.com', 'redfin.com', 'architecturaldigest.com',
        'elledecor.com', 'veranda.com', 'housebeautiful.com', 'dwell.com', 'apartmenttherapy.com', 'chicagomag.com',
        // Software review/listing directories — this is a SaaS product, so
        // these show up beside it constantly and are never a competitor.
        'g2.com', 'capterra.com', 'getapp.com', 'softwareadvice.com', 'producthunt.com',
    ];

    public function domain(): string
    {
        return 'hive.contractors';
    }

    public function brandName(): string
    {
        return (string) config('brand.name', 'Hive Contractors');
    }

    public function brandPatterns(): array
    {
        return ['hive\s*contractors', 'hive\.contractors'];
    }

    public function competitorPatterns(): array
    {
        return [];
    }

    public function competitorExclusions(): array
    {
        return self::AGGREGATORS;
    }

    public function locationName(): string
    {
        return 'Chicago,Illinois,United States';
    }

    /** No office/service area to scan a map-pack grid for — see class docblock. */
    public function latitude(): ?float
    {
        return null;
    }

    public function longitude(): ?float
    {
        return null;
    }

    public function trackedQueries(): array
    {
        return [];
    }

    /** No service area, so no map-pack keywords. */
    public function mapsQueries(): array
    {
        return [];
    }

    /**
     * What hive.contractors sells, as search phrases — keyword-idea seeds
     * and AI-mention prompts. Drawn from config/marketing.php's feature
     * areas (Finances, Estimates & Documents, Vendors & Compliance,
     * Planning).
     */
    public function services(): array
    {
        return [
            'contractor estimating software',
            'construction project management software',
            'construction crm software',
            'lien waiver software',
            'construction scheduling software',
            'contractor invoicing software',
            'job costing software for contractors',
            'subcontractor compliance tracking',
        ];
    }

    /** No served towns — this is a SaaS product sold nationally, not a local business. */
    public function towns(): array
    {
        return [];
    }
}
