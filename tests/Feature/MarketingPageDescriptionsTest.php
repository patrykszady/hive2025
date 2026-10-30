<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(fn () => Cache::flush());

/**
 * Every top-level marketing page from the live audit (the welcome home page
 * plus every welcome/* subpage) — each must render its own meta description,
 * and no two of them may share one. Dynamic pages (welcome/{area}/{card},
 * welcome/homeowners/*) are out of this audit's scope and not covered here.
 *
 * @return array<string, string>
 */
function marketingPagePaths(): array
{
    return [
        'welcome' => 'welcome',
        'finances' => 'welcome/finances',
        'estimates' => 'welcome/estimates',
        'clients' => 'welcome/clients',
        'vendors' => 'welcome/vendors',
        'planning' => 'welcome/planning',
        'team' => 'welcome/team',
        'communication' => 'welcome/communication',
        'photos' => 'welcome/photos',
        'automation' => 'welcome/automation',
        'homeowners' => 'welcome/homeowners',
        'faq' => 'welcome/faq',
    ];
}

it('gives every marketing page a unique, non-empty meta description', function (string $path) {
    foreach (['en', 'pl', 'es'] as $locale) {
        $html = $this->get("/{$locale}/{$path}")->assertOk()->getContent();

        preg_match('#<meta name="description" content="([^"]*)">#', $html, $m);
        $description = html_entity_decode($m[1] ?? '');

        expect($description)->not->toBe('', "missing meta description for /{$locale}/{$path}")
            ->and(mb_strlen($description))->toBeLessThanOrEqual(160)
            ->and(preg_match('/[.!?…]$/u', $description))->toBe(1, "description for /{$locale}/{$path} stops mid-phrase: {$description}");
    }
})->with('marketingPagePaths');

it('never gives two marketing pages the same meta description, in any single locale', function () {
    foreach (['en', 'pl', 'es'] as $locale) {
        $descriptions = [];

        foreach (marketingPagePaths() as $name => $path) {
            $html = $this->get("/{$locale}/{$path}")->assertOk()->getContent();
            preg_match('#<meta name="description" content="([^"]*)">#', $html, $m);
            $descriptions[$name] = $m[1] ?? null;
        }

        expect(array_unique($descriptions))->toHaveCount(
            count($descriptions),
            "duplicate meta description among {$locale} marketing pages: ".json_encode($descriptions)
        );
    }
});

/**
 * dataset() registered after the functions/tests that reference it by name
 * still resolves — Pest collects datasets before running any test — but
 * defining it next to its only use keeps the two readable together.
 */
dataset('marketingPagePaths', fn () => collect(marketingPagePaths())->mapWithKeys(
    fn (string $path, string $name) => [$name => [$path]]
)->all());
