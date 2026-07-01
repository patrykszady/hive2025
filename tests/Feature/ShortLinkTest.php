<?php

use App\Models\ShortLink;
use App\Services\UrlShortener;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('redirects a short link straight to its destination', function (): void {
    $link = ShortLink::create([
        'code' => 'abc123',
        'destination' => 'https://hive.contractors/v/e124ae08566315b5',
    ]);

    $this->get('/l/abc123')
        ->assertRedirect('https://hive.contractors/v/e124ae08566315b5');

    expect($link->fresh()->hits)->toBe(1)
        ->and($link->fresh()->last_visited_at)->not->toBeNull();
});

it('returns 404 for an unknown short link code', function (): void {
    $this->get('/l/missing')->assertNotFound();
});

it('reuses the same short link for the same destination', function (): void {
    $destination = 'https://hive.contractors/s/aabbccddeeff0011';

    $first = ShortLink::forDestination($destination);
    $second = ShortLink::forDestination($destination);

    expect($first->id)->toBe($second->id)
        ->and($first->code)->toBe($second->code)
        ->and(ShortLink::where('destination', $destination)->count())->toBe(1);
});

it('returns the original url when shortening is disabled', function (): void {
    config(['services.url_shortener.enabled' => false]);

    $url = 'https://hive.contractors/v/e124ae08566315b5';

    expect(app(UrlShortener::class)->shorten($url))->toBe($url);
});

it('builds an internal l-code link when shortening is enabled', function (): void {
    config([
        'services.url_shortener.enabled' => true,
        'app.dev_webhook_url' => null,
        'app.url' => 'https://hive.contractors',
    ]);

    $short = app(UrlShortener::class)->shorten('https://hive.contractors/v/e124ae08566315b5');

    expect($short)->toStartWith('https://hive.contractors/l/');
});
