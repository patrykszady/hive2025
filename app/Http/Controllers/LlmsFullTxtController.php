<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Support\MarketingPageList;
use App\Support\Seo\AiFeed\HiveBusinessIdentity;
use App\Support\Seo\AiFeed\HiveServiceCatalog;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use SsSystems\Platform\Seo\Faq\Bank\FaqBankStore;

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
 * config/geo-answers.php FAQ, the other languages, the machine-readable
 * feeds and, last, the FAQ bank (kit 0.24.0, 2026-11-10): the questions and
 * answers seo:faq-bank derived from the pages above, grouped under the page
 * each restates so an answer engine can cite it, capped at FAQ_BANK_CAP.
 * Plain markdown, nothing entity-escaped, every URL on the marketing host.
 */
class LlmsFullTxtController extends Controller
{
    /** The most bank answers the file carries — the feed's own cap is FaqBankCatalog::DEFAULT_CAP. */
    public const FAQ_BANK_CAP = 2000;

    public function __construct(private readonly FaqBankStore $store) {}

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

        // Every page named below, by URL, for the bank's group headings.
        $titles = [];

        foreach ((new HiveServiceCatalog)->services() as $service) {
            $titles[$service['url']] = $service['name'];
            $lines[] = '';
            $lines[] = "### {$service['name']} — {$service['url']}";

            if (! empty($service['description'])) {
                $lines[] = $service['description'];
            }

            foreach ($service['features'] as $feature) {
                $titles[$feature['url']] = $feature['name'];
                $lines[] = "- {$feature['name']} ({$feature['url']})".(empty($feature['description']) ? '' : ": {$feature['description']}");
            }
        }

        $lines[] = '';
        $lines[] = '## Pages';

        foreach (MarketingPageList::english() as $page) {
            $titles[$page['url']] = $page['title'];
            $lines[] = "- [{$page['title']}]({$page['url']})".($page['description'] ? ": {$page['description']}" : '');
        }

        $posts = BlogPost::published()->orderByDesc('published_at')->get();

        if ($posts->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '## Blog';
            $lines[] = '- [Blog]('.marketing_url('en/blog').'): Notes on running a small contracting business with Hive.';

            foreach ($posts as $post) {
                $titles[marketing_url('en/blog/'.$post->slug)] = $post->title;
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
        $lines[] = '- [geo/answers.json]('.marketing_url('geo/answers.json').'): the questions above, and the questions and answers below, as FAQPage JSON-LD.';
        $lines[] = '- [sitemap.xml]('.marketing_url('sitemap.xml').'): every public page in every language.';
        $lines[] = '- [llms.txt]('.marketing_url('llms.txt').'): the short index.';

        $bank = $this->store->all(self::FAQ_BANK_CAP);

        if ($bank !== []) {
            $lines[] = '';
            $lines[] = '## Questions and answers';
            $lines[] = '';
            $lines[] = sprintf(
                '%d more questions and answers, each derived from one page of this site and grouped under the page it restates. They follow the questions above in %s.',
                count($bank),
                marketing_url('geo/answers.json'),
            );

            $byPage = [];

            foreach ($bank as $row) {
                $byPage[$row['url']][] = $row;
            }

            foreach ($byPage as $url => $rows) {
                $lines[] = '';
                $lines[] = isset($titles[$url]) ? "### {$titles[$url]} — {$url}" : "### {$url}";

                foreach ($rows as $row) {
                    $lines[] = '';
                    $lines[] = "#### {$row['q']}";
                    $lines[] = $row['a'];
                }
            }
        }

        return implode("\n", $lines)."\n";
    }
}
