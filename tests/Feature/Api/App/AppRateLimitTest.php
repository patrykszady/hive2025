<?php

/**
 * The app opens most screens with several API calls at once, so a signed-in
 * app user gets a larger per-minute budget than the generic API; everyone
 * else, and the app's own sign-in, keep the old limits.
 */

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function appRateLimitFor(string $path, ?User $user): \Illuminate\Cache\RateLimiting\Limit
{
    $request = Request::create($path);
    $request->setUserResolver(fn () => $user);

    return RateLimiter::limiter('api')($request);
}

it('gives a signed-in app user 300 requests a minute under their own key', function () {
    $user = User::factory()->create();

    $limit = appRateLimitFor('/api/app/v1/projects', $user);

    expect($limit->maxAttempts)->toBe(300)
        ->and($limit->key)->toBe('app:'.$user->id);
});

it('keeps 60 a minute for the rest of the API and for signed-out app calls', function () {
    $user = User::factory()->create();

    expect(appRateLimitFor('/api/v1/leads', $user)->maxAttempts)->toBe(60)
        ->and(appRateLimitFor('/api/app/v1/login', null)->maxAttempts)->toBe(60);
});

it('does not answer 429 after more than 60 app calls in a minute', function () {
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1]);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => 1]);
    Sanctum::actingAs($user, ['app']);

    foreach (range(1, 61) as $attempt) {
        $this->getJson('/api/app/v1/me')->assertOk();
    }
});
