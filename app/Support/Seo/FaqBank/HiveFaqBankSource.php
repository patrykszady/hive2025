<?php

namespace App\Support\Seo\FaqBank;

use App\Models\BlogPost;
use App\Support\MarketingPageList;
use App\Support\MarketingPages;
use SsSystems\Platform\Seo\Faq\Bank\Contracts\FaqBankSource;

/**
 * What hive.contractors hands the kit's FAQ bank builder (`seo:faq-bank`,
 * kit 0.24.0, 2026-11-10): the pages worth mining and the few facts that
 * keep every derived answer honest. Bound to FaqBankSource in
 * AppServiceProvider, beside the FaqCatalog binding whose items() publishes
 * the bank after the hand-written config/geo-answers.php list.
 *
 * The pages, in the order the builder first visits them:
 *
 *   - the English marketing pages App\Support\MarketingPageList lists for
 *     /llms.txt — the home page and the homeowner pages as `page`, the
 *     product-area pages (/en/welcome/finances, …) as `feature` — minus
 *     the FAQ page: its answers ARE the hand-written list, which
 *     FaqBankCatalog::merge() keeps first and unchanged, so mining it
 *     would only repeat them;
 *   - every per-card feature page (/en/welcome/finances/expenses, …), read
 *     from the same App\Support\MarketingPages registry the sitemap reads,
 *     as `feature` — the richest "what does X do" copy on the site, which
 *     MarketingPageList leaves out of the short /llms.txt index on purpose;
 *   - the published blog posts, newest first, as `blog`.
 *
 * Nothing behind a login and no legal page (MarketingPageList already
 * leaves those out). Every URL is absolute on the marketing host through
 * marketing_url(): this app is split across hosts (APP_URL is the hub) and
 * the builder renders each URL in-process through the HTTP kernel — the
 * host of that request is hive.contractors, which RedirectLegacyDomains
 * leaves alone, and the {locale}-prefixed marketing routes match by path,
 * so the render is the marketing page itself, never a hub redirect (proved
 * by tests/Feature/Seo/FaqBankTest.php). textFor() therefore stays null:
 * the kit's PageTextExtractor reads the rendered page's own words with the
 * nav and the footer dropped as chrome.
 *
 * Nationwide software, not a service-area business, so towns() is empty:
 * no two answers here differ only by a place name. bannedTerms() names the
 * competing products an answer must never mention; this site keeps no
 * competitor list in config (HiveSeoProfile::competitorPatterns() is
 * empty on purpose), so the list lives here.
 */
final class HiveFaqBankSource implements FaqBankSource
{
    /** Competing products an answer must never name — a pair naming one is dropped by the kit's FaqAnswerGuard. */
    public const BANNED_TERMS = [
        'Procore', 'Buildertrend', 'Jobber', 'CoConstruct', 'Contractor Foreman',
        'Houzz Pro', 'JobNimbus', 'ServiceTitan', 'Knowify', 'Foundation Software',
    ];

    public function pages(): array
    {
        $pages = [];

        foreach (MarketingPageList::english() as $page) {
            if ($page['type'] === 'faq') {
                continue;
            }

            $pages[] = ['url' => $page['url'], 'kind' => $page['type'] === 'area' ? 'feature' : 'page'];
        }

        foreach (MarketingPages::all() as $page) {
            if ($page['route_name'] !== 'welcome.feature') {
                continue;
            }

            $pages[] = ['url' => marketing_url(MarketingPages::path($page['route_name'], $page['params'])), 'kind' => 'feature'];
        }

        foreach (BlogPost::published()->orderByDesc('published_at')->get(['slug']) as $post) {
            $pages[] = ['url' => marketing_url('en/blog/'.$post->slug), 'kind' => 'blog'];
        }

        return $pages;
    }

    public function textFor(string $url): ?string
    {
        return null;
    }

    public function businessName(): string
    {
        return (string) config('brand.name', 'Hive Contractors');
    }

    public function language(): string
    {
        return 'US English';
    }

    public function towns(): array
    {
        return [];
    }

    public function bannedTerms(): array
    {
        return self::BANNED_TERMS;
    }
}
