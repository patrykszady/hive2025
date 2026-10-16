<?php

use App\Models\Client;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

// =========================================================================
// Mary Simon, added to client 338 on 2026-10-16, was missing from the new
// text thread picker: the cached client list never heard of her. Adding or
// removing a person on a client now forgets that list for every company
// the client belongs to, from either side of the relation.
// =========================================================================

it('forgets the cached client list when a person is added to or removed from a client', function () {
    config(['scout.driver' => null]);
    $company = Vendor::factory()->create();
    $client = Client::factory()->create();
    $client->vendors()->attach($company->id);
    $joseph = User::factory()->create(['first_name' => 'Joseph', 'last_name' => 'Simon']);
    $mary = User::factory()->create(['first_name' => 'Mary', 'last_name' => 'Simon']);
    $client->users()->attach($joseph->id);

    $key = "clients:dropdown:v{$company->id}";

    Cache::put($key, 'stale list');
    $client->users()->attach($mary->id);
    expect(Cache::has($key))->toBeFalse();

    Cache::put($key, 'stale list');
    $client->users()->detach($mary->id);
    expect(Cache::has($key))->toBeFalse();

    // Lead intake attaches from the person's side.
    Cache::put($key, 'stale list');
    $mary->clients()->attach($client->id);
    expect(Cache::has($key))->toBeFalse()
        ->and($client->users()->pluck('users.id')->sort()->values()->all())->toBe(collect([$joseph->id, $mary->id])->sort()->values()->all());
});
