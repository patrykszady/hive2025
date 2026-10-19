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
        ->assertJsonCount(1, 'data.days')
        ->assertJsonPath('data.days.0.date', '2026-05-11');
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
