<?php

use App\Models\Expense;
use App\Models\Hour;
use App\Models\Timesheet;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/** @return array{vendor: Vendor, admin: User} */
function appFieldTimesheetsAdmin(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction', 'business_type' => 'Sub']);
    $admin = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $admin->vendors()->attach($vendor->id, ['role_id' => 1, 'is_employed' => 1]);

    return compact('vendor', 'admin');
}

function appFieldTimesheetsMember(Vendor $vendor): User
{
    $member = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $member->vendors()->attach($vendor->id, ['role_id' => 2, 'is_employed' => 1]);

    return $member;
}

function appFieldTimesheetFixture(Vendor $vendor, User $user, array $overrides = []): Timesheet
{
    return Timesheet::forceCreate(array_merge([
        'date' => now()->startOfWeek()->format('Y-m-d'),
        'user_id' => $user->id,
        'vendor_id' => $vendor->id,
        'hours' => 10,
        'amount' => 500,
        'hourly' => 50,
        'created_by_user_id' => $user->id,
    ], $overrides));
}

it('needs a token to list timesheets', function () {
    $this->getJson('/api/app/v1/timesheets')->assertUnauthorized();
});

it('shows a Member only their own confirmed timesheets, and an Admin everyone\'s', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appFieldTimesheetsAdmin();
    $member = appFieldTimesheetsMember($vendor);

    appFieldTimesheetFixture($vendor, $member, ['date' => '2026-05-11']);
    appFieldTimesheetFixture($vendor, $admin, ['date' => '2026-05-18']);

    Sanctum::actingAs($member, ['app']);
    $asMember = $this->getJson('/api/app/v1/timesheets')->assertOk();
    expect(collect($asMember->json('data'))->pluck('member_name')->unique()->all())
        ->toBe([trim($member->first_name.' '.$member->last_name)]);

    auth()->forgetGuards();
    Sanctum::actingAs($admin, ['app']);
    $asAdmin = $this->getJson('/api/app/v1/timesheets')->assertOk();
    expect(count($asAdmin->json('data')))->toBe(2);
});

it('filters the list to one member via user_id, like the web\'s employee select', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appFieldTimesheetsAdmin();
    $member = appFieldTimesheetsMember($vendor);

    appFieldTimesheetFixture($vendor, $member, ['date' => '2026-05-11']);
    appFieldTimesheetFixture($vendor, $admin, ['date' => '2026-05-18']);

    Sanctum::actingAs($admin, ['app']);
    $response = $this->getJson('/api/app/v1/timesheets?user_id='.$member->id)->assertOk();

    expect(collect($response->json('data'))->pluck('member_name')->unique()->all())
        ->toBe([trim($member->first_name.' '.$member->last_name)]);
});

it('flags an unpaid week "Pay" and a paid week "Paid"', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appFieldTimesheetsAdmin();
    Sanctum::actingAs($admin, ['app']);

    appFieldTimesheetFixture($vendor, $admin, ['date' => '2026-05-11', 'check_id' => null]);
    appFieldTimesheetFixture($vendor, $admin, ['date' => '2026-05-18', 'check_id' => 9999]);

    $response = $this->getJson('/api/app/v1/timesheets')->assertOk();
    $byDate = collect($response->json('data'))->keyBy('date');

    expect($byDate['2026-05-11']['status']['label'])->toBe('Pay')
        ->and($byDate['2026-05-18']['status']['label'])->toBe('Paid');
});

it('links an Admin\'s "Pay" status to the payment form, and "Paid" to the check', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appFieldTimesheetsAdmin();
    Sanctum::actingAs($admin, ['app']);

    appFieldTimesheetFixture($vendor, $admin, ['date' => '2026-05-11', 'check_id' => null]);
    appFieldTimesheetFixture($vendor, $admin, ['date' => '2026-05-18', 'check_id' => 9999]);

    $response = $this->getJson('/api/app/v1/timesheets')->assertOk();
    $byDate = collect($response->json('data'))->keyBy('date');

    expect($byDate['2026-05-11']['status']['link'])->toBe(['type' => 'payment', 'id' => $admin->id])
        ->and($byDate['2026-05-18']['status']['link'])->toBe(['type' => 'check', 'id' => 9999]);
});

it('never links a Member\'s "Pay" status (list-row payment stays Admin-only)', function () {
    ['vendor' => $vendor] = appFieldTimesheetsAdmin();
    $member = appFieldTimesheetsMember($vendor);
    Sanctum::actingAs($member, ['app']);

    appFieldTimesheetFixture($vendor, $member, ['date' => '2026-05-11', 'check_id' => null]);

    $response = $this->getJson('/api/app/v1/timesheets')->assertOk();

    expect($response->json('data.0.status.link'))->toBeNull();
});

it('links a multi-project week\'s "Pay" to the payment form too', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appFieldTimesheetsAdmin();
    Sanctum::actingAs($admin, ['app']);

    $client = \App\Models\Client::factory()->create();
    $projectA = \App\Models\Project::withoutEvents(fn () => \App\Models\Project::create([
        'project_name' => 'Job A', 'client_id' => $client->id,
        'address' => '1 A St', 'city' => 'Northbrook', 'state' => 'IL', 'zip_code' => '60062',
        'belongs_to_vendor_id' => $vendor->id,
    ]));
    $projectB = \App\Models\Project::withoutEvents(fn () => \App\Models\Project::create([
        'project_name' => 'Job B', 'client_id' => $client->id,
        'address' => '2 B St', 'city' => 'Northbrook', 'state' => 'IL', 'zip_code' => '60062',
        'belongs_to_vendor_id' => $vendor->id,
    ]));

    appFieldTimesheetFixture($vendor, $admin, ['date' => '2026-05-11', 'check_id' => null, 'project_id' => $projectA->id]);
    appFieldTimesheetFixture($vendor, $admin, ['date' => '2026-05-11', 'check_id' => null, 'project_id' => $projectB->id]);

    $response = $this->getJson('/api/app/v1/timesheets')->assertOk();

    expect($response->json('data.0.status.label'))->toBe('Pay')
        ->and($response->json('data.0.status.link'))->toBe(['type' => 'payment', 'id' => $admin->id]);
});

it('filters the list by paid status', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appFieldTimesheetsAdmin();
    Sanctum::actingAs($admin, ['app']);

    appFieldTimesheetFixture($vendor, $admin, ['date' => '2026-05-11', 'check_id' => null]);
    appFieldTimesheetFixture($vendor, $admin, ['date' => '2026-05-18', 'check_id' => 9999]);

    $unpaid = $this->getJson('/api/app/v1/timesheets?status=unpaid')->assertOk();
    expect(collect($unpaid->json('data'))->pluck('date')->all())->toBe(['2026-05-11']);

    $confirmed = $this->getJson('/api/app/v1/timesheets?status=confirmed')->assertOk();
    expect(collect($confirmed->json('data'))->pluck('date')->all())->toBe(['2026-05-18']);
});

it('shows a week\'s detail with its per-project rows and daily breakdown', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appFieldTimesheetsAdmin();
    Sanctum::actingAs($admin, ['app']);

    $client = \App\Models\Client::factory()->create();
    $project = \App\Models\Project::withoutEvents(fn () => \App\Models\Project::create([
        'project_name' => 'Kitchen Remodel', 'client_id' => $client->id,
        'address' => '123 Main St', 'city' => 'Northbrook', 'state' => 'IL', 'zip_code' => '60062',
        'belongs_to_vendor_id' => $vendor->id,
    ]));

    $timesheet = appFieldTimesheetFixture($vendor, $admin, ['date' => '2026-05-11', 'project_id' => $project->id]);
    Hour::forceCreate([
        'date' => '2026-05-11', 'user_id' => $admin->id, 'vendor_id' => $vendor->id, 'project_id' => $project->id,
        'hours' => 10, 'timesheet_id' => $timesheet->id, 'created_by_user_id' => $admin->id,
    ]);

    $response = $this->getJson('/api/app/v1/timesheets/'.$timesheet->id)->assertOk();

    $response->assertJsonPath('data.week.date', '2026-05-11')
        ->assertJsonCount(1, 'data.rows')
        ->assertJsonPath('data.rows.0.status.label', 'Pay')
        ->assertJsonPath('data.rows.0.check_id', null)
        ->assertJsonCount(1, 'data.days')
        ->assertJsonPath('data.days.0.date', '2026-05-11');
});

it('carries the paying check\'s id on a paid row, like the web\'s Amount/Payment links', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appFieldTimesheetsAdmin();
    Sanctum::actingAs($admin, ['app']);

    $timesheet = appFieldTimesheetFixture($vendor, $admin, ['date' => '2026-05-11', 'check_id' => 9999]);

    $this->getJson('/api/app/v1/timesheets/'.$timesheet->id)
        ->assertOk()
        ->assertJsonPath('data.rows.0.check_id', 9999);
});

it('shows "Not Paid" instead of "Pay" to a Member viewing their own unpaid week', function () {
    ['vendor' => $vendor] = appFieldTimesheetsAdmin();
    $member = appFieldTimesheetsMember($vendor);
    Sanctum::actingAs($member, ['app']);

    $timesheet = appFieldTimesheetFixture($vendor, $member, ['date' => '2026-05-11']);

    $this->getJson('/api/app/v1/timesheets/'.$timesheet->id)
        ->assertOk()
        ->assertJsonPath('data.rows.0.status.label', 'Not Paid');
});

it('403s a timesheet belonging to another member, for a Member', function () {
    ['vendor' => $vendor] = appFieldTimesheetsAdmin();
    $memberA = appFieldTimesheetsMember($vendor);
    $memberB = appFieldTimesheetsMember($vendor);
    $timesheet = appFieldTimesheetFixture($vendor, $memberB);

    Sanctum::actingAs($memberA, ['app']);
    $this->getJson('/api/app/v1/timesheets/'.$timesheet->id)->assertForbidden();

    $this->getJson('/api/app/v1/timesheets/999999999')->assertNotFound();
});

it('computes the signed-in member\'s own outstanding balance', function () {
    ['vendor' => $vendor] = appFieldTimesheetsAdmin();
    $member = appFieldTimesheetsMember($vendor);
    Sanctum::actingAs($member, ['app']);

    appFieldTimesheetFixture($vendor, $member, ['date' => '2026-05-11', 'amount' => 300]);

    Expense::create([
        'date' => '2026-05-12', 'amount' => 50, 'paid_by' => $member->id,
        'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $member->id,
    ]);

    Expense::create([
        'date' => '2026-05-13', 'amount' => 20, 'reimbursment' => (string) $member->id,
        'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $member->id,
    ]);

    $response = $this->getJson('/api/app/v1/timesheets/balance')->assertOk();

    expect((float) $response->json('data.total'))->toBe(330.0)
        ->and($response->json('data.weekly_timesheets'))->toHaveCount(1)
        ->and($response->json('data.paid_expenses'))->toHaveCount(1)
        ->and($response->json('data.reimbursement_expenses'))->toHaveCount(1);
});

it('refuses balance and payable views to a 1099 vendor, even for one\'s own page', function () {
    ['vendor' => $vendor] = appFieldTimesheetsAdmin();
    $vendor->forceFill(['business_type' => '1099'])->save();
    $member = appFieldTimesheetsMember($vendor);
    Sanctum::actingAs($member, ['app']);

    $this->getJson('/api/app/v1/timesheets/balance')->assertForbidden();
});

it('sorts the list by hours or amount, like the web\'s sortable columns', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appFieldTimesheetsAdmin();
    Sanctum::actingAs($admin, ['app']);

    appFieldTimesheetFixture($vendor, $admin, ['date' => '2026-05-11', 'hours' => 5, 'amount' => 900]);
    appFieldTimesheetFixture($vendor, $admin, ['date' => '2026-05-18', 'hours' => 20, 'amount' => 100]);

    $byHours = $this->getJson('/api/app/v1/timesheets?sort=hours&direction=desc')->assertOk();
    expect(collect($byHours->json('data'))->pluck('date')->all())->toBe(['2026-05-18', '2026-05-11']);

    $byAmount = $this->getJson('/api/app/v1/timesheets?sort=amount&direction=asc')->assertOk();
    expect(collect($byAmount->json('data'))->pluck('date')->all())->toBe(['2026-05-18', '2026-05-11']);
});

it('searches the list by member name when Meilisearch is unavailable', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appFieldTimesheetsAdmin();
    $alice = User::factory()->create(['primary_vendor_id' => $vendor->id, 'first_name' => 'Alice', 'last_name' => 'Anders']);
    $alice->vendors()->attach($vendor->id, ['role_id' => 2, 'is_employed' => 1]);
    Sanctum::actingAs($admin, ['app']);

    appFieldTimesheetFixture($vendor, $alice, ['date' => '2026-05-11']);
    appFieldTimesheetFixture($vendor, $admin, ['date' => '2026-05-18']);

    $this->mock(\Laravel\Scout\EngineManager::class, function ($mock) {
        $mock->shouldReceive('engine')->andThrow(new \RuntimeException('no search engine in tests'));
    });

    $response = $this->getJson('/api/app/v1/timesheets?search=Alice')->assertOk();
    expect(collect($response->json('data'))->pluck('date')->all())->toBe(['2026-05-11']);
});

it('filters the list by date range, like the web\'s date_range filter, needing both ends', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appFieldTimesheetsAdmin();
    Sanctum::actingAs($admin, ['app']);

    appFieldTimesheetFixture($vendor, $admin, ['date' => '2026-05-11']);
    appFieldTimesheetFixture($vendor, $admin, ['date' => '2026-06-15']);
    appFieldTimesheetFixture($vendor, $admin, ['date' => '2026-07-20']);

    $response = $this->getJson('/api/app/v1/timesheets?start_date=2026-05-01&end_date=2026-06-30')->assertOk();
    expect(collect($response->json('data'))->pluck('date')->all())->toBe(['2026-06-15', '2026-05-11']);

    // A lone end without a start is ignored rather than half-applied.
    $unfiltered = $this->getJson('/api/app/v1/timesheets?end_date=2026-06-30')->assertOk();
    expect(collect($unfiltered->json('data')))->toHaveCount(3);
});

it('includes the week\'s not_paid flag on the detail screen', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appFieldTimesheetsAdmin();
    Sanctum::actingAs($admin, ['app']);

    $unpaid = appFieldTimesheetFixture($vendor, $admin, ['date' => '2026-05-11', 'check_id' => null]);
    $this->getJson('/api/app/v1/timesheets/'.$unpaid->id)->assertOk()->assertJsonPath('data.week.not_paid', true);

    $paid = appFieldTimesheetFixture($vendor, $admin, ['date' => '2026-05-18', 'check_id' => 9999]);
    $this->getJson('/api/app/v1/timesheets/'.$paid->id)->assertOk()->assertJsonPath('data.week.not_paid', false);
});

it('reverts an unpaid timesheet, unconfirming its hours, exactly like the web', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appFieldTimesheetsAdmin();
    Sanctum::actingAs($admin, ['app']);

    $client = \App\Models\Client::factory()->create();
    $project = \App\Models\Project::withoutEvents(fn () => \App\Models\Project::create([
        'project_name' => 'Kitchen Remodel', 'client_id' => $client->id,
        'address' => '123 Main St', 'city' => 'Northbrook', 'state' => 'IL', 'zip_code' => '60062',
        'belongs_to_vendor_id' => $vendor->id,
    ]));

    $timesheet = appFieldTimesheetFixture($vendor, $admin, ['date' => '2026-05-11', 'check_id' => null, 'project_id' => $project->id]);
    $hour = Hour::forceCreate([
        'date' => '2026-05-11', 'user_id' => $admin->id, 'vendor_id' => $vendor->id, 'project_id' => $project->id,
        'hours' => 10, 'timesheet_id' => $timesheet->id, 'created_by_user_id' => $admin->id,
    ]);

    $this->postJson('/api/app/v1/timesheets/'.$timesheet->id.'/revert')
        ->assertOk()
        ->assertJsonPath('data.reverted', true);

    // Timesheet uses SoftDeletes — delete() leaves the row with deleted_at
    // set, same as the web's $timesheet->delete() — so withTrashed() is
    // needed to see it's actually gone from the normal query.
    expect(Timesheet::query()->whereKey($timesheet->id)->exists())->toBeFalse()
        ->and(Timesheet::withTrashed()->whereKey($timesheet->id)->first()?->trashed())->toBeTrue()
        ->and($hour->refresh()->timesheet_id)->toBeNull();
});

it('refuses to revert an already-paid timesheet', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appFieldTimesheetsAdmin();
    Sanctum::actingAs($admin, ['app']);

    $timesheet = appFieldTimesheetFixture($vendor, $admin, ['date' => '2026-05-11', 'check_id' => 9999]);

    $this->postJson('/api/app/v1/timesheets/'.$timesheet->id.'/revert')->assertForbidden();
    expect(Timesheet::withoutGlobalScopes()->whereKey($timesheet->id)->exists())->toBeTrue();
});

it('refuses a Member reverting their own unpaid timesheet (Admin-only, same as the web\'s render() gate)', function () {
    ['vendor' => $vendor] = appFieldTimesheetsAdmin();
    $member = appFieldTimesheetsMember($vendor);
    Sanctum::actingAs($member, ['app']);

    $timesheet = appFieldTimesheetFixture($vendor, $member, ['date' => '2026-05-11', 'check_id' => null]);

    $this->postJson('/api/app/v1/timesheets/'.$timesheet->id.'/revert')->assertForbidden();
    expect(Timesheet::withoutGlobalScopes()->whereKey($timesheet->id)->exists())->toBeTrue();
});

it('shows the payable list only to an Admin', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appFieldTimesheetsAdmin();
    $member = appFieldTimesheetsMember($vendor);

    appFieldTimesheetFixture($vendor, $member, ['date' => '2026-05-11', 'amount' => 400]);

    Sanctum::actingAs($admin, ['app']);
    $response = $this->getJson('/api/app/v1/timesheets/payable')->assertOk();
    $row = collect($response->json('data'))->firstWhere('user.id', $member->id);
    expect((float) $row['total'])->toBe(400.0);

    auth()->forgetGuards();
    Sanctum::actingAs($member, ['app']);
    $this->getJson('/api/app/v1/timesheets/payable')->assertForbidden();
});

it('lists every member with timesheet/hour history for the filter, including one no longer on the team', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appFieldTimesheetsAdmin();
    $member = appFieldTimesheetsMember($vendor);

    // No longer attached to the vendor at all (left the company) — still
    // has a confirmed timesheet row, so TimesheetsIndex::mount() (and this
    // mirror of it) must still offer them in the filter.
    $former = User::factory()->create(['first_name' => 'Former', 'last_name' => 'Employee']);
    appFieldTimesheetFixture($vendor, $former, ['date' => '2026-04-06']);
    appFieldTimesheetFixture($vendor, $member, ['date' => '2026-05-11']);

    // Someone with an unconfirmed Hour row but no Timesheet yet also counts.
    $hourOnly = User::factory()->create(['first_name' => 'Hour', 'last_name' => 'Only']);
    $client = \App\Models\Client::factory()->create();
    $project = \App\Models\Project::withoutEvents(fn () => \App\Models\Project::create([
        'project_name' => 'Hour Only Job', 'client_id' => $client->id, 'address' => '1 Elm St',
        'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601', 'belongs_to_vendor_id' => $vendor->id,
    ]));
    Hour::forceCreate([
        'date' => now()->format('Y-m-d'),
        'user_id' => $hourOnly->id,
        'vendor_id' => $vendor->id,
        'project_id' => $project->id,
        'hours' => 8,
        'timesheet_id' => null,
        'created_by_user_id' => $admin->id,
    ]);

    Sanctum::actingAs($admin, ['app']);
    $response = $this->getJson('/api/app/v1/timesheets/employees')->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($former->id)
        ->and($ids)->toContain($member->id)
        ->and($ids)->toContain($hourOnly->id);
});

it('needs the viewAny ability to list timesheet employees', function () {
    $this->getJson('/api/app/v1/timesheets/employees')->assertUnauthorized();
});
