<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * /clients?client_name_search=Dick finds Richard Egger (Patryk, 2026-10-27):
 * the client and project search indexes carry each client user's nickname,
 * alone and with the last name, beside the legal names. The engine is off
 * in tests (scout.driver null), so this reads what would be indexed.
 */

/** @return array{client: Client, project: Project} */
function dickEgger(): array
{
    $gs = Vendor::query()->create(['business_name' => 'GS Construction & Remodeling, Inc', 'business_type' => 'GC']);
    $patryk = User::query()->create(['first_name' => 'Patryk', 'last_name' => 'Szady', 'email' => 'p.'.Str::random(5).'@gs.construction', 'cell_phone' => '2245559222', 'password' => bcrypt('x'), 'primary_vendor_id' => $gs->id, 'registration' => ['registered' => true]]);
    test()->actingAs($patryk);

    $client = Client::factory()->create(['business_name' => null, 'address' => '12 Lake St', 'city' => 'Barrington', 'state' => 'IL', 'zip_code' => '60010']);
    $client->vendors()->attach($gs->id, ['source' => 'test']);
    $richard = User::query()->create(['first_name' => 'Richard', 'nickname' => 'Dick', 'last_name' => 'Egger', 'email' => 'r.'.Str::random(5).'@example.test', 'cell_phone' => '8475550101', 'password' => bcrypt('x'), 'registration' => ['registered' => true]]);
    $plain = User::query()->create(['first_name' => 'Mary', 'nickname' => null, 'last_name' => 'Egger', 'email' => 'm.'.Str::random(5).'@example.test', 'cell_phone' => '8475550102', 'password' => bcrypt('x'), 'registration' => ['registered' => true]]);
    $client->users()->attach([$richard->id, $plain->id]);

    $project = Project::query()->create(['project_name' => 'Kitchen', 'client_id' => $client->id, 'belongs_to_vendor_id' => $gs->id, 'address' => '12 Lake St', 'city' => 'Barrington', 'state' => 'IL', 'zip_code' => '60010']);

    return ['client' => Client::query()->find($client->id), 'project' => Project::query()->find($project->id)];
}

it('indexes a client under its people\'s nicknames too — "Dick" and "Dick Egger" beside Richard Egger', function () {
    ['client' => $client] = dickEgger();

    $indexed = $client->toSearchableArray();

    expect($indexed['client_user_nicknames'])->toBe(['Dick'])
        ->and($indexed['client_user_nickname_full_names'])->toBe(['Dick Egger'])
        ->and($indexed['client_user_first_names'])->toBe(['Richard', 'Mary'])
        ->and($indexed['client_search'])->toContain('Richard Egger')
        ->and($indexed['client_search'])->toContain('Dick Egger')
        ->and(config('scout.meilisearch.index-settings.'.Client::class.'.searchableAttributes'))->toContain('client_user_nicknames', 'client_user_nickname_full_names');

    // The bulk import loads the same columns the per-row path reads.
    $bulk = Client::query()->whereKey($client->id)->tap(fn ($q) => (new \ReflectionMethod(Client::class, 'makeAllSearchableUsing'))->invoke($client, $q))->first();
    expect($bulk->toSearchableArray()['client_user_nicknames'])->toBe(['Dick']);
});

it('indexes a project under the client\'s nicknames the same way', function () {
    ['project' => $project] = dickEgger();

    $indexed = $project->toSearchableArray();

    expect($indexed['client_user_nicknames'])->toBe(['Dick'])
        ->and($indexed['client_user_nickname_full_names'])->toBe(['Dick Egger'])
        ->and($indexed['client_search'])->toContain('Dick Egger')
        ->and(config('scout.meilisearch.index-settings.'.Project::class.'.searchableAttributes'))->toContain('client_user_nicknames', 'client_user_nickname_full_names');

    $bulk = Project::query()->whereKey($project->id)->tap(fn ($q) => (new \ReflectionMethod(Project::class, 'makeAllSearchableUsing'))->invoke($project, $q))->first();
    expect($bulk->toSearchableArray()['client_user_nickname_full_names'])->toBe(['Dick Egger']);
});
