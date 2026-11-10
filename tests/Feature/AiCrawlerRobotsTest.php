<?php

/**
 * Owner's call, 2026-09-29: search engines and AI assistants that answer
 * people (and cite the site) may crawl the public pages; crawlers that
 * collect pages to train models may not. A crawler reads every group naming
 * it together, and Google lets "Allow: /" beat "Disallow: /" at the same
 * length, so a leftover Allow group for a training crawler would undo it.
 *
 * Changed 2026-11-10: GPTBot, ClaudeBot (and the old anthropic-ai token) and
 * Google-Extended are let in — the site wants to be known by the AI models
 * people ask, and Google-Extended also decides whether Gemini grounds its
 * answers on the site. Only bulk training scrapers stay out.
 */

/** @return array<string, list<string>> lowercase user-agent => its rules, every group naming it combined */
function aiCrawlerRobotsGroups(string $robots): array
{
    $groups = [];
    $agents = [];
    $inRules = false;

    foreach (preg_split('/\R/', $robots) as $line) {
        $line = trim(preg_replace('/#.*/', '', $line));
        if ($line === '' || ! str_contains($line, ':')) {
            continue;
        }
        [$field, $value] = array_map('trim', explode(':', $line, 2));
        $field = strtolower($field);

        if ($field === 'user-agent') {
            if ($inRules) {
                $agents = [];
                $inRules = false;
            }
            $agents[] = strtolower($value);
            $groups[strtolower($value)] ??= [];
        } elseif (in_array($field, ['allow', 'disallow'], true) && $agents !== []) {
            $inRules = true;
            foreach ($agents as $agent) {
                $groups[$agent][] = "{$field}: {$value}";
            }
        }
    }

    return $groups;
}

it('keeps AI training crawlers out and lets search and answering crawlers in', function () {
    $groups = aiCrawlerRobotsGroups((string) file_get_contents(public_path('robots.txt')));

    foreach (['Applebot-Extended', 'CCBot', 'Bytespider', 'meta-externalagent', 'cohere-ai'] as $agent) {
        $rules = $groups[strtolower($agent)] ?? $groups['*'] ?? [];
        expect($rules)->toContain('disallow: /')->not->toContain('allow: /');
    }

    foreach (['OAI-SearchBot', 'ChatGPT-User', 'Claude-SearchBot', 'Claude-User', 'PerplexityBot', 'Perplexity-User', 'Googlebot', 'Bingbot', 'Amazonbot', 'GPTBot', 'ClaudeBot', 'anthropic-ai', 'Google-Extended'] as $agent) {
        $rules = $groups[strtolower($agent)] ?? $groups['*'] ?? [];
        expect($rules)->not->toContain('disallow: /')->toContain('allow: /en/welcome');
    }
});
