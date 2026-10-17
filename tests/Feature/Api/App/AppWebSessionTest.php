<?php

use App\Models\User;
use App\Models\Vendor;
use App\Services\Auth\AppWebSessionLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * AppAuthTest's appTeamUser().
 */
function appWebSessionUser(array $overrides = []): User
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);

    return User::factory()->create(array_merge(['primary_vendor_id' => $vendor->id], $overrides));
}

it('needs a token to request a web-session link', function () {
    $this->postJson('/api/app/v1/web-session', ['path' => '/projects'])->assertUnauthorized();
});

it('rejects a non-relative path', function () {
    $user = appWebSessionUser();
    Sanctum::actingAs($user, ['app']);

    foreach (['https://evil.test/phish', '//evil.test/phish', 'relative/no/slash', "/back\\slash"] as $path) {
        $this->postJson('/api/app/v1/web-session', ['path' => $path])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['path']);
    }
});

it('a disallowed user cannot get a web-session link', function () {
    $outsiderVendor = Vendor::factory()->create();
    $outsider = User::factory()->create(['primary_vendor_id' => $outsiderVendor->id]);
    Sanctum::actingAs($outsider, ['app']);

    $this->postJson('/api/app/v1/web-session', ['path' => '/projects'])->assertForbidden();
});

it('logs the token\'s user in exactly once; a second open fails', function () {
    $user = appWebSessionUser();

    // Issued directly through the service rather than a POST to
    // /api/app/v1/web-session: that route runs through the auth:sanctum
    // middleware, which calls Auth::shouldUse('sanctum') — and because
    // Laravel's HTTP test client reuses one application container across
    // calls in the same test (unlike two separate real requests), that
    // would still be in effect for the plain web Auth::login() the consume
    // route does right below. The store endpoint's own request/response is
    // already covered by the validation and 403 tests above; this test is
    // about the consume side.
    $links = app(AppWebSessionLinks::class);
    $nonce = $links->issue($user, '/projects/123');
    $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
        'web-session.consume',
        now()->addSeconds(AppWebSessionLinks::TTL),
        ['nonce' => $nonce]
    );

    $path = \Illuminate\Support\Str::after($url, config('app.url'));

    $first = $this->get($path);
    $first->assertRedirect('/projects/123');
    $this->assertAuthenticatedAs($user);

    // Opening the exact same link again must not log in a second time —
    // the nonce behind it was consumed on the first open.
    auth()->logout();
    $second = $this->get($path);
    expect($second->status())->toBeIn([403, 419]);
    $this->assertGuest();
});

it('fails once the link has expired', function () {
    $user = appWebSessionUser();

    $links = app(AppWebSessionLinks::class);
    $nonce = $links->issue($user, '/projects');

    $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
        'web-session.consume',
        now()->subMinute(), // already expired
        ['nonce' => $nonce]
    );

    $path = \Illuminate\Support\Str::after($url, config('app.url'));

    $this->get($path)->assertStatus(403);
    $this->assertGuest();
});

it('rejects a tampered nonce', function () {
    $user = appWebSessionUser();

    $links = app(AppWebSessionLinks::class);
    $links->issue($user, '/projects');

    $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
        'web-session.consume',
        now()->addSeconds(60),
        ['nonce' => 'not-the-real-nonce']
    );

    $path = \Illuminate\Support\Str::after($url, config('app.url'));

    $this->get($path)->assertStatus(403);
    $this->assertGuest();
});
