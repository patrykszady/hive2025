<?php

namespace App\Http\Controllers;

use App\Support\MarketingPageList;
use App\Support\Seo\AiFeed\HiveBusinessIdentity;
use App\Support\Seo\AiFeed\HiveContentCatalog;
use App\Support\Seo\AiFeed\HiveProjectCatalog;
use App\Support\Seo\AiFeed\HiveReviewCatalog;
use App\Support\Seo\AiFeed\HiveServiceAreaCatalog;
use App\Support\Seo\AiFeed\HiveServiceCatalog;
use Illuminate\Http\JsonResponse;
use SsSystems\Platform\Reports\Contracts\SiteIdentity;
use SsSystems\Platform\Seo\AiFeed\AiFeedBuilder;

/**
 * GET /ai-feed.json — the machine-readable business-facts feed for AI
 * crawlers (ChatGPT, Perplexity, Google AI Overviews, Claude), the one
 * gs.construction and jpeterson-design serve through the kit's
 * SsSystems\Platform\Seo\AiFeed\AiFeedBuilder (2026-11-10, Patryk: roll
 * the AI feeds to every site). This controller only wires this site's six
 * adapters together (App\Support\Seo\AiFeed\Hive*); the envelope shape, the
 * response headers and the 15-minute cache live in the kit. Linked from
 * /llms.txt and allowed in public/robots.txt.
 *
 * Every URL in the payload is rooted on the marketing host
 * (marketing_url(), never url()/APP_URL), like the sitemap and llms.txt —
 * the app and the marketing site are split across hosts.
 */
class AiFeedController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return $this->builder()->respond();
    }

    private function builder(): AiFeedBuilder
    {
        $services = new HiveServiceCatalog;

        return new AiFeedBuilder(
            business: new HiveBusinessIdentity,
            identity: app(SiteIdentity::class),
            services: $services,
            projects: new HiveProjectCatalog,
            content: new HiveContentCatalog,
            reviews: new HiveReviewCatalog,
            areas: new HiveServiceAreaCatalog($services),
            links: [
                'sitemap' => marketing_url('sitemap.xml'),
                'llms_txt' => marketing_url('llms.txt'),
                'llms_full' => marketing_url('llms-full.txt'),
                'geo_answers' => marketing_url('geo/answers.json'),
                'robots' => marketing_url('robots.txt'),
            ],
            extra: [
                // A nationwide product: the flat list gs.construction's feed
                // fills with its suburbs is the one country here.
                'service_area' => [(string) config('geo-answers.meta.service_area', 'United States')],
                // The same site in its other languages (config/locales.php):
                // the catalogs are English only, so the alternates ride here.
                'translations' => $this->translations(),
            ],
        );
    }

    /** @return array<string, string> locale => that language's home page */
    private function translations(): array
    {
        $translations = [];

        foreach (MarketingPageList::otherLanguages() as $language) {
            $translations[$language['locale']] = $language['url'];
        }

        return $translations;
    }
}
