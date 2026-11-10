<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Support\MarketingPageList;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * /llms.txt — the marketing site for AI search, in the shape gs.construction
 * and ss.systems publish: the name, one line on what Hive is, every public
 * page with the same description its own <meta> carries, the blog, the
 * other languages and, since 2026-11-10, the machine-readable feeds next
 * to it (/llms-full.txt, /ai-feed.json, /geo/answers.json). Rooted on the
 * marketing host like the sitemap, cached for an hour. Hive was the one
 * site without one (2026-10-29). The page list is App\Support\
 * MarketingPageList, shared with /llms-full.txt and /ai-feed.json so the
 * three never list different pages.
 */
class LlmsTxtController extends Controller
{
    public function __invoke(): Response
    {
        $body = Cache::remember('llms-txt', now()->addHour(), fn () => $this->render());

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    protected function render(): string
    {
        $lines = ['# Hive Contractors', ''];
        $lines[] = '> '.MarketingPageList::homeDescription();
        $lines[] = '';
        $lines[] = '## Pages';

        foreach (MarketingPageList::english() as $page) {
            $lines[] = "- [{$page['title']}]({$page['url']})".($page['description'] ? ": {$page['description']}" : '');
        }

        $posts = BlogPost::published()->latest('published_at')->limit(20)->get();

        if ($posts->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '## Blog';
            $lines[] = '- [Blog]('.marketing_url('en/blog').'): Notes on running a small contracting business with Hive.';

            foreach ($posts as $post) {
                $lines[] = "- [{$post->title}](".marketing_url('en/blog/'.$post->slug).'): '.$post->teaser();
            }
        }

        $lines[] = '';
        $lines[] = '## Languages';

        foreach (MarketingPageList::otherLanguages() as $language) {
            $lines[] = "- [{$language['native']}]({$language['url']}): the same site in {$language['name']}.";
        }

        $lines[] = '';
        $lines[] = '## Machine-readable';
        $lines[] = '- [llms-full.txt]('.marketing_url('llms-full.txt').'): the extended profile — what each area does, every page, the blog and the FAQ in one file.';
        $lines[] = '- [ai-feed.json]('.marketing_url('ai-feed.json').'): business facts, product areas, pages and reviews as JSON.';
        $lines[] = '- [geo/answers.json]('.marketing_url('geo/answers.json').'): the FAQ as FAQPage JSON-LD.';
        $lines[] = '- [sitemap.xml]('.marketing_url('sitemap.xml').'): every public page in every language.';

        return implode("\n", $lines)."\n";
    }
}
