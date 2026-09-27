<?php

use App\Models\Testimonial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/**
 * The "What contractors say" section on /{locale}/welcome — published
 * Testimonial rows only, rendered nowhere (no empty heading) when there are
 * none. See App\Observers\TestimonialObserver for the cache-bust half.
 */
it('shows a published testimonial with its rating, quote and role', function () {
    Testimonial::create([
        'name' => 'Jane Doe',
        'role' => 'Owner, Doe Roofing',
        'body' => 'Hive keeps my books straight without an accountant.',
        'rating' => 5,
        'is_published' => true,
    ]);

    $html = $this->get('/en/welcome')->assertOk()->getContent();

    expect($html)->toContain('What contractors say')
        ->and($html)->toContain('Jane Doe')
        ->and($html)->toContain('Owner, Doe Roofing')
        ->and($html)->toContain('Hive keeps my books straight without an accountant.');
});

it('hides an unpublished testimonial', function () {
    Testimonial::create([
        'name' => 'Hidden Reviewer',
        'body' => 'This should never appear.',
        'is_published' => false,
    ]);

    $html = $this->get('/en/welcome')->assertOk()->getContent();

    expect($html)->not->toContain('Hidden Reviewer')
        ->and($html)->not->toContain('This should never appear.');
});

it('renders no section at all when there are no testimonials', function () {
    $html = $this->get('/en/welcome')->assertOk()->getContent();

    expect($html)->not->toContain('What contractors say');
});

it('shows a review the same words on every locale, never translated', function () {
    Testimonial::create([
        'name' => 'Global Reviewer',
        'body' => 'Same words everywhere.',
        'is_published' => true,
    ]);

    foreach (['en', 'pl', 'es'] as $locale) {
        $html = $this->get("/{$locale}/welcome")->assertOk()->getContent();
        expect($html)->toContain('Same words everywhere.');
    }
});

it('forgets every locale\'s cached welcome page when a testimonial is saved, so a new review shows at once', function () {
    Cache::flush();

    // Warm the cache for each locale.
    foreach (['en', 'pl', 'es'] as $locale) {
        $this->get("/{$locale}/welcome")->assertOk();
    }

    Testimonial::create([
        'name' => 'Fresh Reviewer',
        'body' => 'Just added.',
        'is_published' => true,
    ]);

    foreach (['en', 'pl', 'es'] as $locale) {
        $html = $this->get("/{$locale}/welcome")->assertOk()->getContent();
        expect($html)->toContain('Fresh Reviewer');
    }
});

it('forgets the cached welcome page when a testimonial is deleted, so a removed review disappears at once', function () {
    Cache::flush();

    $testimonial = Testimonial::create([
        'name' => 'Going Away',
        'body' => 'Temporary.',
        'is_published' => true,
    ]);

    // Warm the cache with the review still present.
    foreach (['en', 'pl', 'es'] as $locale) {
        $this->get("/{$locale}/welcome")->assertOk()->assertSee('Going Away');
    }

    $testimonial->delete();

    foreach (['en', 'pl', 'es'] as $locale) {
        $html = $this->get("/{$locale}/welcome")->assertOk()->getContent();
        expect($html)->not->toContain('Going Away');
    }
});
