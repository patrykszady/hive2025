<?php

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.admin_api.token' => 'test-admin-api-token']);
});

/**
 * A migrated data-fixup migration (2026_03_29_000002_seed_vendor_
 * conversions_and_data_fixes) inserts a handful of vendor/user rows with
 * created_at = now() as a side effect of running every migration on a
 * fresh test database — so these tests measure DELTAS off a baseline
 * queried the same way the controller does, rather than hardcoding
 * absolute counts that migration would otherwise silently invalidate.
 *
 * @return array{vendor_total:int,vendor_recent:int,vendor_prior:int,user_total:int,user_recent:int,user_prior:int}
 */
function adminDashboardBaseline(): array
{
    $now = now();
    $recentSince = (clone $now)->subDays(7);
    $priorSince = (clone $now)->subDays(14);

    return [
        'vendor_total' => Vendor::query()->count(),
        'vendor_recent' => Vendor::query()->where('created_at', '>=', $recentSince)->count(),
        'vendor_prior' => Vendor::query()->where('created_at', '>=', $priorSince)->where('created_at', '<', $recentSince)->count(),
        'user_total' => User::query()->count(),
        'user_recent' => User::query()->where('created_at', '>=', $recentSince)->count(),
        'user_prior' => User::query()->where('created_at', '>=', $priorSince)->where('created_at', '<', $recentSince)->count(),
    ];
}

function expectedDeltaPct(int $current, int $previous): ?float
{
    if ($previous <= 0) {
        return null;
    }

    return round((($current - $previous) / $previous) * 100, 1);
}

it('sends the common tile shape for new companies and new users', function () {
    $baseline = adminDashboardBaseline();
    $now = now();

    // 2 vendors in the last 7 days, 1 more in the 7 days before that, 1 older still.
    Vendor::factory()->create(['created_at' => (clone $now)->subDays(1)]);
    Vendor::factory()->create(['created_at' => (clone $now)->subDays(3)]);
    Vendor::factory()->create(['created_at' => (clone $now)->subDays(10)]);
    Vendor::factory()->create(['created_at' => (clone $now)->subDays(40)]);

    // 1 user in the last 7 days, none added to the prior window.
    User::factory()->create(['created_at' => (clone $now)->subDays(2)]);

    $tiles = $this->getJson('/api/admin/v1/dashboard-stats', adminApiHeaders())
        ->assertOk()
        ->json('data.tiles');

    $byKey = collect($tiles)->keyBy('key');

    expect($byKey->keys()->all())->toEqualCanonicalizing(['signups', 'users']);

    $expectedVendorCurrent = $baseline['vendor_recent'] + 2;
    $expectedVendorPrior = $baseline['vendor_prior'] + 1;
    $expectedVendorTotal = $baseline['vendor_total'] + 4;

    $signups = $byKey['signups'];
    expect($signups['label'])->toBe('New companies (7 days)');
    expect($signups['value'])->toBe($expectedVendorCurrent);
    expect($signups['note'])->toBe(number_format($expectedVendorTotal).' total');
    expect($signups['delta_pct'])->toEqual(expectedDeltaPct($expectedVendorCurrent, $expectedVendorPrior));
    expect($signups['href'])->toBeNull();

    $expectedUserCurrent = $baseline['user_recent'] + 1;
    $expectedUserPrior = $baseline['user_prior'];
    $expectedUserTotal = $baseline['user_total'] + 1;

    $users = $byKey['users'];
    expect($users['label'])->toBe('New users (7 days)');
    expect($users['value'])->toBe($expectedUserCurrent);
    expect($users['note'])->toBe(number_format($expectedUserTotal).' total');
    expect($users['delta_pct'])->toEqual(expectedDeltaPct($expectedUserCurrent, $expectedUserPrior));
    expect($users['href'])->toBeNull();

    // Every tile carries exactly the shared shape's keys.
    foreach ($tiles as $tile) {
        expect(array_keys($tile))->toEqualCanonicalizing(['key', 'label', 'value', 'note', 'delta_pct', 'href']);
    }
});

it('counts every vendor regardless of who is signed in (no session on this route)', function () {
    $baseline = adminDashboardBaseline();

    Vendor::factory()->count(3)->create();

    $tiles = $this->getJson('/api/admin/v1/dashboard-stats', adminApiHeaders())
        ->assertOk()
        ->json('data.tiles');

    $expectedTotal = $baseline['vendor_total'] + 3;

    expect(collect($tiles)->firstWhere('key', 'signups')['note'])->toBe(number_format($expectedTotal).' total');
});
