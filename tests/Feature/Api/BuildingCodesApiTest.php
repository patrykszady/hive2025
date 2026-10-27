<?php

use App\Jobs\Permits\FindVillageCodes;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VillageCodes;
use App\Support\Permits\BuildingCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
 * gs.construction asks for each served town's adopted building codes to put
 * on its town page (2026-10-27). Answers come from village_codes; an unknown
 * town starts a lookup and answers pending.
 */

function codesApiUser(): User
{
    $vendor = Vendor::factory()->create();
    $api = new User();
    $api->forceFill([
        'first_name' => 'Site', 'last_name' => 'Bot',
        'email' => 'site-bot.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $api->save();
    $vendor->users()->attach($api->id, ['role_id' => 1]);

    return $api;
}

beforeEach(fn () => Queue::fake());

it('answers a town on file with its codes, source and the day they were read', function () {
    Sanctum::actingAs(codesApiUser());
    VillageCodes::factory()->create([
        'town' => 'Lake Zurich',
        'source_url' => 'https://lakezurich.org/202/Residential-Codes',
        'codes' => ['International Residential Code, 2018 Edition', 'National Electrical Code, 2017 Edition'],
        'note' => null,
        'checked_at' => '2026-10-27 13:00:00',
    ]);

    $this->getJson('/api/v1/building-codes?town=lake%20zurich&state=IL')
        ->assertOk()
        ->assertExactJson(['data' => [
            'town' => 'Lake Zurich',
            'state' => 'IL',
            'status' => 'found',
            'codes' => ['International Residential Code, 2018 Edition', 'National Electrical Code, 2017 Edition'],
            'source_url' => 'https://lakezurich.org/202/Residential-Codes',
            'note' => null,
            'checked_at' => '2026-10-27T13:00:00+00:00',
        ]]);

    Queue::assertNotPushed(FindVillageCodes::class);
});

it('starts a lookup for a town nobody has read yet and says it is pending, once', function () {
    Sanctum::actingAs(codesApiUser());

    $this->getJson('/api/v1/building-codes?town=Village%20of%20Wheeling')
        ->assertOk()
        ->assertJsonPath('data.town', 'Wheeling')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.codes', []);
    $this->getJson('/api/v1/building-codes?town=Wheeling')->assertJsonPath('data.status', 'pending');

    Queue::assertPushed(FindVillageCodes::class, 1);
    Queue::assertPushedOn('background', FindVillageCodes::class);
    expect(VillageCodes::query()->named('Wheeling')->sole()->isPending())->toBeTrue();
});

it('answers the hand-typed towns from their list before their row exists', function () {
    Sanctum::actingAs(codesApiUser());

    $this->getJson('/api/v1/building-codes?town=Rolling%20Meadows')
        ->assertOk()
        ->assertJsonPath('data.status', 'found')
        ->assertJsonPath('data.codes', BuildingCodes::JURISDICTIONS['Rolling Meadows']['codes'])
        ->assertJsonPath('data.source_url', 'https://www.cityrm.org/149/Building-Codes');

    Queue::assertNotPushed(FindVillageCodes::class);
});

it('hides what a failed lookup found nothing about, refuses bad input and strangers', function () {
    $user = codesApiUser();
    VillageCodes::factory()->notFound()->create(['town' => 'Long Grove']);

    $this->getJson('/api/v1/building-codes?town=Long%20Grove')->assertUnauthorized();

    Sanctum::actingAs($user);
    $this->getJson('/api/v1/building-codes?town=Long%20Grove')
        ->assertOk()
        ->assertJsonPath('data.status', 'not_found')
        ->assertJsonPath('data.codes', [])
        ->assertJsonPath('data.source_url', null);

    $this->getJson('/api/v1/building-codes?town=<script>')->assertUnprocessable()->assertJsonValidationErrors('town');
    $this->getJson('/api/v1/building-codes?town=Wheeling&state=Illinois')->assertUnprocessable()->assertJsonValidationErrors('state');
});
