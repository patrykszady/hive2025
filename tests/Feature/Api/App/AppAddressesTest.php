<?php

use App\Models\User;
use App\Models\Vendor;
use App\Services\GeoapifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * GET /api/app/v1/addresses/autocomplete and /resolve — the address
 * autocomplete every app form with an address field shares, mirroring
 * App\Traits\HandlesAddresses exactly (same provider via GeoapifyService,
 * same two-step shape: list suggestions, then resolve one by place_id).
 * GeoapifyService is mocked throughout (it talks to Geoapify over a raw
 * Guzzle client, not Laravel's Http facade) — never a real network call.
 */
function appAddressesTeamUser(): User
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return $user;
}

it('needs a token to autocomplete an address', function () {
    $this->getJson('/api/app/v1/addresses/autocomplete?query=130 E Main')->assertUnauthorized();
});

it('requires at least 3 characters, like the web', function () {
    Sanctum::actingAs(appAddressesTeamUser(), ['app']);

    $this->mock(GeoapifyService::class)->shouldNotReceive('getAutocompleteSuggestions');

    $this->getJson('/api/app/v1/addresses/autocomplete?query=13')->assertUnprocessable();
});

it('lists suggestions from the same provider the web uses', function () {
    Sanctum::actingAs(appAddressesTeamUser(), ['app']);

    $this->mock(GeoapifyService::class)
        ->shouldReceive('getAutocompleteSuggestions')
        ->once()
        ->with('130 E Main')
        ->andReturn([
            ['place_id' => 'place-1', 'description' => '130 E Main Ave, Barrington, IL 60010'],
        ]);

    $response = $this->getJson('/api/app/v1/addresses/autocomplete?query=130 E Main')->assertOk();

    expect($response->json('data'))->toBe([
        ['place_id' => 'place-1', 'description' => '130 E Main Ave, Barrington, IL 60010'],
    ]);
});

it('resolves a chosen suggestion to line1/city/state/zip', function () {
    Sanctum::actingAs(appAddressesTeamUser(), ['app']);

    $this->mock(GeoapifyService::class)
        ->shouldReceive('getPlaceDetails')
        ->once()
        ->with('place-1')
        ->andReturn([
            'place_id' => 'place-1',
            'street_number' => '130',
            'route' => 'E Main Ave',
            'locality' => 'Barrington',
            'administrative_area_level_1' => 'IL',
            'postal_code' => '60010',
            'formatted_address' => '130 E Main Ave, Barrington, IL 60010',
        ]);

    $response = $this->getJson('/api/app/v1/addresses/resolve?place_id=place-1')->assertOk();

    expect($response->json('data'))->toBe([
        'place_id' => 'place-1',
        'line1' => '130 E Main Ave',
        'city' => 'Barrington',
        'state' => 'IL',
        'zip' => '60010',
    ]);
});

it('resolves to null when the provider has nothing for that place_id', function () {
    Sanctum::actingAs(appAddressesTeamUser(), ['app']);

    $this->mock(GeoapifyService::class)->shouldReceive('getPlaceDetails')->once()->andReturn([]);

    $response = $this->getJson('/api/app/v1/addresses/resolve?place_id=unknown')->assertOk();

    expect($response->json('data'))->toBeNull();
});
