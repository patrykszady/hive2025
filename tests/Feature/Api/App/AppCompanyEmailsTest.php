<?php

use App\Models\CompanyEmail;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/** @return array{vendor: Vendor, user: User} */
function appCompanyEmailsTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

it('needs a token to list email accounts', function () {
    $this->getJson('/api/app/v1/company-emails')->assertUnauthorized();
});

it('refuses a Member listing email accounts, like the web', function () {
    ['user' => $member] = appCompanyEmailsTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->getJson('/api/app/v1/company-emails')->assertForbidden();
});

it('lists an unlinked account without calling Nylas', function () {
    ['vendor' => $vendor, 'user' => $admin] = appCompanyEmailsTeamUser(role: 1);
    // grant_id is NOT NULL in the schema — '' is how an unlinked row is
    // stored (CompanyEmailsIndex's own `! $email->grant_id` check treats
    // '' the same as null).
    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => 'crew@gs.construction', 'grant_id' => '']);
    Sanctum::actingAs($admin, ['app']);

    Http::fake(function () {
        throw new RuntimeException('Nylas must never be called for an unlinked account.');
    });

    $response = $this->getJson('/api/app/v1/company-emails')->assertOk();

    expect($response->json('data.0.state'))->toBe('unlinked')
        ->and($response->json('data.0.email'))->toBe('crew@gs.construction');
});

it('auto-probes a linked grant on first load, like the web\'s wire:init, instead of getting stuck "checking"', function () {
    ['vendor' => $vendor, 'user' => $admin] = appCompanyEmailsTeamUser(role: 1);
    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => 'crew@gs.construction', 'grant_id' => (string) Str::uuid()]);
    Sanctum::actingAs($admin, ['app']);

    Http::fake([
        '*/v3/grants/*/messages*' => Http::response(['data' => []], 200),
        '*/v3/grants/*' => Http::response(['data' => ['provider' => 'google', 'email' => 'crew@gs.construction', 'grant_status' => 'valid']], 200),
    ]);

    // A cold cache would otherwise read "checking" forever until a manual
    // Recheck tap — the plain index load must resolve it itself.
    $response = $this->getJson('/api/app/v1/company-emails')->assertOk();

    expect($response->json('data.0.state'))->toBe('connected')
        ->and($response->json('data.0.grant_email'))->toBe('crew@gs.construction');
});

it('recheck probes Nylas for a linked grant and reports it connected', function () {
    ['vendor' => $vendor, 'user' => $admin] = appCompanyEmailsTeamUser(role: 1);
    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => 'crew@gs.construction', 'grant_id' => (string) Str::uuid()]);
    Sanctum::actingAs($admin, ['app']);

    Http::fake([
        '*/v3/grants/*/messages*' => Http::response(['data' => []], 200),
        '*/v3/grants/*' => Http::response(['data' => ['provider' => 'google', 'email' => 'crew@gs.construction', 'grant_status' => 'valid']], 200),
    ]);

    $response = $this->postJson('/api/app/v1/company-emails/recheck')->assertOk();

    expect($response->json('data.0.state'))->toBe('connected')
        ->and($response->json('data.0.provider'))->toBe('google');
});
