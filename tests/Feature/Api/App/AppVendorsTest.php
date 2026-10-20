<?php

use App\Models\Check;
use App\Models\Expense;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDoc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Scout\EngineManager;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    // No real Meilisearch round trips from fixture setup — same reasoning
    // as AppProjectsTest.
    config(['scout.driver' => null]);
});

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * AppAuthTest's appTeamUser(). Returns an Admin on that vendor (role_id 1).
 *
 * @return array{vendor: Vendor, user: User}
 */
function appFieldAdmin(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction', 'business_type' => 'Sub']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $user->vendors()->attach($vendor->id, ['role_id' => 1, 'is_employed' => 1]);

    return compact('vendor', 'user');
}

/** A Member (role_id 2) on $vendor. */
function appFieldMember(Vendor $vendor): User
{
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $user->vendors()->attach($vendor->id, ['role_id' => 2, 'is_employed' => 1]);

    return $user;
}

/** Attaches $sub into $company's vendor directory (what VendorScope checks). */
function appFieldAttach(Vendor $company, Vendor $sub): void
{
    $company->vendors()->attach($sub->id);
}

it('needs a token to list vendors', function () {
    $this->getJson('/api/app/v1/vendors')->assertUnauthorized();
});

it('lists the business types in the web select order', function () {
    ['user' => $user] = appFieldAdmin();
    Sanctum::actingAs($user, ['app']);

    $this->getJson('/api/app/v1/vendors/types')
        ->assertOk()
        ->assertExactJson(['data' => ['All', 'Sub', 'Retail', '1099', 'DBA']]);
});

it('only lists vendors in the company tree, filtered by business type', function () {
    ['vendor' => $company, 'user' => $admin] = appFieldAdmin();
    Sanctum::actingAs($admin, ['app']);

    $sub = Vendor::factory()->create(['business_name' => 'Acme Drywall', 'business_type' => 'Sub']);
    $retail = Vendor::factory()->create(['business_name' => 'Home Depot', 'business_type' => 'Retail']);
    appFieldAttach($company, $sub);
    appFieldAttach($company, $retail);

    $outsider = Vendor::factory()->create(['business_type' => 'Sub']);

    $response = $this->getJson('/api/app/v1/vendors?type=All')->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($sub->id)
        ->and($ids)->toContain($retail->id)
        ->and($ids)->not->toContain($outsider->id);

    $subOnly = $this->getJson('/api/app/v1/vendors?type=Sub')->assertOk();
    $subIds = collect($subOnly->json('data'))->pluck('id');
    expect($subIds)->toContain($sub->id)->and($subIds)->not->toContain($retail->id);
});

it('searches by business name when Meilisearch is unavailable', function () {
    ['vendor' => $company, 'user' => $admin] = appFieldAdmin();
    Sanctum::actingAs($admin, ['app']);

    $acme = Vendor::factory()->create(['business_name' => 'Acme Drywall', 'business_type' => 'Sub']);
    $other = Vendor::factory()->create(['business_name' => 'Zephyr Roofing', 'business_type' => 'Sub']);
    appFieldAttach($company, $acme);
    appFieldAttach($company, $other);

    $this->mock(EngineManager::class, function ($mock) {
        $mock->shouldReceive('engine')->andThrow(new RuntimeException('no search engine in tests'));
    });

    $response = $this->getJson('/api/app/v1/vendors?type=All&search=Acme')->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids->all())->toBe([$acme->id]);
});

it('sorts vendors by YTD Paid descending by default, like VendorsIndex', function () {
    ['vendor' => $company, 'user' => $admin] = appFieldAdmin();
    Sanctum::actingAs($admin, ['app']);

    $low = Vendor::factory()->create(['business_name' => 'Low Paid', 'business_type' => 'Sub']);
    $high = Vendor::factory()->create(['business_name' => 'High Paid', 'business_type' => 'Sub']);
    appFieldAttach($company, $low);
    appFieldAttach($company, $high);

    Expense::withoutEvents(fn () => Expense::create([
        'amount' => 50, 'date' => now()->format('Y-m-d'), 'vendor_id' => $low->id,
        'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => $admin->id,
    ]));
    Expense::withoutEvents(fn () => Expense::create([
        'amount' => 500, 'date' => now()->format('Y-m-d'), 'vendor_id' => $high->id,
        'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => $admin->id,
    ]));

    $response = $this->getJson('/api/app/v1/vendors?type=All')->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->values()->all();

    expect(array_search($high->id, $ids, true))->toBeLessThan(array_search($low->id, $ids, true))
        ->and($response->json('sort'))->toBe('ytd_expense_sum')
        ->and($response->json('direction'))->toBe('desc');
});

it('sorts vendors by business name and by Since (attached date), and carries Since on every row', function () {
    ['vendor' => $company, 'user' => $admin] = appFieldAdmin();
    Sanctum::actingAs($admin, ['app']);

    $zebra = Vendor::factory()->create(['business_name' => 'Zebra Roofing', 'business_type' => 'Sub']);
    $acme = Vendor::factory()->create(['business_name' => 'Acme Drywall', 'business_type' => 'Sub']);
    appFieldAttach($company, $zebra);
    appFieldAttach($company, $acme);

    \Illuminate\Support\Facades\DB::table('vendors_vendor')
        ->where('belongs_to_vendor_id', $company->id)->where('vendor_id', $zebra->id)
        ->update(['created_at' => now()->subDays(5)]);
    \Illuminate\Support\Facades\DB::table('vendors_vendor')
        ->where('belongs_to_vendor_id', $company->id)->where('vendor_id', $acme->id)
        ->update(['created_at' => now()->subDay()]);

    $byName = $this->getJson('/api/app/v1/vendors?type=All&sort=business_name&direction=asc')->assertOk();
    $byNameRows = collect($byName->json('data'))->keyBy('id');
    expect($byNameRows->keys()->search($acme->id))->toBeLessThan($byNameRows->keys()->search($zebra->id))
        ->and($byNameRows[$acme->id]['since'])->not->toBeNull()
        ->and($byNameRows[$zebra->id]['since'])->not->toBeNull();

    $bySince = $this->getJson('/api/app/v1/vendors?type=All&sort=attached_at&direction=asc')->assertOk();
    $sinceIds = collect($bySince->json('data'))->pluck('id')->values();
    expect($sinceIds->search($zebra->id))->toBeLessThan($sinceIds->search($acme->id));
});

it('falls back to the web default sort/direction when given an unrecognized one', function () {
    ['user' => $admin] = appFieldAdmin();
    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson('/api/app/v1/vendors?sort=nonsense&direction=nonsense')->assertOk();

    expect($response->json('sort'))->toBe('ytd_expense_sum')
        ->and($response->json('direction'))->toBe('desc');
});

it('403s a vendor outside the company tree and 404s a nonexistent one', function () {
    ['user' => $admin] = appFieldAdmin();
    Sanctum::actingAs($admin, ['app']);

    $outsider = Vendor::factory()->create();

    $this->getJson('/api/app/v1/vendors/'.$outsider->id)->assertForbidden();
    $this->getJson('/api/app/v1/vendors/999999999')->assertNotFound();
});

it('always allows viewing one\'s own company vendor record', function () {
    ['vendor' => $company, 'user' => $admin] = appFieldAdmin();
    Sanctum::actingAs($admin, ['app']);

    $this->getJson('/api/app/v1/vendors/'.$company->id)
        ->assertOk()
        ->assertJsonPath('data.id', $company->id)
        ->assertJsonPath('data.is_own_company', true)
        // Nothing to pay itself — ytd_paid stays null even though the
        // viewer can manage vendors.
        ->assertJsonPath('data.ytd_paid', null);
});

it('shows YTD paid and documents to an Admin, never to a Member', function () {
    ['vendor' => $company, 'user' => $admin] = appFieldAdmin();
    $member = appFieldMember($company);

    $sub = Vendor::factory()->create(['business_name' => 'Acme Drywall', 'business_type' => 'Sub']);
    appFieldAttach($company, $sub);

    VendorDoc::create([
        'vendor_id' => $sub->id,
        'belongs_to_vendor_id' => $company->id,
        'type' => 'workers',
        'expiration_date' => now()->addMonths(6)->format('Y-m-d'),
        'effective_date' => now()->subMonths(6)->format('Y-m-d'),
        'number' => 'WC-12345',
        'doc_filename' => 'workers-comp.pdf',
    ]);

    Sanctum::actingAs($admin, ['app']);
    $asAdmin = $this->getJson('/api/app/v1/vendors/'.$sub->id)->assertOk();
    $asAdmin->assertJsonPath('data.can_manage', true)
        ->assertJsonCount(1, 'data.documents')
        ->assertJsonPath('data.documents.0.number', 'WC-12345')
        ->assertJsonPath('data.documents.0.type_label', 'Workers');

    expect((float) $asAdmin->json('data.ytd_paid'))->toBe(0.0);

    auth()->forgetGuards();
    Sanctum::actingAs($member, ['app']);
    $asMember = $this->getJson('/api/app/v1/vendors/'.$sub->id)->assertOk();
    $asMember->assertJsonPath('data.can_manage', false)
        ->assertJsonPath('data.ytd_paid', null)
        ->assertJsonPath('data.documents', null);
});

it('hides contacts, address and documents for a Retail vendor, but still lists team members for a Sub', function () {
    ['vendor' => $company, 'user' => $admin] = appFieldAdmin();
    Sanctum::actingAs($admin, ['app']);

    $retail = Vendor::factory()->create(['business_name' => 'Home Depot', 'business_type' => 'Retail', 'address' => '1 Depot Way']);
    appFieldAttach($company, $retail);

    $sub = Vendor::factory()->create(['business_name' => 'Acme Drywall', 'business_type' => 'Sub']);
    appFieldAttach($company, $sub);
    $worker = User::factory()->create();
    $sub->users()->attach($worker->id, ['role_id' => 2, 'is_employed' => 1]);

    $this->getJson('/api/app/v1/vendors/'.$retail->id)
        ->assertOk()
        ->assertJsonPath('data.address', null)
        ->assertJsonPath('data.contacts', [])
        ->assertJsonPath('data.documents', null);

    $this->getJson('/api/app/v1/vendors/'.$sub->id)
        ->assertOk()
        ->assertJsonCount(1, 'data.contacts')
        ->assertJsonPath('data.contacts.0.id', $worker->id)
        ->assertJsonPath('data.contacts.0.role', 'Member');
});

it('groups a vendor\'s upcoming tasks by day, with a Pending Tasks split, like VendorTaskList', function () {
    ['vendor' => $company, 'user' => $admin] = appFieldAdmin();
    Sanctum::actingAs($admin, ['app']);

    $sub = Vendor::factory()->create(['business_name' => 'Acme Drywall', 'business_type' => 'Sub']);
    appFieldAttach($company, $sub);

    $client = \App\Models\Client::factory()->create();
    $project = \App\Models\Project::withoutEvents(fn () => \App\Models\Project::create([
        'project_name' => 'Kitchen Remodel',
        'client_id' => $client->id,
        'address' => '123 Main St',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => '60062',
        'belongs_to_vendor_id' => $company->id,
    ]));
    $project->vendors()->attach($company->id, ['client_id' => $client->id]);

    $tomorrow = now()->addDay()->format('Y-m-d');
    Task::factory()->create([
        'project_id' => $project->id,
        'vendor_id' => $sub->id,
        'belongs_to_vendor_id' => $company->id,
        'start_date' => $tomorrow,
        'end_date' => $tomorrow,
    ]);
    Task::factory()->create([
        'project_id' => $project->id,
        'vendor_id' => $sub->id,
        'belongs_to_vendor_id' => $company->id,
        'start_date' => null,
        'end_date' => null,
    ]);
    $tenDays = now()->addDays(10)->format('Y-m-d');
    $twelveDays = now()->addDays(12)->format('Y-m-d');
    Task::factory()->create([
        'project_id' => $project->id,
        'vendor_id' => $sub->id,
        'belongs_to_vendor_id' => $company->id,
        'start_date' => $tenDays,
        'end_date' => $twelveDays,
        'options' => ['dates' => [$tenDays, $twelveDays]],
    ]);

    $response = $this->getJson('/api/app/v1/vendors/'.$sub->id)->assertOk();

    $response
        ->assertJsonPath('data.task_count', 1)
        ->assertJsonCount(1, 'data.pending_tasks')
        ->assertJsonCount(1, 'data.upcoming_tasks')
        ->assertJsonPath('data.upcoming_tasks.0.date', $tomorrow)
        ->assertJsonPath('data.upcoming_tasks.0.is_tomorrow', true)
        ->assertJsonPath('data.upcoming_tasks.0.is_today', false)
        ->assertJsonCount(1, 'data.upcoming_tasks.0.tasks')
        ->assertJsonPath('data.upcoming_tasks.0.tasks.0.project.id', $project->id)
        ->assertJsonCount(2, 'data.later_tasks')
        ->assertJsonPath('data.later_tasks.0.date', $tenDays)
        ->assertJsonPath('data.later_tasks.1.date', $twelveDays)
        ->assertJsonPath('data.later_tasks.1.tasks.0.date', $twelveDays);
});

it('has no tasks card for a Retail vendor', function () {
    ['vendor' => $company, 'user' => $admin] = appFieldAdmin();
    Sanctum::actingAs($admin, ['app']);

    $retail = Vendor::factory()->create(['business_type' => 'Retail']);
    appFieldAttach($company, $retail);

    $this->getJson('/api/app/v1/vendors/'.$retail->id)
        ->assertOk()
        ->assertJsonPath('data.task_count', 0)
        ->assertJsonPath('data.pending_tasks', [])
        ->assertJsonPath('data.upcoming_tasks', [])
        ->assertJsonPath('data.later_tasks', []);
});

it('shows a non-Retail vendor\'s payment history, scoped to the company\'s own checks', function () {
    ['vendor' => $company, 'user' => $admin] = appFieldAdmin();
    Sanctum::actingAs($admin, ['app']);

    $sub = Vendor::factory()->create(['business_name' => 'Acme Drywall', 'business_type' => 'Sub']);
    appFieldAttach($company, $sub);

    $ourCheck = Check::create([
        'check_type' => 'Check', 'check_number' => 5001, 'date' => now()->format('Y-m-d'),
        'amount' => 400, 'vendor_id' => $sub->id, 'belongs_to_vendor_id' => $company->id,
        'created_by_user_id' => $admin->id,
    ]);

    // A different tenant's check to the same shared sub must never leak in.
    $otherCompany = Vendor::factory()->create(['business_type' => 'Sub']);
    Check::create([
        'check_type' => 'Check', 'check_number' => 6002, 'date' => now()->format('Y-m-d'),
        'amount' => 999, 'vendor_id' => $sub->id, 'belongs_to_vendor_id' => $otherCompany->id,
        'created_by_user_id' => $admin->id,
    ]);

    $response = $this->getJson('/api/app/v1/vendors/'.$sub->id)->assertOk();

    expect(collect($response->json('data.payments'))->pluck('id')->all())->toBe([$ourCheck->id])
        ->and((float) $response->json('data.payments.0.amount'))->toBe(400.0)
        ->and($response->json('data.payments.0.check_number'))->toBe(5001);
});

it('has no payments card for a vendor with no checks, or for a Retail vendor', function () {
    ['vendor' => $company, 'user' => $admin] = appFieldAdmin();
    Sanctum::actingAs($admin, ['app']);

    // Both attached up front: VendorPolicy::view caches $user->vendor's
    // loaded 'vendors' relation on first access, so attaching a second
    // vendor BETWEEN requests (within the one test) would leave it stale.
    $sub = Vendor::factory()->create(['business_type' => 'Sub']);
    $retail = Vendor::factory()->create(['business_type' => 'Retail']);
    appFieldAttach($company, $sub);
    appFieldAttach($company, $retail);

    $this->getJson('/api/app/v1/vendors/'.$sub->id)
        ->assertOk()
        ->assertJsonPath('data.payments', null);

    $this->getJson('/api/app/v1/vendors/'.$retail->id)
        ->assertOk()
        ->assertJsonPath('data.payments', null);
});

it('needs the Admin ability to create a vendor', function () {
    ['vendor' => $company, 'user' => $admin] = appFieldAdmin();
    $member = appFieldMember($company);
    Sanctum::actingAs($member, ['app']);

    $this->postJson('/api/app/v1/vendors', [
        'business_name' => 'Acme Drywall', 'business_type' => 'Sub',
        'owner_cell_phone' => '3125551234', 'owner_first_name' => 'Jane', 'owner_last_name' => 'Owner', 'owner_email' => 'jane@example.com',
    ])->assertForbidden();
});

it('creates a Sub vendor with a brand-new owner, attached as Admin at $0/hr, exactly like VendorCreate::store()', function () {
    ['vendor' => $company, 'user' => $admin] = appFieldAdmin();
    Sanctum::actingAs($admin, ['app']);

    $response = $this->postJson('/api/app/v1/vendors', [
        'business_name' => 'Acme Drywall',
        'business_type' => 'Sub',
        'address' => '1 Elm St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
        'business_phone' => '3125559876', 'business_email' => 'office@acme.test', 'business_website' => 'https://acme.test',
        'owner_cell_phone' => '3125551234', 'owner_first_name' => 'Jane', 'owner_last_name' => 'Owner', 'owner_email' => 'jane@example.com',
    ])->assertCreated();

    $vendorId = $response->json('data.id');
    $vendor = Vendor::withoutGlobalScopes()->findOrFail($vendorId);

    expect($vendor->business_name)->toBe('Acme Drywall')
        ->and($vendor->address)->toBe('1 Elm St')
        ->and($vendor->timezone)->toBe('America/Chicago')
        ->and($company->vendors()->where('vendors.id', $vendor->id)->exists())->toBeTrue();

    $owner = User::where('cell_phone', '3125551234')->first();
    expect($owner)->not->toBeNull()
        ->and($owner->first_name)->toBe('Jane');

    // Raw pivot lookup — $owner->vendors() goes through Vendor's own
    // VendorScope global scope, which scopes to the currently AUTHENTICATED
    // user's company tree, not $owner's.
    $pivot = \Illuminate\Support\Facades\DB::table('user_vendor')->where('user_id', $owner->id)->where('vendor_id', $vendor->id)->first();
    expect($pivot)->not->toBeNull()
        ->and((int) $pivot->role_id)->toBe(1)
        ->and((float) $pivot->hourly_rate)->toBe(0.0);
});

it('creates a Retail vendor without requiring an owner', function () {
    ['user' => $admin] = appFieldAdmin();
    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/vendors', [
        'business_name' => 'Home Depot', 'business_type' => 'Retail',
    ])->assertCreated()
        ->assertJsonPath('data.business_type', 'Retail');
});

it('reuses an existing person by phone as the new vendor\'s owner, instead of creating a duplicate', function () {
    ['vendor' => $company, 'user' => $admin] = appFieldAdmin();
    $existingPerson = User::factory()->create(['cell_phone' => '3125551234', 'first_name' => 'Established']);
    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/vendors', [
        'business_name' => 'Acme Drywall', 'business_type' => 'Sub',
        'owner_cell_phone' => '3125551234', 'owner_first_name' => 'Ignored', 'owner_last_name' => 'Ignored', 'owner_email' => 'ignored@example.com',
    ])->assertCreated();

    expect(User::where('cell_phone', '3125551234')->count())->toBe(1)
        ->and($existingPerson->refresh()->first_name)->toBe('Established');
});

it('requires an owner phone for a non-Retail vendor', function () {
    ['user' => $admin] = appFieldAdmin();
    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/vendors', ['business_name' => 'Acme Drywall', 'business_type' => 'Sub'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('owner_cell_phone');
});

it('updates a vendor\'s details but never its business_type, like the web\'s disabled radio on edit', function () {
    ['vendor' => $company, 'user' => $admin] = appFieldAdmin();
    Sanctum::actingAs($admin, ['app']);

    $sub = Vendor::factory()->create(['business_name' => 'Acme Drywall', 'business_type' => 'Sub']);
    appFieldAttach($company, $sub);

    $this->putJson('/api/app/v1/vendors/'.$sub->id, [
        'business_name' => 'Acme Drywall LLC',
        'address' => '2 Oak St', 'city' => 'Evanston', 'state' => 'IL', 'zip_code' => '60201',
        'business_phone' => '3125550000', 'business_email' => 'new@acme.test', 'business_website' => 'https://acme.test',
    ])->assertOk()
        // Vendor::businessName()'s own mutator title-cases every save, web
        // or app alike — "LLC" becomes "Llc", same as it always has.
        ->assertJsonPath('data.business_name', 'Acme Drywall Llc')
        ->assertJsonPath('data.business_type', 'Sub');

    expect($sub->refresh()->business_type)->toBe('Sub')
        ->and($sub->address)->toBe('2 Oak St')
        ->and($sub->timezone)->toBe('America/Chicago');
});

it('403s editing a Retail vendor, an unconnected vendor, and refuses a Member outright', function () {
    ['vendor' => $company, 'user' => $admin] = appFieldAdmin();
    $member = appFieldMember($company);

    $retail = Vendor::factory()->create(['business_type' => 'Retail']);
    appFieldAttach($company, $retail);

    $unconnected = Vendor::factory()->create(['business_type' => 'Sub']);

    Sanctum::actingAs($admin, ['app']);
    // isEditableBy() returns false outright for Retail — VendorPolicy::update's
    // own business rule, same gate the web's YTD-paid figure and the
    // vendor-docs card use.
    $this->putJson('/api/app/v1/vendors/'.$retail->id, ['business_name' => 'Nope'])->assertForbidden();
    // Not even in VendorScope's visible tree — 403 at the view step, same
    // as the read tests above.
    $this->putJson('/api/app/v1/vendors/'.$unconnected->id, ['business_name' => 'Nope'])->assertForbidden();

    auth()->forgetGuards();
    Sanctum::actingAs($member, ['app']);
    $privateSub = Vendor::factory()->create(['business_type' => 'Sub']);
    appFieldAttach($company, $privateSub);
    // A Member never has VendorPolicy::update, regardless of the vendor.
    $this->putJson('/api/app/v1/vendors/'.$privateSub->id, ['business_name' => 'Nope'])->assertForbidden();
});

it('finds existing-and-linkable vendors by business name, like VendorCreate::updatedBusinessNameText()', function () {
    ['vendor' => $company, 'user' => $admin] = appFieldAdmin();
    Sanctum::actingAs($admin, ['app']);

    $linked = Vendor::factory()->create(['business_name' => 'Acme Drywall', 'business_type' => 'Sub']);
    appFieldAttach($company, $linked);
    $unlinked = Vendor::factory()->create(['business_name' => 'Acme Roofing', 'business_type' => 'Sub']);

    $response = $this->getJson('/api/app/v1/vendors/search?name=Acme')->assertOk();

    expect(collect($response->json('data.existing'))->pluck('id')->all())->toBe([$linked->id])
        ->and(collect($response->json('data.linkable'))->pluck('id')->all())->toBe([$unlinked->id]);
});

it('links an existing unlinked vendor to the company, like VendorCreate::addVendorToCompany()', function () {
    ['vendor' => $company, 'user' => $admin] = appFieldAdmin();
    Sanctum::actingAs($admin, ['app']);

    $unlinked = Vendor::factory()->create(['business_type' => 'Sub']);

    $this->postJson('/api/app/v1/vendors/'.$unlinked->id.'/link')
        ->assertOk()
        ->assertJsonPath('data.id', $unlinked->id);

    expect($company->vendors()->where('vendors.id', $unlinked->id)->exists())->toBeTrue();
});

it('refuses to link another tenant\'s own registered vendor', function () {
    ['user' => $admin] = appFieldAdmin();
    Sanctum::actingAs($admin, ['app']);

    $registered = Vendor::factory()->create(['business_type' => 'Sub', 'registration' => ['registered' => true]]);

    $this->postJson('/api/app/v1/vendors/'.$registered->id.'/link')->assertForbidden();
});

it('looks up an existing person by phone for the owner step, like UserCreate::user_cell_find()', function () {
    ['user' => $admin] = appFieldAdmin();
    $existing = User::factory()->create(['cell_phone' => '3125551234', 'first_name' => 'Jane']);
    Sanctum::actingAs($admin, ['app']);

    $this->getJson('/api/app/v1/vendors/owner-lookup?cell_phone=3125551234')
        ->assertOk()
        ->assertJsonPath('data.id', $existing->id)
        ->assertJsonPath('data.first_name', 'Jane');

    $this->getJson('/api/app/v1/vendors/owner-lookup?cell_phone=3125559999')
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('summarizes a Retail vendor\'s YTD expenses, scoped to the company\'s own spend', function () {
    ['vendor' => $company, 'user' => $admin] = appFieldAdmin();
    Sanctum::actingAs($admin, ['app']);

    $retail = Vendor::factory()->create(['business_name' => 'Home Depot', 'business_type' => 'Retail']);
    appFieldAttach($company, $retail);

    Expense::withoutEvents(fn () => Expense::create([
        'amount' => 60, 'date' => now()->format('Y-m-d'), 'vendor_id' => $retail->id,
        'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => $admin->id,
    ]));
    Expense::withoutEvents(fn () => Expense::create([
        'amount' => 40, 'date' => now()->subYears(2)->format('Y-m-d'), 'vendor_id' => $retail->id,
        'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => $admin->id,
    ]));

    // A different tenant's spend at the same shared retailer must never leak in.
    $otherCompany = Vendor::factory()->create(['business_type' => 'Sub']);
    Expense::withoutEvents(fn () => Expense::create([
        'amount' => 999, 'date' => now()->format('Y-m-d'), 'vendor_id' => $retail->id,
        'belongs_to_vendor_id' => $otherCompany->id, 'created_by_user_id' => $admin->id,
    ]));

    $response = $this->getJson('/api/app/v1/vendors/'.$retail->id)->assertOk();

    // ytd_total only counts the past year; "recent" is the last 15 rows
    // regardless of date (same "recent activity" idea as payments()).
    $recentAmounts = collect($response->json('data.expense_summary.recent'))->pluck('amount')->map(fn ($a) => (float) $a)->all();
    expect((float) $response->json('data.expense_summary.ytd_total'))->toBe(60.0)
        ->and($recentAmounts)->toBe([60.0, 40.0]);
});

it('tracks Vendor Payment emails on the index, Admin-only, attributed by the sent event\'s vendor_id', function () {
    ['vendor' => $company, 'user' => $admin] = appFieldAdmin();
    $member = appFieldMember($company);
    $paidVendor = Vendor::factory()->create(['business_name' => 'Acme Supply']);

    \App\Models\EmailTracking::create([
        'belongs_to_vendor_id' => $company->id,
        'message_id' => 'msg-1',
        'thread_id' => 'thread-1',
        'email_template_name' => 'Vendor Payment',
        'event_type' => 'sent',
        'recipient_emails' => ['acme@example.test'],
        'metadata' => ['vendor_id' => $paidVendor->id],
        'event_at' => now()->subHours(3),
    ]);
    \App\Models\EmailTracking::create([
        'belongs_to_vendor_id' => $company->id,
        'message_id' => 'msg-1-open',
        'thread_id' => 'thread-1',
        'email_template_name' => 'Vendor Payment',
        'event_type' => 'opened',
        'recipient_emails' => ['acme@example.test'],
        'event_at' => now()->subHours(1),
    ]);
    // A different template never shows on this card.
    \App\Models\EmailTracking::create([
        'belongs_to_vendor_id' => $company->id,
        'message_id' => 'msg-2',
        'email_template_name' => 'Lien Waiver Signing Request',
        'event_type' => 'sent',
        'recipient_emails' => ['acme@example.test'],
        'event_at' => now(),
    ]);

    Sanctum::actingAs($member, ['app']);
    $this->getJson('/api/app/v1/vendors/payment-email-tracking')->assertForbidden();

    Sanctum::actingAs($admin, ['app']);
    $response = $this->getJson('/api/app/v1/vendors/payment-email-tracking')->assertOk();

    $rows = $response->json('data');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['event_type'])->toBe('opened')
        ->and($rows[0]['vendor_id'])->toBe($paidVendor->id)
        ->and($rows[0]['vendor_name'])->toBe('Acme Supply')
        ->and($rows[0]['event_count'])->toBe(2);
});

it('scopes Lien Waiver Signing Request email tracking to one vendor', function () {
    ['vendor' => $company, 'user' => $admin] = appFieldAdmin();
    $vendorA = Vendor::factory()->create(['business_name' => 'Vendor A']);
    $vendorB = Vendor::factory()->create(['business_name' => 'Vendor B']);
    appFieldAttach($company, $vendorA);
    appFieldAttach($company, $vendorB);

    \App\Models\EmailTracking::create([
        'belongs_to_vendor_id' => $company->id,
        'message_id' => 'lw-a',
        'email_template_name' => 'Lien Waiver Signing Request',
        'event_type' => 'sent',
        'recipient_emails' => ['a@example.test'],
        'metadata' => ['vendor_id' => $vendorA->id],
        'event_at' => now(),
    ]);
    \App\Models\EmailTracking::create([
        'belongs_to_vendor_id' => $company->id,
        'message_id' => 'lw-b',
        'email_template_name' => 'Lien Waiver Signing Request',
        'event_type' => 'sent',
        'recipient_emails' => ['b@example.test'],
        'metadata' => ['vendor_id' => $vendorB->id],
        'event_at' => now(),
    ]);

    Sanctum::actingAs($admin, ['app']);
    $response = $this->getJson('/api/app/v1/vendors/'.$vendorA->id.'/lien-waiver-email-tracking')->assertOk();

    $rows = $response->json('data');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['vendor_id'])->toBe($vendorA->id);
});
