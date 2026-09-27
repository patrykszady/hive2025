<?php

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * App\Http\Controllers\Api\Admin\V1\LeadController — Hive's "Leads" screen
 * reads sign-ups (users.registration IS NOT NULL, the exclusive marker
 * App\Livewire\Entry\Registration writes), never App\Models\Lead (the
 * general contractor's own CRM).
 */
beforeEach(function () {
    config(['services.admin_api.token' => 'test-admin-api-token']);
});

function signedUpUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'registration' => ['registered' => true],
    ], $overrides));
}

it('requires a bearer token', function () {
    $this->getJson('/api/admin/v1/leads')->assertUnauthorized();
});

it('only lists users the public sign-up flow touched', function () {
    signedUpUser(['first_name' => 'Signed', 'last_name' => 'Up']);
    // A team member added by an existing company (App\Livewire\Forms\
    // UserForm::store()) never sets `registration` — never a lead.
    User::factory()->create(['first_name' => 'Team', 'last_name' => 'Member', 'registration' => null]);

    $data = $this->getJson('/api/admin/v1/leads', adminApiHeaders())->assertOk()->json('data');

    expect($data)->toHaveCount(1);
    expect($data[0]['name'])->toBe('Signed Up');
});

it('counts a still-mid-verification sign-up as a lead too', function () {
    // Phone verified, email/password not yet set — Registration::
    // updateRegistrationStep() already persisted this much.
    signedUpUser(['registration' => ['phone_code_sent' => true, 'last_step' => 'phone_code_sent']]);

    $data = $this->getJson('/api/admin/v1/leads', adminApiHeaders())->assertOk()->json('data');

    expect($data)->toHaveCount(1);
    expect($data[0]['status'])->toBe('legitimate');
});

it('answers the paginated envelope with every row key', function () {
    signedUpUser();
    signedUpUser();

    $response = $this->getJson('/api/admin/v1/leads', adminApiHeaders())->assertOk();

    $response->assertJsonStructure([
        'data' => [[
            'id', 'name', 'email', 'phone', 'message', 'status', 'source',
            'referrer', 'utm_source', 'utm_campaign', 'created_at',
        ]],
        'meta' => ['current_page', 'per_page', 'total', 'last_page'],
    ]);
    expect($response->json('meta.total'))->toBe(2);
    expect($response->json('meta.per_page'))->toBe(20);
});

it('shapes a row with the fixed status and source and a null message', function () {
    $user = signedUpUser(['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com', 'cell_phone' => '(224) 999-3880']);

    $data = $this->getJson("/api/admin/v1/leads/{$user->id}", adminApiHeaders())
        ->assertOk()->json('data');

    expect($data['name'])->toBe('Jane Doe');
    expect($data['email'])->toBe('jane@example.com');
    expect($data['phone'])->toBe('(224) 999-3880');
    expect($data['message'])->toBeNull();
    expect($data['status'])->toBe('legitimate');
    expect($data['source'])->toBe('Sign-up');
    expect($data['referrer'])->toBeNull();
    expect($data['utm_source'])->toBeNull();
    expect($data['utm_campaign'])->toBeNull();
});

it('prefers the attached company name once a vendor is linked', function () {
    $vendor = Vendor::factory()->create(['business_name' => 'Acme Roofing LLC']);
    $user = signedUpUser(['first_name' => 'Jane', 'last_name' => 'Doe', 'primary_vendor_id' => $vendor->id]);

    $data = $this->getJson("/api/admin/v1/leads/{$user->id}", adminApiHeaders())
        ->assertOk()->json('data');

    expect($data['name'])->toBe('Acme Roofing');
});

it('search filters across first name, last name, email and phone', function () {
    signedUpUser(['first_name' => 'Findme', 'last_name' => 'Smith']);
    signedUpUser(['first_name' => 'Other', 'last_name' => 'Person', 'email' => 'match-phone@example.com', 'cell_phone' => '8005551234']);
    signedUpUser(['first_name' => 'Third', 'last_name' => 'Person', 'email' => 'thirdmatch@example.com']);
    signedUpUser(['first_name' => 'Fourth', 'last_name' => 'Person', 'email' => 'nomatch@example.com', 'cell_phone' => '1115551234']);

    $names = collect($this->getJson('/api/admin/v1/leads?search=Findme', adminApiHeaders())->json('data'))
        ->pluck('name')->all();
    expect($names)->toBe(['Findme Smith']);

    expect($this->getJson('/api/admin/v1/leads?search=8005551234', adminApiHeaders())->json('data'))->toHaveCount(1);
    expect($this->getJson('/api/admin/v1/leads?search=thirdmatch', adminApiHeaders())->json('data'))->toHaveCount(1);
});

it('status filter never excludes a legitimate row but spam matches none', function () {
    signedUpUser();
    signedUpUser();

    expect($this->getJson('/api/admin/v1/leads?status=spam', adminApiHeaders())->json('data'))->toHaveCount(0);
    expect($this->getJson('/api/admin/v1/leads?status=legitimate', adminApiHeaders())->json('data'))->toHaveCount(2);
    expect($this->getJson('/api/admin/v1/leads?status=pending', adminApiHeaders())->json('data'))->toHaveCount(2);
    expect($this->getJson('/api/admin/v1/leads', adminApiHeaders())->json('data'))->toHaveCount(2);
});

it('date range filter scopes by created_at', function () {
    signedUpUser(['created_at' => now()]);
    signedUpUser(['created_at' => now()->subDays(3)]);
    signedUpUser(['created_at' => now()->subDays(10)]);
    signedUpUser(['created_at' => now()->subMonths(2)]);

    expect($this->getJson('/api/admin/v1/leads?date_range=today', adminApiHeaders())->json('data'))->toHaveCount(1);
    expect($this->getJson('/api/admin/v1/leads?date_range=week', adminApiHeaders())->json('data'))->toHaveCount(2);
    expect($this->getJson('/api/admin/v1/leads?date_range=month', adminApiHeaders())->json('data'))->toHaveCount(3);
    expect($this->getJson('/api/admin/v1/leads', adminApiHeaders())->json('data'))->toHaveCount(4);
});

it('source filter never excludes a lead', function () {
    signedUpUser();

    expect($this->getJson('/api/admin/v1/leads?source=Sign-up', adminApiHeaders())->json('data'))->toHaveCount(1);
    expect($this->getJson('/api/admin/v1/leads?source=web', adminApiHeaders())->json('data'))->toHaveCount(1);
});

it('paginates and reports meta, newest first', function () {
    $older = signedUpUser(['created_at' => now()->subDay()]);
    $newer = signedUpUser(['created_at' => now()]);
    signedUpUser(['created_at' => now()->subDays(2)]);

    $response = $this->getJson('/api/admin/v1/leads?per_page=2', adminApiHeaders())->assertOk();

    expect($response->json('data'))->toHaveCount(2);
    expect($response->json('meta.per_page'))->toBe(2);
    expect($response->json('meta.total'))->toBe(3);
    expect($response->json('meta.last_page'))->toBe(2);
    expect($response->json('data.0.id'))->toBe($newer->id);
});

it('refuses status updates with a calm 405, not a delete or a mutation', function () {
    $user = signedUpUser();

    $this->patchJson("/api/admin/v1/leads/{$user->id}/status", ['status' => 'spam'], adminApiHeaders())
        ->assertStatus(405)
        ->assertJsonPath('message', "Sign-ups are accounts; they can't be marked or deleted here.");

    expect(User::find($user->id))->not->toBeNull();
});

it('refuses delete with a calm 405', function () {
    $user = signedUpUser();

    $this->deleteJson("/api/admin/v1/leads/{$user->id}", [], adminApiHeaders())
        ->assertStatus(405)
        ->assertJsonPath('message', "Sign-ups are accounts; they can't be marked or deleted here.");

    expect(User::find($user->id))->not->toBeNull();
});

it('404s show for a user the sign-up flow never touched', function () {
    $user = User::factory()->create(['registration' => null]);

    $this->getJson("/api/admin/v1/leads/{$user->id}", adminApiHeaders())->assertNotFound();
});

it('404s show for a missing id', function () {
    $this->getJson('/api/admin/v1/leads/999999', adminApiHeaders())->assertNotFound();
});

it('reports the stats shape', function () {
    signedUpUser(['created_at' => now()]);
    signedUpUser(['created_at' => now()]);
    signedUpUser(['created_at' => now()->subDays(30)]);
    // Not a lead: never touched the sign-up flow, must not count.
    User::factory()->create(['registration' => null]);

    $data = $this->getJson('/api/admin/v1/leads/stats', adminApiHeaders())
        ->assertOk()->json('data');

    expect($data['total'])->toBe(3);
    expect($data['today'])->toBe(2);
    expect($data['spam'])->toBe(0);
    // 'new': the 30-day-old one excluded from the last-7-days count.
    expect($data['new'])->toBe(2);
    expect($data['sources'])->toBe([['source' => 'Sign-up', 'count' => 3]]);
    expect($data['top_cities'])->toBe([]);
    expect($data['traffic_sources'])->toBe([]);
});
