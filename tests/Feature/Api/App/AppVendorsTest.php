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

it('includes a vendor\'s upcoming tasks on the detail screen', function () {
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

    Task::factory()->create([
        'project_id' => $project->id,
        'vendor_id' => $sub->id,
        'belongs_to_vendor_id' => $company->id,
        'start_date' => now()->addDay()->format('Y-m-d'),
        'end_date' => now()->addDay()->format('Y-m-d'),
    ]);

    $this->getJson('/api/app/v1/vendors/'.$sub->id)
        ->assertOk()
        ->assertJsonCount(1, 'data.upcoming_tasks')
        ->assertJsonPath('data.upcoming_tasks.0.project.id', $project->id);
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
