<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('serves each locale under its prefix and sets the app locale', function () {
    $this->get('/en/welcome')->assertOk()->assertSee('Sign in');
    expect(app()->getLocale())->toBe('en');

    $this->get('/pl/welcome')->assertOk();
    expect(app()->getLocale())->toBe('pl');

    $this->get('/es/welcome')->assertOk();
    expect(app()->getLocale())->toBe('es');
});

it('301s bare marketing paths to the default locale', function () {
    $this->get('/welcome')->assertRedirect('/en/welcome');
    $this->get('/welcome/finances')->assertRedirect('/en/welcome/finances');
    $this->get('/welcome/finances/expenses')->assertRedirect('/en/welcome/finances/expenses');
});

it('keeps in-page marketing links inside the active locale', function () {
    $html = $this->get('/pl/welcome')->assertOk()->getContent();

    expect($html)->toContain('/pl/welcome/finances')
        ->and($html)->not->toContain('href="'.url('/en/welcome/finances').'"');
});

it('emits hreflang alternates for every locale plus x-default on marketing pages, rooted on the marketing host', function () {
    // APP_URL deliberately set to something else: hreflang/canonical must
    // follow config('app.marketing_url'), never APP_URL or the request's
    // own host — the app and the marketing site are split across hosts.
    config(['app.url' => 'https://hub.hive.contractors', 'app.marketing_url' => 'https://hive.contractors']);

    $html = $this->get('/en/welcome/finances')->assertOk()->getContent();

    expect($html)->toContain('hreflang="en"')
        ->and($html)->toContain('hreflang="pl"')
        ->and($html)->toContain('hreflang="es"')
        ->and($html)->toContain('hreflang="x-default"')
        ->and($html)->toContain('https://hive.contractors/en/welcome/finances')
        ->and($html)->toContain('https://hive.contractors/pl/welcome/finances')
        ->and($html)->toContain('https://hive.contractors/es/welcome/finances')
        ->and($html)->not->toContain('hub.hive.contractors');
});

it('emits a self-canonical link on marketing pages, rooted on the marketing host', function () {
    config(['app.marketing_url' => 'https://hive.contractors']);

    $html = $this->get('/pl/welcome/finances')->assertOk()->getContent();

    expect($html)->toContain('<link rel="canonical" href="https://hive.contractors/pl/welcome/finances">');
});

it('does not emit a canonical tag on non-marketing pages', function () {
    $html = $this->get('/login')->assertOk()->getContent();

    expect($html)->not->toContain('rel="canonical"');
});

it('does not emit hreflang alternate tags on non-marketing pages', function () {
    $html = $this->get('/login')->assertOk()->getContent();

    // The alternate-language <link> tags must not appear (a bare "hreflang"
    // substring lives in the Flux JS bundle, so match the actual tag).
    expect($html)->not->toContain('<link rel="alternate" hreflang=');
});

it('renders config-driven feature pages in each locale', function () {
    $this->get('/en/welcome/finances/expenses')->assertOk();
    $this->get('/pl/welcome/finances/expenses')->assertOk();
    $this->get('/es/welcome/finances/expenses')->assertOk();
});

it('404s an unknown locale prefix and an unknown feature card', function () {
    $this->get('/de/welcome')->assertNotFound();
    $this->get('/en/welcome/finances/not-a-real-card')->assertNotFound();
});

it('generates locale-correct URLs from route() via the active prefix', function () {
    $this->get('/pl/welcome');
    expect(route('welcome.finances', [], false))->toBe('/pl/welcome/finances');

    $this->get('/en/welcome');
    expect(route('welcome.finances', [], false))->toBe('/en/welcome/finances');
});

it('translates nav and feature content into Polish and Spanish', function () {
    // Polish nav + Polish feature hero (from the translated lang files).
    $pl = $this->get('/pl/welcome')->assertOk()->getContent();
    expect($pl)->toContain('Zaloguj się'); // "Sign in"

    $es = $this->get('/es/welcome/finances/expenses')->assertOk()->getContent();
    expect($es)->toContain('Gastos'); // "Expenses"
});
