<?php

use App\Models\Client;
use App\Models\Lead;
use App\Models\LeadFeedback;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    // No real Meilisearch/Nylas round trips from fixture setup — same
    // reasoning as AppPaymentsTest.
    config(['scout.driver' => null]);
});

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * AppAuthTest's appTeamUser(). $role is the vendor_user pivot's role_id
 * (1 = Admin, 2 = Member) — LeadPolicy's viewAny/view gate EVERY lead
 * action on vendor_role === 'Admin' (create/update/delete are
 * unimplemented stubs, so App\Livewire\Leads\LeadsIndex authorizes
 * 'viewAny' for every write too — this controller mirrors that).
 *
 * @return array{vendor: Vendor, user: User}
 */
function appSalesTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

/** @return array<string, mixed> */
function appSalesLeadData(array $overrides = []): array
{
    return array_merge([
        'name' => 'Debby Hill',
        'email' => 'debby.'.uniqid().'@example.test',
        'phone' => '2245321090',
        'address' => '1463 W Winnetka St, Palatine, IL 60067',
        'message' => 'Looking for a kitchen remodel quote.',
    ], $overrides);
}

function appSalesLead(Vendor $vendor, array $overrides = [], string $status = 'New'): Lead
{
    $leadData = $overrides['lead_data'] ?? appSalesLeadData();
    unset($overrides['lead_data']);

    $lead = Lead::create(array_merge([
        'date' => now(),
        'origin' => 'Website',
        'lead_data' => $leadData,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => User::factory()->create(['primary_vendor_id' => $vendor->id])->id,
    ], $overrides));

    $lead->statuses()->create(['title' => $status, 'belongs_to_vendor_id' => $vendor->id]);

    return $lead;
}

it('needs a token to list leads', function () {
    $this->getJson('/api/app/v1/leads')->assertUnauthorized();
});

it('refuses a Member viewing the leads list, like the web', function () {
    ['user' => $member] = appSalesTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->getJson('/api/app/v1/leads')->assertForbidden();
});

it('allows an Admin to list leads, newest date first, filtered by status', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $older = appSalesLead($vendor, ['date' => now()->subDays(3)], 'New');
    $won = appSalesLead($vendor, ['date' => now()->subDay()], 'Won');

    $response = $this->getJson('/api/app/v1/leads')->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$won->id, $older->id]);

    $filtered = $this->getJson('/api/app/v1/leads?'.http_build_query(['statuses' => ['Won']]))->assertOk();
    expect(collect($filtered->json('data'))->pluck('id')->all())->toBe([$won->id])
        ->and($filtered->json('data.0.status.code'))->toBe('Won');
});

it('filters the leads list by origin and search', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $angi = appSalesLead($vendor, ['origin' => 'Angi', 'lead_data' => appSalesLeadData(['name' => 'Carri Alan'])]);
    appSalesLead($vendor, ['origin' => 'Website', 'lead_data' => appSalesLeadData(['name' => 'Someone Else'])]);

    $byOrigin = $this->getJson('/api/app/v1/leads?origin=Angi')->assertOk();
    expect(collect($byOrigin->json('data'))->pluck('id')->all())->toBe([$angi->id]);

    $bySearch = $this->getJson('/api/app/v1/leads?search=carri')->assertOk();
    expect(collect($bySearch->json('data'))->pluck('id')->all())->toBe([$angi->id]);
});

it('shows the new-lead badge count to an Admin and 0 to a Member, like the web sidebar', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    ['user' => $member] = appSalesTeamUser(role: 2);
    appSalesLead($vendor, [], 'New');
    appSalesLead($vendor, [], 'Won');

    Sanctum::actingAs($admin, ['app']);
    $this->getJson('/api/app/v1/leads/badge')->assertOk()->assertJson(['count' => 1]);

    Sanctum::actingAs($member, ['app']);
    $this->getJson('/api/app/v1/leads/badge')->assertOk()->assertJson(['count' => 0]);
});

it('404s a lead that does not exist', function () {
    ['user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $this->getJson('/api/app/v1/leads/999999')->assertNotFound();
});

it('refuses a Member viewing a lead detail, like the web', function () {
    ['vendor' => $vendor, 'user' => $member] = appSalesTeamUser(role: 2);
    $lead = appSalesLead($vendor);

    Sanctum::actingAs($member, ['app']);

    $this->getJson("/api/app/v1/leads/{$lead->id}")->assertForbidden();
});

it('shows a lead detail with its message, attachments and status history', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $lead = appSalesLead($vendor, [
        'lead_data' => appSalesLeadData([
            'attachments' => [
                ['name' => 'bid.pdf', 'mime' => 'application/pdf', 'path' => 'leads/bid.pdf'],
            ],
        ]),
    ]);
    LeadFeedback::create(['lead_id' => $lead->id, 'rating' => 5, 'message' => 'Great visit!']);

    $response = $this->getJson("/api/app/v1/leads/{$lead->id}")->assertOk();

    expect($response->json('data.id'))->toBe($lead->id)
        ->and($response->json('data.message'))->toBe('Looking for a kitchen remodel quote.')
        ->and($response->json('data.attachments.0.name'))->toBe('bid.pdf')
        ->and($response->json('data.attachments.0.url'))->toContain('/leads/'.$lead->id.'/file/0/signed')
        ->and($response->json('data.feedback.0.rating'))->toBe(5)
        ->and($response->json('data.status_history.0.title'))->toBe('New')
        ->and($response->json('data.can_delete'))->toBeTrue();
});

it('creates a lead by hand, like a phoned-in enquiry', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $response = $this->postJson('/api/app/v1/leads', [
        'full_name' => 'New Caller',
        'phone' => '2245550123',
        'address' => '10 Main St',
        'message' => 'Called about a bathroom remodel.',
    ])->assertCreated();

    $lead = Lead::query()->where('belongs_to_vendor_id', $vendor->id)->sole();
    expect($lead->lead_data['name'])->toBe('New Caller')
        ->and($lead->lead_data['phone'])->toBe('2245550123')
        ->and($lead->origin)->toBe('Manual')
        ->and($lead->last_status->title)->toBe('New')
        ->and($response->json('data.id'))->toBe($lead->id);
});

it('rejects a new lead with neither email nor phone, like the web', function () {
    ['user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/leads', ['full_name' => 'No Contact'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('warns about a duplicate contact before creating a lead, then allows "create anyway"', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $existing = appSalesLead($vendor, ['lead_data' => appSalesLeadData(['email' => 'dupe@example.test'])]);

    $warned = $this->postJson('/api/app/v1/leads', [
        'full_name' => 'Dupe Caller',
        'email' => 'dupe@example.test',
    ])->assertStatus(409);

    expect($warned->json('duplicate.lead_id'))->toBe($existing->id);
    expect(Lead::query()->where('belongs_to_vendor_id', $vendor->id)->count())->toBe(1);

    $this->postJson('/api/app/v1/leads', [
        'full_name' => 'Dupe Caller',
        'email' => 'dupe@example.test',
        'force' => true,
    ])->assertCreated();

    expect(Lead::query()->where('belongs_to_vendor_id', $vendor->id)->count())->toBe(2);
});

it('refuses a Member creating a lead, like the web', function () {
    ['user' => $member] = appSalesTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->postJson('/api/app/v1/leads', ['full_name' => 'Nope', 'phone' => '2245550123'])
        ->assertForbidden();
});

it('changes a lead status to any selectable status, like LeadsIndex::updateLeadStatus()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $lead = appSalesLead($vendor);

    $this->postJson("/api/app/v1/leads/{$lead->id}/status", ['status' => 'Won'])
        ->assertOk()
        ->assertJsonPath('data.status.code', 'Won');

    expect($lead->fresh()->last_status->title)->toBe('Won');
});

it('rejects a status outside Lead::selectableStatuses()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $lead = appSalesLead($vendor);

    $this->postJson("/api/app/v1/leads/{$lead->id}/status", ['status' => 'Bogus'])
        ->assertStatus(422);
});

it('refuses a Member changing a lead status, like the web', function () {
    ['vendor' => $vendor, 'user' => $member] = appSalesTeamUser(role: 2);
    $lead = appSalesLead($vendor);

    Sanctum::actingAs($member, ['app']);

    $this->postJson("/api/app/v1/leads/{$lead->id}/status", ['status' => 'Won'])->assertForbidden();
});

it("updates a lead's contact and address fields without touching its status", function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $lead = appSalesLead($vendor);

    $this->patchJson("/api/app/v1/leads/{$lead->id}", [
        'phone' => '2245551234',
        'city' => 'Northbrook',
    ])->assertOk();

    $fresh = $lead->fresh();
    expect($fresh->lead_data['phone'])->toBe('2245551234')
        ->and($fresh->lead_data['city'])->toBe('Northbrook')
        ->and($fresh->last_status->title)->toBe('New');
});

it('deletes a lead that has not replied', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $lead = appSalesLead($vendor);

    $this->deleteJson("/api/app/v1/leads/{$lead->id}")->assertOk();

    expect(Lead::query()->withTrashed()->find($lead->id)?->trashed() ?? true)->toBeTrue();
});

it('refuses to delete a lead that has replied, like LeadCreate::remove()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $lead = appSalesLead($vendor, [], 'Replied');

    $this->deleteJson("/api/app/v1/leads/{$lead->id}")->assertStatus(422);

    expect(Lead::query()->find($lead->id))->not->toBeNull();
});

it("hands back the lead's own scheduling link", function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $lead = appSalesLead($vendor);

    $response = $this->postJson("/api/app/v1/leads/{$lead->id}/schedule-link")->assertOk();

    // URL_SHORTENER_ENABLED=false in testing (phpunit.xml) — UrlShortener
    // falls back to the full signed URL, same as the web would in this env.
    expect($response->json('url'))->toContain('/lead/times/'.$lead->id);
});

it('texts the scheduling link to the lead\'s phone, like LeadCreate::textScheduleLink()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    Http::fake(['https://api.telnyx.com/*' => Http::response(['data' => ['id' => 'telnyx-test']], 200)]);

    $lead = appSalesLead($vendor, ['lead_data' => appSalesLeadData(['phone' => '2245321090'])]);

    $response = $this->postJson("/api/app/v1/leads/{$lead->id}/text-schedule-link")->assertOk();

    expect($response->json('ok'))->toBeTrue();
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telnyx.com'));
});

it('warns instead of texting when the lead has no phone number', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $lead = appSalesLead($vendor, ['lead_data' => appSalesLeadData(['phone' => null])]);

    $response = $this->postJson("/api/app/v1/leads/{$lead->id}/text-schedule-link")->assertStatus(422);

    expect($response->json('heading'))->toBe('No phone number');
});
