<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Support\MarketingPageList;
use App\Support\Seo\AiFeed\HiveBusinessIdentity;
use App\Support\Seo\AiFeed\HiveServiceCatalog;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * GET /llms-full.txt — the extended, citation-ready profile for AI answer
 * engines that gs.construction and jpeterson-design publish next to their
 * /llms.txt (2026-11-10, Patryk: roll the AI feeds to every site). Those
 * two write it to disk nightly through a package this app does not carry;
 * here it is a live route like /llms.txt, built from the same sources on
 * request and cached for an hour, so there is nothing on disk to go stale:
 * the business facts /ai-feed.json carries, every product area and its
 * feature pages from config/marketing.php, every marketing page with its
 * own description (App\Support\MarketingPageList), the published blog, the
 * config/geo-answers.php FAQ, the other languages and the machine-readable
 * feeds. Plain markdown, nothing entity-escaped, every URL on the
 * marketing host.
 */
class LlmsFullTxtController extends Controller
{
    public function __invoke(): Response
    {
        $body = Cache::remember('llms-full-txt', now()->addHour(), fn (): string => $this->render());

        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    protected function render(): string
    {
        $business = new HiveBusinessIdentity;
        $name = $business->name();
        $facts = $business->extra();

        $lines = [
            "# llms-full.txt for {$name}",
            '',
            '> '.MarketingPageList::homeDescription(),
            '',
            "This is the extended profile of {$name} for AI answer engines; the short index is ".marketing_url('llms.txt').". Everything here comes from the site's own pages and configuration.",
            '',
            '## Business facts',
            "- Name: {$name}",
        ];

        foreach ([
            'What it is' => $facts['headline'] ?? null,
            'Website' => $business->url(),
            'Email' => $business->email(),
            'Mailing address' => $facts['mailing_address'] ?? null,
            'Made in' => $facts['team_base'] ?? null,
            'Serves' => config('geo-answers.meta.service_area'),
            'Languages' => implode(', ', $business->languages()),
            'For' => $facts['audience'] ?? null,
            'Pricing' => $facts['pricing'] ?? null,
        ] as $label => $value) {
            if ($value !== null && $value !== '') {
                $lines[] = "- {$label}: {$value}";
            }
        }

        $lines[] = '';
        $lines[] = '## What Hive does';

        foreach ((new HiveServiceCatalog)->services() as $service) {
            $lines[] = '';
            $lines[] = "### {$service['name']} — {$service['url']}";

            if (! empty($service['description'])) {
                $lines[] = $service['description'];
            }

            foreach ($service['features'] as $feature) {
                $lines[] = "- {$feature['name']} ({$feature['url']})".(empty($feature['description']) ? '' : ": {$feature['description']}");
            }
        }

        $lines[] = '';
        $lines[] = '## Pages';

        foreach (MarketingPageList::english() as $page) {
            $lines[] = "- [{$page['title']}]({$page['url']})".($page['description'] ? ": {$page['description']}" : '');
        }

        $posts = BlogPost::published()->orderByDesc('published_at')->get();

        if ($posts->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '## Blog';
            $lines[] = '- [Blog]('.marketing_url('en/blog').'): Notes on running a small contracting business with Hive.';

            foreach ($posts as $post) {
                $lines[] = "- [{$post->title}](".marketing_url('en/blog/'.$post->slug).'): '.$post->teaser();
            }
        }

        $answers = (array) config('geo-answers.answers', []);

        if ($answers !== []) {
            $lines[] = '';
            $lines[] = '## Frequently asked questions';

            foreach ($answers as $answer) {
                $lines[] = '';
                $lines[] = '### '.$answer['q'];
                $lines[] = $answer['a'];
            }
        }

        $lines[] = '';
        $lines[] = '## Languages';

        foreach (MarketingPageList::otherLanguages() as $language) {
            $lines[] = "- [{$language['native']}]({$language['url']}): the same site in {$language['name']}.";
        }

        $lines[] = '';
        $lines[] = '## Machine-readable feeds';
        $lines[] = '- [ai-feed.json]('.marketing_url('ai-feed.json').'): business facts, product areas, pages and reviews as JSON.';
        $lines[] = '- [geo/answers.json]('.marketing_url('geo/answers.json').'): the questions above as FAQPage JSON-LD.';
        $lines[] = '- [sitemap.xml]('.marketing_url('sitemap.xml').'): every public page in every language.';
        $lines[] = '- [llms.txt]('.marketing_url('llms.txt').'): the short index.';

        return implode("\n", $lines)."\n";
    }
}
