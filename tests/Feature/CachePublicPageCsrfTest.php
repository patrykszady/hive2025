<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/**
 * The cached marketing pages must never hand one visitor's CSRF token to
 * another. Livewire reads data-csrf once at boot and keeps it across
 * wire:navigate, so a shared token 419s the login page one click later.
 */
it('gives each visitor their own csrf token on a cached public page', function () {
    Cache::flush();

    $first = $this->get('/en/welcome')->assertOk();
    $firstToken = csrfTokenFrom($first->getContent());

    expect($firstToken)->not->toBeNull();

    // A second, independent visitor: fresh session, and the page now comes
    // from the cache (that is the regression — it used to serve visitor 1's
    // token to everybody).
    $this->flushSession();

    $second = $this->get('/en/welcome')->assertOk();
    $second->assertHeader('X-Page-Cache', 'hit');
    $secondToken = csrfTokenFrom($second->getContent());

    expect($secondToken)->not->toBeNull()
        ->and($secondToken)->toBe(session()->token())
        ->and($secondToken)->not->toBe($firstToken);
});

it('rewrites the token in both places the page publishes it', function () {
    Cache::flush();

    $this->get('/en/welcome')->assertOk();
    $this->flushSession();

    $html = $this->get('/en/welcome')->assertOk()->getContent();
    $token = session()->token();

    // Every token the page carries belongs to THIS session — a missed spot
    // would leave Livewire booting with the stale one.
    foreach (metaTokensFrom($html) as $found) {
        expect($found)->toBe($token);
    }
    foreach (dataCsrfTokensFrom($html) as $found) {
        expect($found)->toBe($token);
    }
});

function csrfTokenFrom(string $html): ?string
{
    return dataCsrfTokensFrom($html)[0] ?? metaTokensFrom($html)[0] ?? null;
}

/** @return array<int, string> */
function dataCsrfTokensFrom(string $html): array
{
    preg_match_all('/data-csrf="([^"]*)"/', $html, $m);

    return $m[1] ?? [];
}

/** @return array<int, string> */
function metaTokensFrom(string $html): array
{
    preg_match_all('/<meta name="csrf-token" content="([^"]*)"/', $html, $m);

    return $m[1] ?? [];
}
