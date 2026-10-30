<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Support\MarketingPages;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * /llms.txt — the marketing site for AI search, in the shape gs.construction
 * and ss.systems publish: the name, one line on what Hive is, every public
 * page with the same description its own <meta> carries, the blog, and the
 * other languages. Rooted on the marketing host like the sitemap, cached
 * for an hour. Hive was the one site without one (2026-10-29).
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
        app()->setLocale('en');

        $pages = collect(MarketingPages::all())
            ->filter(fn (array $page) => $page['route_name'] !== 'welcome.feature' && in_array($page['type'], ['other', 'area', 'homeowners', 'faq'], true))
            ->map(fn (array $page) => [
                'route_name' => $page['route_name'],
                'title' => $this->title($page),
                'url' => marketing_url(MarketingPages::path($page['route_name'], $page['params'])),
                'description' => MarketingPages::description($page['route_name'], $page['params'], $page['view']),
            ])
            ->filter(fn (array $page) => $page['title'] !== null)
            ->sortBy(fn (array $page) => $page['route_name'] === 'welcome' ? '' : $page['url'])
            ->values();

        $home = $pages->firstWhere('route_name', 'welcome');

        $lines = ['# Hive Contractors', ''];
        $lines[] = '> '.($home['description'] ?? 'Hive Contractors is the CRM built for small contractors: finances, estimates, leads and clients, vendors and compliance, planning, team time, communication and photos in one app.');
        $lines[] = '';
        $lines[] = '## Pages';

        foreach ($pages as $page) {
            $lines[] = "- [{$page['title']}]({$page['url']})".($page['description'] ? ": {$page['description']}" : '');
        }

        $posts = BlogPost::published()->latest('published_at')->limit(20)->get();

        if ($posts->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '## Blog';
            $lines[] = '- [Blog](' . marketing_url('en/blog') . '): Notes on running a small contracting business with Hive.';

            foreach ($posts as $post) {
                $lines[] = "- [{$post->title}](".marketing_url('en/blog/'.$post->slug).'): '.$post->teaser();
            }
        }

        $lines[] = '';
        $lines[] = '## Languages';
        $lines[] = '- [Polski](' . marketing_url('pl/welcome') . '): the same site in Polish.';
        $lines[] = '- [Español](' . marketing_url('es/welcome') . '): the same site in Spanish.';

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array{route_name: string, params: array<string, string>, view: ?string}  $page
     */
    protected function title(array $page): ?string
    {
        $title = MarketingPages::baseTitle($page['route_name'], $page['params'], $page['view']);

        if ($title === null) {
            return null;
        }

        $title = html_entity_decode(__($title));

        return trim(Str::before($title, ' — Hive Contractors')) ?: null;
    }
}
