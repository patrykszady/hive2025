<?php

use App\Jobs\CreateMeetTaskCalendarEvent;
use App\Jobs\SendLeadReplyJob;
use App\Models\Client;
use App\Models\CompanyEmail;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\LeadFeedback;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * A vendor-1 lead with a linked client contact (no phone, so the
 * companion-text path is a no-op and tests never need to fake GroupSmsService/
 * Telnyx) and whatever availability slots the caller wants — the Message
 * composer and consult-booking endpoints need a resolvable client, unlike
 * appSalesLead()'s bare lead.
 *
 * @return array{contact: User, client: Client, lead: Lead}
 */
function appSalesLeadWithClient(Vendor $vendor, array $availability = []): array
{
    $contact = User::query()->create([
        'first_name' => 'Preet',
        'last_name' => 'Singh',
        'email' => 'preet.'.uniqid().'@example.test',
    ]);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);
    $client->users()->attach($contact->id);

    $lead = Lead::create([
        'date' => now(),
        'origin' => 'Website',
        'user_id' => $contact->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $contact->id,
        'lead_data' => [
            'name' => 'Preet Singh',
            'email' => $contact->email,
            'address' => '123 Main St, Palatine, IL 60067',
            'message' => 'Kitchen remodel',
            'availability' => $availability,
        ],
    ]);
    $lead->statuses()->create(['title' => 'New', 'belongs_to_vendor_id' => $vendor->id]);

    return compact('contact', 'client', 'lead');
}

/** The next bookable (non-weekend) weekday from today, as 'Y-m-d'. */
function appSalesNextWeekday(int $daysOut = 2): string
{
    $date = now()->addDays($daysOut);
    while ($date->isWeekend()) {
        $date->addDay();
    }

    return $date->format('Y-m-d');
}

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

it('sorts the leads list by date asc/desc via sort/direction, like LeadsIndex::sort()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $older = appSalesLead($vendor, ['date' => now()->subDays(3)]);
    $newer = appSalesLead($vendor, ['date' => now()->subDay()]);

    $desc = $this->getJson('/api/app/v1/leads?'.http_build_query(['sort' => 'date', 'direction' => 'desc']))->assertOk();
    expect(collect($desc->json('data'))->pluck('id')->all())->toBe([$newer->id, $older->id])
        ->and($desc->json('sort'))->toBe('date')
        ->and($desc->json('direction'))->toBe('desc');

    $asc = $this->getJson('/api/app/v1/leads?'.http_build_query(['sort' => 'date', 'direction' => 'asc']))->assertOk();
    expect(collect($asc->json('data'))->pluck('id')->all())->toBe([$older->id, $newer->id])
        ->and($asc->json('direction'))->toBe('asc');
});

it('ignores an unsortable column, falling back to date desc', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    appSalesLead($vendor, ['date' => now()->subDays(3)]);
    appSalesLead($vendor, ['date' => now()->subDay()]);

    $response = $this->getJson('/api/app/v1/leads?sort=notes')->assertOk();

    expect($response->json('sort'))->toBe('date');
});

it('shows has_feedback true only for a lead with feedback, on the list and the detail — the fixed LeadResource bug', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $withFeedback = appSalesLead($vendor);
    \App\Models\LeadFeedback::create(['lead_id' => $withFeedback->id, 'rating' => 5, 'message' => 'Great!']);
    $withoutFeedback = appSalesLead($vendor);

    $list = $this->getJson('/api/app/v1/leads')->assertOk();
    $rows = collect($list->json('data'));

    expect($rows->firstWhere('id', $withFeedback->id)['has_feedback'])->toBeTrue()
        ->and($rows->firstWhere('id', $withFeedback->id)['feedback_count'])->toBe(1)
        ->and($rows->firstWhere('id', $withoutFeedback->id)['has_feedback'])->toBeFalse();

    $detail = $this->getJson("/api/app/v1/leads/{$withFeedback->id}")->assertOk();
    expect($detail->json('data.has_feedback'))->toBeTrue();

    $detailWithout = $this->getJson("/api/app/v1/leads/{$withoutFeedback->id}")->assertOk();
    expect($detailWithout->json('data.has_feedback'))->toBeFalse();
});

it('shows the "Last" column\'s date only for a status that isn\'t a lifecycle bookend, like leads/index.blade.php', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $new = appSalesLead($vendor, [], 'New');
    $replied = appSalesLead($vendor, [], 'Replied');

    $response = $this->getJson('/api/app/v1/leads')->assertOk();
    $rows = collect($response->json('data'));

    expect($rows->firstWhere('id', $new->id)['last_status_at'])->toBeNull()
        ->and($rows->firstWhere('id', $replied->id)['last_status_at'])->not->toBeNull();
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

it('lists distinct, sorted origins for the Origin filter, like LeadsIndex::origins()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    appSalesLead($vendor, ['origin' => 'Website']);
    appSalesLead($vendor, ['origin' => 'Angi']);
    appSalesLead($vendor, ['origin' => 'Website']);
    appSalesLead($vendor, ['origin' => '']);

    $response = $this->getJson('/api/app/v1/leads/origins')->assertOk();

    expect($response->json('data'))->toBe(['Angi', 'Website']);
});

it('refuses a Member listing lead origins', function () {
    ['user' => $member] = appSalesTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->getJson('/api/app/v1/leads/origins')->assertForbidden();
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
            'city' => 'Palatine',
            'state' => 'IL',
            'zip' => '60067',
            'attachments' => [
                ['name' => 'bid.pdf', 'mime' => 'application/pdf', 'path' => 'leads/bid.pdf'],
            ],
        ]),
    ]);
    LeadFeedback::create(['lead_id' => $lead->id, 'rating' => 5, 'message' => 'Great visit!']);

    $response = $this->getJson("/api/app/v1/leads/{$lead->id}")->assertOk();

    expect($response->json('data.id'))->toBe($lead->id)
        ->and($response->json('data.message'))->toBe('Looking for a kitchen remodel quote.')
        ->and($response->json('data.city'))->toBe('Palatine')
        ->and($response->json('data.state'))->toBe('IL')
        ->and($response->json('data.zip'))->toBe('60067')
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

it('lists existing users as contact candidates for a lead that does not exist yet, like userChoices() in create mode', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $contact = User::query()->create(['first_name' => 'Preet', 'last_name' => 'Singh', 'email' => 'preet.'.uniqid().'@example.test']);
    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);
    $client->users()->attach($contact->id);

    $response = $this->getJson('/api/app/v1/leads/contact-candidates')->assertOk();

    expect($response->json('data'))->toContain([
        'id' => $contact->id,
        'name' => 'Preet Singh',
        'contact' => $contact->email,
        'label' => 'Preet Singh — '.$contact->email,
    ]);
});

it("links a picked user to a new lead before provisioning runs, so provisioning doesn't also create a duplicate contact", function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $contactEmail = 'preet.'.uniqid().'@example.test';
    $contact = User::query()->create(['first_name' => 'Preet', 'last_name' => 'Singh', 'email' => $contactEmail]);
    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);
    $client->users()->attach($contact->id);

    $usersBefore = User::query()->count();

    // Neither email nor phone is typed — picking the contact (user_id)
    // alone supplies one (the controller's own fallback, since the
    // candidate's email/phone aren't split out client-side) and skips the
    // duplicate warning this same email would otherwise trigger, since
    // LeadDuplicateFinder would match $contact's own client record.
    $response = $this->postJson('/api/app/v1/leads', [
        'full_name' => 'Preet Singh',
        'user_id' => $contact->id,
        'address' => '10 Main St',
    ])->assertCreated();

    $lead = Lead::findOrFail($response->json('data.id'));

    expect($lead->user_id)->toBe($contact->id)
        ->and($lead->lead_data['email'] ?? null)->toBe($contactEmail)
        ->and(User::query()->count())->toBe($usersBefore);
});

it('still requires a way to reach them when the picked user has no routable email or phone', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    // A placeholder email and no cell_phone — neither counts as routable
    // (User::hasRoutableEmail()/hasRoutablePhone()) — so StoreLeadRequest
    // defers the check to the controller (user_id is present), which must
    // still catch this once its own fallback comes up empty too.
    $contact = User::query()->create([
        'first_name' => 'Preet',
        'last_name' => 'Singh',
        'email' => 'preet.'.uniqid().'@'.\App\Models\User::PLACEHOLDER_EMAIL_DOMAIN,
    ]);
    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);
    $client->users()->attach($contact->id);

    $this->postJson('/api/app/v1/leads', [
        'full_name' => 'Preet Singh',
        'user_id' => $contact->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['email']);
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

    $this->putJson("/api/app/v1/leads/{$lead->id}", [
        'phone' => '2245551234',
        'city' => 'Northbrook',
    ])->assertOk();

    $fresh = $lead->fresh();
    expect($fresh->lead_data['phone'])->toBe('2245551234')
        ->and($fresh->lead_data['city'])->toBe('Northbrook')
        ->and($fresh->last_status->title)->toBe('New');
});

it('clears a contact field when the client sends it as null, unlike omitting the key entirely', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $lead = appSalesLead($vendor, ['lead_data' => appSalesLeadData(['phone' => '2245550000', 'city' => 'Northbrook'])]);

    // city is omitted entirely (as a client would when sending `undefined`)
    // and must stay untouched, while phone is explicitly cleared.
    $this->putJson("/api/app/v1/leads/{$lead->id}", ['phone' => null])->assertOk();

    $fresh = $lead->fresh();
    expect($fresh->lead_data['phone'])->toBeNull()
        ->and($fresh->lead_data['city'])->toBe('Northbrook');
});

it('never lets the client set a lead\'s origin — it stays "Manual", like the web\'s disabled Origin field', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $created = $this->postJson('/api/app/v1/leads', [
        'full_name' => 'New Caller',
        'phone' => '2245550123',
        'origin' => 'Website',
    ])->assertCreated();

    $lead = Lead::findOrFail($created->json('data.id'));
    expect($lead->origin)->toBe('Manual');

    $this->putJson("/api/app/v1/leads/{$lead->id}", ['origin' => 'Angi'])->assertOk();

    expect($lead->fresh()->origin)->toBe('Manual');
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

    $response = $this->postJson("/api/app/v1/leads/{$lead->id}/text-schedule-link")->assertOk();

    expect($response->json('ok'))->toBeFalse()
        ->and($response->json('heading'))->toBe('No phone number');
});

// --- Message composer (App\Services\Leads\LeadReplyComposer) -----------

it('returns reply-composer options with recipients, templates and availability, like LeadCreate::prepareEmailComposer()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $slot = ['date' => appSalesNextWeekday(), 'time' => '1-3 PM'];
    ['contact' => $contact, 'client' => $client, 'lead' => $lead] = appSalesLeadWithClient($vendor, [$slot]);

    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => $admin->email, 'grant_id' => '']);
    EmailTemplate::create(['vendor_id' => $vendor->id, 'name' => 'Intro', 'type' => 'lead', 'subject' => 'Re: your inquiry', 'body' => 'Hi {{client_first_name}}, {{lead_time_block}}']);

    $response = $this->getJson("/api/app/v1/leads/{$lead->id}/reply-options")->assertOk();

    expect($response->json('data.to'))->toContain($contact->email)
        ->and($response->json('data.from'))->toBe($admin->email)
        ->and($response->json('data.subject'))->toBe('Re: your inquiry')
        ->and($response->json('data.body'))->toContain('Based on the availability you shared')
        ->and($response->json('data.availability'))->toHaveCount(1)
        ->and($response->json('data.slot_problems'))->toBe([null])
        ->and($response->json('data.has_usable_availability'))->toBeTrue()
        ->and($response->json('data.client.id'))->toBe($client->id);
});

it('refuses a Member reading reply options, like the web', function () {
    ['vendor' => $vendor, 'user' => $member] = appSalesTeamUser(role: 2);
    ['lead' => $lead] = appSalesLeadWithClient($vendor);

    Sanctum::actingAs($member, ['app']);

    $this->getJson("/api/app/v1/leads/{$lead->id}/reply-options")->assertForbidden();
});

it('previews the template-filled subject/body for a selected slot, like every wire:click on the Message tab', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $slot = ['date' => appSalesNextWeekday(), 'time' => '1-3 PM'];
    ['lead' => $lead] = appSalesLeadWithClient($vendor, [$slot]);
    $template = EmailTemplate::create(['vendor_id' => $vendor->id, 'name' => 'Intro', 'type' => 'lead', 'subject' => 'Re:', 'body' => 'Pick one: {{lead_time_block}}']);

    $response = $this->postJson("/api/app/v1/leads/{$lead->id}/reply-preview", [
        'template_id' => $template->id,
        'from' => $admin->email,
        'availability' => [$slot],
        'selected_availability' => [0],
    ])->assertOk();

    expect($response->json('data.body'))->toContain('{{SELECT Time}}')
        ->and($response->json('data.exact_time_options'))->not->toBeEmpty()
        ->and($response->json('data.send_blocked_reason'))->toBe('Pick the exact consult time first');

    $exactTime = $response->json('data.exact_time_options.0.value');

    $confirmed = $this->postJson("/api/app/v1/leads/{$lead->id}/reply-preview", [
        'template_id' => $template->id,
        'from' => $admin->email,
        'availability' => [$slot],
        'selected_availability' => [0],
        'selected_exact_time' => $exactTime,
        'project_name' => 'Kitchen Remodel',
    ])->assertOk();

    expect($confirmed->json('data.body'))->not->toContain('{{SELECT')
        ->and($confirmed->json('data.send_blocked_reason'))->toBeNull();
});

it('proposes an office consult day, mirroring updatedProposeDate()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    ['lead' => $lead] = appSalesLeadWithClient($vendor);

    $date = appSalesNextWeekday(5);

    $response = $this->postJson("/api/app/v1/leads/{$lead->id}/propose-date", [
        'date' => $date,
        'availability' => [],
    ])->assertOk();

    expect($response->json('data.availability.0.date'))->toBe($date)
        ->and($response->json('data.availability.0.office_proposed'))->toBeTrue()
        ->and($response->json('data.selected_availability'))->toBe([0])
        ->and($response->json('data.exact_time_options'))->not->toBeEmpty();
});

it('refuses a weekend office-proposed date, mirroring updatedProposeDate()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    ['lead' => $lead] = appSalesLeadWithClient($vendor);

    $saturday = now()->next(\Carbon\Carbon::SATURDAY)->format('Y-m-d');

    $this->postJson("/api/app/v1/leads/{$lead->id}/propose-date", [
        'date' => $saturday,
        'availability' => [],
    ])->assertStatus(422)
        ->assertJsonFragment(['message' => 'Consultations are scheduled Monday through Friday.']);
});

it('sends a lead reply and books the consult exactly like LeadCreate::send_message()', function () {
    Queue::fake();

    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $slot = ['date' => appSalesNextWeekday(), 'time' => '1-3 PM'];
    ['contact' => $contact, 'client' => $client, 'lead' => $lead] = appSalesLeadWithClient($vendor, [$slot]);
    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => $admin->email, 'grant_id' => '']);

    $preview = $this->postJson("/api/app/v1/leads/{$lead->id}/reply-preview", [
        'from' => $admin->email,
        'body' => '{{lead_time_block}}',
        'availability' => [$slot],
        'selected_availability' => [0],
    ])->assertOk();
    $exactTime = $preview->json('data.exact_time_options.0.value');

    $response = $this->postJson("/api/app/v1/leads/{$lead->id}/reply", [
        'to' => [$contact->email],
        'from' => $admin->email,
        'subject' => 'Your consultation',
        'body' => 'Confirming: {{lead_time_block}}',
        'availability' => [$slot],
        'selected_availability' => [0],
        'selected_exact_time' => $exactTime,
        'project_name' => 'Kitchen Remodel',
    ])->assertOk();

    expect($response->json('data.booked'))->toBeTrue()
        ->and($response->json('data.new_status'))->toBe('Won');

    Queue::assertPushed(SendLeadReplyJob::class, 1);
    Queue::assertPushed(CreateMeetTaskCalendarEvent::class, 1);

    expect($lead->fresh()->last_status->title)->toBe('Won');
    $project = $client->fresh()->projects()->first();
    expect($project)->not->toBeNull();
    expect(Task::where('project_id', $project->id)->where('type', 'Meet')->exists())->toBeTrue();
});

it('blocks sending while a consult time is still unresolved, like sendBlockedReason()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $slot = ['date' => appSalesNextWeekday(), 'time' => '1-3 PM'];
    ['contact' => $contact, 'lead' => $lead] = appSalesLeadWithClient($vendor, [$slot]);
    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => $admin->email, 'grant_id' => '']);

    $this->postJson("/api/app/v1/leads/{$lead->id}/reply", [
        'to' => [$contact->email],
        'from' => $admin->email,
        'subject' => 'Your consultation',
        'body' => 'Pick a time: {{SELECT Time}}',
        'availability' => [$slot],
        'selected_availability' => [0],
    ])->assertStatus(422)
        ->assertJsonFragment(['message' => 'Pick the exact consult time first.']);

    expect($lead->fresh()->last_status->title)->toBe('New');
});

it('sends the one-click consult invite exactly like LeadCreate::sendConsultInvite()', function () {
    Queue::fake();

    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    // No availability at all — same fixture shape as the web's
    // LeadConsultInviteButtonTest: the invite just asks the homeowner to
    // self-schedule, so nothing here needs an exact time or project name.
    ['contact' => $contact, 'lead' => $lead] = appSalesLeadWithClient($vendor);
    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => $admin->email, 'grant_id' => '']);
    EmailTemplate::create(['vendor_id' => $vendor->id, 'name' => 'Consult', 'type' => 'lead', 'subject' => '{{vendor_name}} Consultation', 'body' => '<p>{{lead_time_block}}</p>']);

    $response = $this->postJson("/api/app/v1/leads/{$lead->id}/send-consult-invite", [
        'to' => [$contact->email],
        'from' => $admin->email,
        'availability' => [],
        'selected_availability' => [],
    ])->assertOk();

    expect($response->json('data.recipient_count'))->toBe(1);

    Queue::assertPushed(SendLeadReplyJob::class, function (SendLeadReplyJob $job) {
        $seen = (fn () => ['body' => $this->body, 'template' => $this->emailTemplateName])->call($job);

        return $seen['template'] === 'Consult'
            && str_contains($seen['body'], 'select consultation times')
            && ! str_contains($seen['body'], 'select new consultation times');
    });
});

it('reports "no Consult template" instead of failing silently', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    ['contact' => $contact, 'lead' => $lead] = appSalesLeadWithClient($vendor);
    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => $admin->email, 'grant_id' => '']);

    $this->postJson("/api/app/v1/leads/{$lead->id}/send-consult-invite", [
        'to' => [$contact->email],
        'from' => $admin->email,
        'availability' => [],
        'selected_availability' => [],
    ])->assertStatus(422)
        ->assertJsonFragment(['message' => 'No email template named "Consult" exists.']);
});

// --- Leads list bulk actions (App\Livewire\Leads\LeadsIndex) ------------

it('bulk-updates several leads\' status at once, like LeadsIndex::bulkSetStatus()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $one = appSalesLead($vendor);
    $two = appSalesLead($vendor);

    $this->postJson('/api/app/v1/leads/bulk-status', [
        'lead_ids' => [$one->id, $two->id],
        'status' => 'Lost',
    ])->assertOk()->assertJsonPath('data.updated', 2);

    expect($one->fresh()->last_status->title)->toBe('Lost')
        ->and($two->fresh()->last_status->title)->toBe('Lost');
});

it('reports bulk-delete impact across several leads, like LeadsIndex::bulkDeleteImpact()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    ['lead' => $withClient, 'client' => $client] = appSalesLeadWithClient($vendor);
    $bare = appSalesLead($vendor);

    $response = $this->postJson('/api/app/v1/leads/bulk-delete-impact', [
        'lead_ids' => [$withClient->id, $bare->id],
    ])->assertOk();

    expect($response->json('data.count'))->toBe(2);
});

it('bulk-deletes several leads at once, like LeadsIndex::bulkDelete()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $one = appSalesLead($vendor);
    $two = appSalesLead($vendor);

    $this->postJson('/api/app/v1/leads/bulk-delete', [
        'lead_ids' => [$one->id, $two->id],
    ])->assertOk()->assertJsonPath('data.deleted', 2);

    expect(Lead::withTrashed()->find($one->id)?->trashed())->toBeTrue()
        ->and(Lead::withTrashed()->find($two->id)?->trashed())->toBeTrue();
});

it('refuses a Member doing any bulk lead action, like the web', function () {
    ['vendor' => $vendor, 'user' => $member] = appSalesTeamUser(role: 2);
    $lead = appSalesLead($vendor);

    Sanctum::actingAs($member, ['app']);

    $this->postJson('/api/app/v1/leads/bulk-status', ['lead_ids' => [$lead->id], 'status' => 'Lost'])->assertForbidden();
    $this->postJson('/api/app/v1/leads/bulk-delete-impact', ['lead_ids' => [$lead->id]])->assertForbidden();
    $this->postJson('/api/app/v1/leads/bulk-delete', ['lead_ids' => [$lead->id]])->assertForbidden();
});

it('toggles consult-within-the-hour, like LeadCreate::updatedConsultWithinTheHour()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $lead = appSalesLead($vendor);

    $this->postJson("/api/app/v1/leads/{$lead->id}/consult-within-the-hour", ['enabled' => true])
        ->assertOk()
        ->assertJsonPath('data.allows_consult_within_the_hour', true);

    expect($lead->fresh()->lead_data['consult_notice_hours'])->toBe(1);

    $this->postJson("/api/app/v1/leads/{$lead->id}/consult-within-the-hour", ['enabled' => false])
        ->assertOk()
        ->assertJsonPath('data.allows_consult_within_the_hour', false);

    expect($lead->fresh()->lead_data['consult_notice_hours'] ?? null)->toBeNull();
});

// --- Completing a lead's contact — mirrors LeadCreate's missing-contact-info
// tools, writing through LeadContactProvisioner exactly like the web (see
// App\Services\Leads\LeadContactCompletion). -------------------------------

it('flags a lead missing a phone and reports it on the detail, like LeadCreate::missingContactInfo()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $lead = appSalesLead($vendor, [
        'lead_data' => appSalesLeadData(['phone' => null]),
    ]);

    $response = $this->getJson("/api/app/v1/leads/{$lead->id}")->assertOk();

    expect($response->json('data.needs_phone'))->toBeTrue()
        ->and($response->json('data.missing_contact_info'))->toContain('a phone number');
});

it('saves a hand-entered phone and provisions the contact, like LeadCreate::saveContactPhone()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $lead = appSalesLead($vendor, [
        'lead_data' => appSalesLeadData(['phone' => null]),
    ]);

    $response = $this->postJson("/api/app/v1/leads/{$lead->id}/phone", ['phone' => '(224) 555-9876'])
        ->assertOk();

    expect($lead->fresh()->lead_data['phone'])->toBe('2245559876')
        ->and($response->json('data.needs_phone'))->toBeFalse()
        ->and($response->json('data.phone'))->toBe('2245559876')
        // Provisioning ran: the lead now has a contact with that number.
        ->and($lead->fresh()->user?->cell_phone)->toBe('2245559876');
});

it('rejects a phone that is not 10 digits, like saveContactPhone()\'s own validation', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $lead = appSalesLead($vendor, ['lead_data' => appSalesLeadData(['phone' => null])]);

    $this->postJson("/api/app/v1/leads/{$lead->id}/phone", ['phone' => '12345'])
        ->assertStatus(422)
        ->assertJsonPath('errors.phone.0', 'Enter a 10-digit phone number.');

    expect($lead->fresh()->lead_data['phone'] ?? null)->toBeNull();
});

it('rejects a phone another contact already uses, like saveContactPhone()\'s uniqueness check', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    User::query()->create(['first_name' => 'Taken', 'last_name' => 'Number', 'email' => 'taken.'.uniqid().'@example.test', 'cell_phone' => '2245559876']);
    $lead = appSalesLead($vendor, ['lead_data' => appSalesLeadData(['phone' => null])]);

    $this->postJson("/api/app/v1/leads/{$lead->id}/phone", ['phone' => '2245559876'])
        ->assertStatus(422)
        ->assertJsonPath('errors.phone.0', 'Another contact already uses this number.');
});

it('selects a stored address candidate and provisions the client, like LeadCreate::selectAddressCandidate()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $lead = appSalesLead($vendor, [
        'lead_data' => appSalesLeadData([
            'address' => '123 Main St',
            'city' => null,
            'state' => null,
            'zip' => null,
            'address_candidates' => [
                ['address' => '123 Main St', 'city' => 'Palatine', 'state' => 'IL', 'zip_code' => '60067', 'miles' => 1.2],
                ['address' => '123 Main St', 'city' => 'Arlington Heights', 'state' => 'IL', 'zip_code' => '60004', 'miles' => 3.4],
            ],
        ]),
    ]);

    $detail = $this->getJson("/api/app/v1/leads/{$lead->id}")->assertOk();
    expect($detail->json('data.address_candidates'))->toHaveCount(2);

    $response = $this->postJson("/api/app/v1/leads/{$lead->id}/address-candidate", ['index' => 1])
        ->assertOk();

    expect($response->json('data.city'))->toBe('Arlington Heights')
        ->and($lead->fresh()->lead_data['zip'])->toBe('60004')
        ->and($lead->fresh()->lead_data)->not->toHaveKey('address_candidates')
        // Provisioning ran now that the address is complete: a client exists.
        ->and($lead->fresh()->resolveClient())->not->toBeNull();
});

it('rejects an out-of-range address candidate index', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $lead = appSalesLead($vendor, [
        'lead_data' => appSalesLeadData(['address_candidates' => [
            ['address' => '123 Main St', 'city' => 'Palatine', 'state' => 'IL', 'zip_code' => '60067', 'miles' => 1.2],
        ]]),
    ]);

    $this->postJson("/api/app/v1/leads/{$lead->id}/address-candidate", ['index' => 5])
        ->assertStatus(422);
});

it("lists this company's linkable contacts, like LeadCreate::userChoices()", function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);
    $contact = User::query()->create(['first_name' => 'Amy', 'last_name' => 'Dusto', 'email' => 'amy.'.uniqid().'@example.test']);
    $client->users()->attach($contact->id);

    $lead = appSalesLead($vendor, ['lead_data' => appSalesLeadData(['phone' => null])]);

    $response = $this->getJson("/api/app/v1/leads/{$lead->id}/contact-candidates")->assertOk();

    expect(collect($response->json('data'))->pluck('id'))->toContain($contact->id);
});

it('links an existing user as the lead contact, like LeadCreate::attachUser()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);
    $contact = User::query()->create([
        'first_name' => 'Amy', 'last_name' => 'Dusto',
        'email' => 'amy.'.uniqid().'@example.test', 'cell_phone' => '2245550001',
    ]);
    $client->users()->attach($contact->id);

    $lead = appSalesLead($vendor, ['lead_data' => appSalesLeadData(['phone' => null])]);

    $response = $this->postJson("/api/app/v1/leads/{$lead->id}/attach-user", ['user_id' => $contact->id])
        ->assertOk();

    expect($lead->fresh()->user_id)->toBe($contact->id)
        ->and($response->json('data.contact.id'))->toBe($contact->id)
        ->and($response->json('data.needs_phone'))->toBeFalse();
});

it('refuses to attach a user who is not a linkable candidate', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $otherVendor = Vendor::factory()->create();
    $staffer = User::factory()->create(['primary_vendor_id' => $otherVendor->id]);
    $otherVendor->users()->attach($staffer->id, ['role_id' => 1]);

    $lead = appSalesLead($vendor);

    $this->postJson("/api/app/v1/leads/{$lead->id}/attach-user", ['user_id' => $staffer->id])
        ->assertStatus(422);

    expect($lead->fresh()->user_id)->toBeNull();
});

it('creates and links a new user from the lead\'s own details, like LeadCreate::addUser()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $lead = appSalesLead($vendor, ['lead_data' => appSalesLeadData(['phone' => null, 'email' => null])]);

    $response = $this->postJson("/api/app/v1/leads/{$lead->id}/add-user", [
        'full_name' => 'Jordan Casey',
        'email' => 'jordan.'.uniqid().'@example.test',
    ])->assertOk();

    $fresh = $lead->fresh();
    expect($fresh->user_id)->not->toBeNull()
        ->and($fresh->user->full_name)->toBe('Jordan Casey')
        ->and($response->json('data.contact.name'))->toBe('Jordan Casey');
});

it('rejects "Add User" with neither email nor phone, like addUser()\'s own guard', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $lead = appSalesLead($vendor, ['lead_data' => appSalesLeadData(['phone' => null, 'email' => null])]);

    $this->postJson("/api/app/v1/leads/{$lead->id}/add-user", ['full_name' => 'Jordan Casey'])
        ->assertStatus(422);

    expect($lead->fresh()->user_id)->toBeNull();
});

it('refuses "Add User" once the lead already has a linked contact', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    ['lead' => $lead] = appSalesLeadWithClient($vendor);

    $this->postJson("/api/app/v1/leads/{$lead->id}/add-user", [
        'full_name' => 'Someone Else',
        'email' => 'someone.'.uniqid().'@example.test',
    ])->assertStatus(422);
});

it('refuses a Member completing a lead\'s contact, like the web', function () {
    ['vendor' => $vendor, 'user' => $member] = appSalesTeamUser(role: 2);
    $lead = appSalesLead($vendor, ['lead_data' => appSalesLeadData(['phone' => null])]);

    Sanctum::actingAs($member, ['app']);

    $this->getJson("/api/app/v1/leads/{$lead->id}/contact-candidates")->assertForbidden();
    $this->postJson("/api/app/v1/leads/{$lead->id}/phone", ['phone' => '2245559876'])->assertForbidden();
    $this->postJson("/api/app/v1/leads/{$lead->id}/attach-user", ['user_id' => 1])->assertForbidden();
    $this->postJson("/api/app/v1/leads/{$lead->id}/add-user", ['full_name' => 'X', 'email' => 'x.'.uniqid().'@example.test'])->assertForbidden();
    $this->postJson("/api/app/v1/leads/{$lead->id}/address-candidate", ['index' => 0])->assertForbidden();
});
