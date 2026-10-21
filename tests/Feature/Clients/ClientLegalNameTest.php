<?php

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Documents a client signs or keeps use the legal first name; the app's
 * screens keep the nickname (estimate 273: Zachary "Zach" Wong, 2026-10-21).
 */
function legal_name_client(array $people, ?string $businessName = null): Client
{
    $client = Client::factory()->create(['business_name' => $businessName]);

    foreach ($people as [$first, $nickname, $last]) {
        $user = User::factory()->create(['first_name' => $first, 'nickname' => $nickname, 'last_name' => $last]);
        $client->users()->attach($user->id);
    }

    return $client->fresh();
}

it('uses the legal first name on documents and the nickname on screen', function () {
    $client = legal_name_client([['Zachary', 'Zach', 'Wong']]);

    expect($client->legal_name)->toBe('Zachary Wong')
        ->and($client->name)->toBe('Zach Wong');
});

it('builds couples and mixed last names the same way as the display name', function () {
    $couple = legal_name_client([['Richard', 'Dick', 'Lee'], ['Margaret', 'Peggy', 'Lee']]);
    $mixed = legal_name_client([['Zachary', 'Zach', 'Wong'], ['Jennifer', null, 'Kim']]);

    expect($couple->legal_name)->toBe('Richard & Margaret Lee')
        ->and($couple->name)->toBe('Dick & Peggy Lee')
        ->and($mixed->legal_name)->toBe('Zachary Wong & Jennifer Kim');
});

it('keeps a business name as written', function () {
    expect(legal_name_client([['Zachary', 'Zach', 'Wong']], 'Wong Holdings, LLC')->legal_name)->toBe('Wong Holdings');
});
