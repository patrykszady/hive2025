<?php

use App\Support\MarketingSitemap;
use App\Support\Seo\Inspection\MarketingSitemapSource;

/**
 * SitemapSource over App\Support\MarketingSitemap — the same route/config
 * inventory GET /sitemap.xml itself renders from, not a static file. See
 * the class's own docblock for why that is simpler and more current than
 * fetching the rendered XML would be.
 */
it('flattens every locale url from MarketingSitemap::entries()', function () {
    $source = new MarketingSitemapSource(new MarketingSitemap);

    $urls = $source->urls();

    expect($urls)->not->toBeEmpty();
    expect($urls)->toContain(marketing_url('en/welcome'));
    // No duplicates: array_unique() over every locale's href.
    expect($urls)->toBe(array_values(array_unique($urls)));
});

it('base url is rooted on the marketing host regardless of APP_URL', function () {
    config(['app.marketing_url' => 'https://hive.contractors', 'app.url' => 'http://127.0.0.1:8011']);

    $source = new MarketingSitemapSource(new MarketingSitemap);

    expect($source->baseUrl())->toBe('https://hive.contractors');
});

it('an override path reads a literal xml file instead', function () {
    $path = tempnam(sys_get_temp_dir(), 'sitemap-').'.xml';
    file_put_contents($path, '<?xml version="1.0"?><urlset><url><loc>https://hive.contractors/from-file</loc></url></urlset>');

    $source = new MarketingSitemapSource(new MarketingSitemap);
    $urls = $source->urls($path);

    expect($urls)->toBe(['https://hive.contractors/from-file']);

    @unlink($path);
});
