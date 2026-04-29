<?php

use App\Http\Controllers\CompanyEmailController;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Invoke the protected detectBillToClientId() method via reflection.
 */
function invokeDetectBillToClientId(string $rawContent): ?int
{
    $controller = app(CompanyEmailController::class);
    $ref = new ReflectionMethod($controller, 'detectBillToClientId');
    $ref->setAccessible(true);

    return $ref->invoke($controller, $rawContent);
}

/**
 * Create a User without invoking the default factory state, which references
 * an `email_verified_at` column that has been dropped in this app's schema.
 */
function makeUser(string $first, string $last): User
{
    static $i = 0;
    $i++;

    return User::query()->create([
        'first_name' => $first,
        'last_name' => $last,
        'email' => 'test'.$i.'@example.com',
        'cell_phone' => 2240000000 + $i,
    ]);
}

it('detects a client by matching first+last name in the SOLD TO block', function () {
    $client = Client::withoutGlobalScopes()->create([
        'address' => '239 Perth Rd',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => '60013',
    ]);

    $user = makeUser('Lou', 'Friedman');

    $client->users()->attach($user->id);

    $rawContent = <<<TXT
STUDIO 41
Order Acknowledgement
Order # S2432212

SOLD TO:
LOU & RICK FRIEDMAN
239 PERTH RD
CARY, IL 60013

SHIP TO:
GS CONSTRUCTION
TXT;

    expect(invokeDetectBillToClientId($rawContent))->toBe($client->id);
});

it('returns null when the SOLD TO block has no matching user', function () {
    Client::withoutGlobalScopes()->create([
        'address' => '1 Main',
        'city' => 'Nowhere',
        'state' => 'IL',
        'zip_code' => '60000',
    ])->users()->attach(makeUser('Alice', 'Smith')->id);

    $rawContent = "SOLD TO:\nBOB JONES\n123 ANY ST\n";

    expect(invokeDetectBillToClientId($rawContent))->toBeNull();
});

it('returns null when no BILL/SOLD/SHIP/QUOTE/ORDER TO label is present', function () {
    expect(invokeDetectBillToClientId("Just a plain receipt\nTotal: $10\n"))->toBeNull();
});

it('returns null when multiple distinct clients match', function () {
    $clientA = Client::withoutGlobalScopes()->create([
        'address' => 'A', 'city' => 'A', 'state' => 'IL', 'zip_code' => '60001',
    ]);
    $clientB = Client::withoutGlobalScopes()->create([
        'address' => 'B', 'city' => 'B', 'state' => 'IL', 'zip_code' => '60002',
    ]);

    $clientA->users()->attach(makeUser('Lou', 'Friedman')->id);

    $clientB->users()->attach(makeUser('Jane', 'Doe')->id);

    $rawContent = "SOLD TO:\nLOU FRIEDMAN AND JANE DOE\n";

    expect(invokeDetectBillToClientId($rawContent))->toBeNull();
});

it('returns the same client when multiple users of one client match', function () {
    $client = Client::withoutGlobalScopes()->create([
        'address' => '239 Perth Rd', 'city' => 'Cary', 'state' => 'IL', 'zip_code' => '60013',
    ]);

    $client->users()->attach(makeUser('Lou', 'Friedman')->id);
    $client->users()->attach(makeUser('Rick', 'Friedman')->id);

    $rawContent = "SOLD TO:\nLOU & RICK FRIEDMAN\n239 PERTH RD\n";

    expect(invokeDetectBillToClientId($rawContent))->toBe($client->id);
});

it('picks the higher-scoring client when zip code matches one of them', function () {
    $clientA = Client::withoutGlobalScopes()->create([
        'address' => '239 Perth Rd', 'city' => 'Cary', 'state' => 'IL', 'zip_code' => '60013',
    ]);
    $clientB = Client::withoutGlobalScopes()->create([
        'address' => '999 Other St', 'city' => 'Elsewhere', 'state' => 'IL', 'zip_code' => '99999',
    ]);

    // Both clients have a user with the same name — without scoring this would tie.
    $clientA->users()->attach(makeUser('John', 'Smith')->id);
    $clientB->users()->attach(makeUser('John', 'Smith')->id);

    // Block contains clientA's zip + street → clientA wins.
    $rawContent = "SOLD TO:\nJOHN SMITH\n239 PERTH RD\nCARY, IL 60013\n";

    expect(invokeDetectBillToClientId($rawContent))->toBe($clientA->id);
});

it('returns null when two distinct clients tie on score', function () {
    $clientA = Client::withoutGlobalScopes()->create([
        'address' => 'A', 'city' => 'A', 'state' => 'IL', 'zip_code' => '60001',
    ]);
    $clientB = Client::withoutGlobalScopes()->create([
        'address' => 'B', 'city' => 'B', 'state' => 'IL', 'zip_code' => '60002',
    ]);

    $clientA->users()->attach(makeUser('Lou', 'Friedman')->id);
    $clientB->users()->attach(makeUser('Jane', 'Doe')->id);

    // Both name matches occur, neither address/zip appears → both score 2 → tie.
    $rawContent = "SOLD TO:\nLOU FRIEDMAN AND JANE DOE\n";

    expect(invokeDetectBillToClientId($rawContent))->toBeNull();
});
