<?php

use App\Livewire\Timesheets\TimesheetCreate;
use App\Livewire\Timesheets\TimesheetPaymentCreate;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Hour;
use App\Models\Project;
use App\Models\Timesheet;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
    Queue::fake();
});

/** @return array{vendor: Vendor, admin: User} */
function appPayrollAdmin(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction', 'business_type' => 'Sub']);
    $admin = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $admin->vendors()->attach($vendor->id, ['role_id' => 1, 'is_employed' => 1]);

    return compact('vendor', 'admin');
}

function appPayrollMember(Vendor $vendor): User
{
    $member = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $member->vendors()->attach($vendor->id, ['role_id' => 2, 'is_employed' => 1]);

    return $member;
}

function appPayrollProject(Vendor $vendor): Project
{
    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Kitchen Remodel',
        'client_id' => $client->id,
        'address' => '123 Main St',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => '60062',
        'belongs_to_vendor_id' => $vendor->id,
    ]));

    // ProjectScope requires the vendor<->project pivot (whereHas('vendors')),
    // not just belongs_to_vendor_id — without this a project is invisible to
    // every scoped query, including the web blade's project->name lookups.
    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    return $project;
}

function appPayrollHour(Vendor $vendor, User $user, Project $project, string $date, float $hours): Hour
{
    return Hour::forceCreate([
        'date' => $date,
        'hours' => $hours,
        'project_id' => $project->id,
        'user_id' => $user->id,
        'vendor_id' => $vendor->id,
        'created_by_user_id' => $user->id,
    ]);
}

/** @return array{bank: Bank, account: BankAccount} */
function appPayrollBankAccount(Vendor $vendor): array
{
    $bank = Bank::query()->create([
        'name' => 'Test Payroll Bank',
        'vendor_id' => $vendor->id,
        'plaid_ins_id' => 'ins_payroll_test',
        'plaid_access_token' => 'access-test-token',
    ]);
    $account = BankAccount::query()->create([
        'bank_id' => $bank->id,
        'vendor_id' => $vendor->id,
        'account_number' => 4321,
        'plaid_account_id' => 'acct_payroll_test',
        'type' => 'Checking',
    ]);

    return compact('bank', 'account');
}

// --- Auth required -----------------------------------------------------

it('needs a token for every payroll route', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appPayrollAdmin();
    $project = appPayrollProject($vendor);
    $hour = appPayrollHour($vendor, $admin, $project, '2026-05-11', 8);

    $this->getJson("/api/app/v1/timesheets/create/{$hour->id}")->assertUnauthorized();
    $this->postJson("/api/app/v1/timesheets/create/{$hour->id}", ['hourly' => 20])->assertUnauthorized();
    $this->getJson("/api/app/v1/timesheets/payment/{$admin->id}")->assertUnauthorized();
    $this->postJson("/api/app/v1/timesheets/payment/{$admin->id}", [])->assertUnauthorized();
});

// --- Confirming a week's hours into a Timesheet -------------------------

it('lets any team member see their own unconfirmed week and confirm it', function () {
    ['vendor' => $vendor] = appPayrollAdmin();
    $member = appPayrollMember($vendor);
    $project = appPayrollProject($vendor);
    appPayrollHour($vendor, $member, $project, '2026-05-11', 8);
    appPayrollHour($vendor, $member, $project, '2026-05-12', 6);

    Sanctum::actingAs($member, ['app']);

    $show = $this->getJson('/api/app/v1/timesheets/create/'.Hour::where('user_id', $member->id)->first()->id)->assertOk();
    expect((float) $show->json('data.hours'))->toBe(14.0)
        ->and($show->json('data.user.id'))->toBe($member->id)
        ->and($show->json('data.days'))->toHaveCount(2);

    $hourId = Hour::where('user_id', $member->id)->first()->id;
    $response = $this->postJson("/api/app/v1/timesheets/create/{$hourId}", ['hourly' => 25])->assertCreated();

    $timesheet = Timesheet::find($response->json('data.timesheet_id'));
    expect($timesheet)->not->toBeNull()
        ->and((float) $timesheet->hours)->toBe(14.0)
        ->and((float) $timesheet->hourly)->toBe(25.0)
        ->and((float) $timesheet->amount)->toBe(350.0);

    expect(Hour::where('user_id', $member->id)->whereNull('timesheet_id')->count())->toBe(0);

    $member->refresh();
    expect((float) $member->vendors()->where('vendors.id', $vendor->id)->first()->pivot->hourly_rate)->toBe(25.0);
});

it('creates one timesheet per project worked that week', function () {
    ['vendor' => $vendor] = appPayrollAdmin();
    $member = appPayrollMember($vendor);
    $projectA = appPayrollProject($vendor);
    $projectB = appPayrollProject($vendor);
    appPayrollHour($vendor, $member, $projectA, '2026-05-11', 5);
    appPayrollHour($vendor, $member, $projectB, '2026-05-12', 3);

    Sanctum::actingAs($member, ['app']);
    $hourId = Hour::where('project_id', $projectA->id)->first()->id;

    $this->postJson("/api/app/v1/timesheets/create/{$hourId}", ['hourly' => 10])->assertCreated();

    expect(Timesheet::where('user_id', $member->id)->count())->toBe(2)
        ->and((float) Timesheet::where('user_id', $member->id)->sum('amount'))->toBe(80.0);
});

it('404s confirming a week that is not visible to the acting member (HourScope), but an admin may confirm anyone\'s', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appPayrollAdmin();
    $memberA = appPayrollMember($vendor);
    $memberB = appPayrollMember($vendor);
    $project = appPayrollProject($vendor);
    $hour = appPayrollHour($vendor, $memberB, $project, '2026-05-11', 8);

    Sanctum::actingAs($memberA, ['app']);
    $this->getJson("/api/app/v1/timesheets/create/{$hour->id}")->assertNotFound();
    $this->postJson("/api/app/v1/timesheets/create/{$hour->id}", ['hourly' => 20])->assertNotFound();

    auth()->forgetGuards();
    Sanctum::actingAs($admin, ['app']);
    $this->getJson("/api/app/v1/timesheets/create/{$hour->id}")->assertOk();
    $this->postJson("/api/app/v1/timesheets/create/{$hour->id}", ['hourly' => 20])->assertCreated();
});

it('409s confirming a week that has already been confirmed', function () {
    ['vendor' => $vendor] = appPayrollAdmin();
    $member = appPayrollMember($vendor);
    $project = appPayrollProject($vendor);
    $hour = appPayrollHour($vendor, $member, $project, '2026-05-11', 8);

    Sanctum::actingAs($member, ['app']);
    $this->postJson("/api/app/v1/timesheets/create/{$hour->id}", ['hourly' => 20])->assertCreated();

    $this->getJson("/api/app/v1/timesheets/create/{$hour->id}")->assertStatus(409);
    $this->postJson("/api/app/v1/timesheets/create/{$hour->id}", ['hourly' => 20])->assertStatus(409);
});

it('422s a zero hourly rate that would pay nothing', function () {
    ['vendor' => $vendor] = appPayrollAdmin();
    $member = appPayrollMember($vendor);
    $project = appPayrollProject($vendor);
    $hour = appPayrollHour($vendor, $member, $project, '2026-05-11', 8);

    Sanctum::actingAs($member, ['app']);
    $this->postJson("/api/app/v1/timesheets/create/{$hour->id}", ['hourly' => 0])
        ->assertStatus(422)
        ->assertJsonValidationErrors('hourly');
});

// --- Byte-identical: confirming a week, web vs app -----------------------

it('writes the exact same Timesheet row whether confirmed from the web or the app', function () {
    ['vendor' => $vendor] = appPayrollAdmin();
    $webMember = appPayrollMember($vendor);
    $appMember = appPayrollMember($vendor);
    $project = appPayrollProject($vendor);

    $webHour = appPayrollHour($vendor, $webMember, $project, '2026-05-11', 10);
    $appHour = appPayrollHour($vendor, $appMember, $project, '2026-05-11', 10);

    Livewire::actingAs($webMember)
        ->test(TimesheetCreate::class, ['hour' => $webHour])
        ->set('form.hourly', 30)
        ->call('save')
        ->assertRedirect();

    auth()->forgetGuards();
    Sanctum::actingAs($appMember, ['app']);
    $this->postJson("/api/app/v1/timesheets/create/{$appHour->id}", ['hourly' => 30])->assertCreated();

    $webTimesheet = Timesheet::withoutGlobalScopes()->where('user_id', $webMember->id)->first();
    $appTimesheet = Timesheet::withoutGlobalScopes()->where('user_id', $appMember->id)->first();

    expect($webTimesheet)->not->toBeNull()->and($appTimesheet)->not->toBeNull();

    foreach (['hours', 'amount', 'hourly', 'date'] as $field) {
        expect((string) $appTimesheet->{$field})->toBe((string) $webTimesheet->{$field});
    }
});

// --- Paying a team member ------------------------------------------------

it('lets a member view their own pay page, but never submit it — only an admin may pay', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appPayrollAdmin();
    $member = appPayrollMember($vendor);
    $otherMember = appPayrollMember($vendor);
    Timesheet::forceCreate([
        'date' => '2026-05-11', 'user_id' => $member->id, 'vendor_id' => $vendor->id,
        'hours' => 10, 'amount' => 300, 'hourly' => 30, 'created_by_user_id' => $member->id,
    ]);

    Sanctum::actingAs($member, ['app']);
    $this->getJson("/api/app/v1/timesheets/payment/{$member->id}")->assertOk();
    $this->postJson("/api/app/v1/timesheets/payment/{$member->id}", ['date' => '2026-05-20'])->assertForbidden();

    auth()->forgetGuards();
    Sanctum::actingAs($otherMember, ['app']);
    $this->getJson("/api/app/v1/timesheets/payment/{$member->id}")->assertForbidden();

    auth()->forgetGuards();
    Sanctum::actingAs($admin, ['app']);
    $this->getJson("/api/app/v1/timesheets/payment/{$member->id}")->assertOk();
});

it('pays selected weekly timesheets by check, leaving unselected ones untouched', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appPayrollAdmin();
    ['account' => $account] = appPayrollBankAccount($vendor);
    $member = appPayrollMember($vendor);

    $paid = Timesheet::forceCreate([
        'date' => '2026-05-11', 'user_id' => $member->id, 'vendor_id' => $vendor->id,
        'hours' => 10, 'amount' => 300, 'hourly' => 30, 'created_by_user_id' => $member->id,
    ]);
    $unselected = Timesheet::forceCreate([
        'date' => '2026-05-18', 'user_id' => $member->id, 'vendor_id' => $vendor->id,
        'hours' => 5, 'amount' => 150, 'hourly' => 30, 'created_by_user_id' => $member->id,
    ]);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->postJson("/api/app/v1/timesheets/payment/{$member->id}", [
        'date' => '2026-05-20',
        'bank_account_id' => $account->id,
        'check_type' => 'Check',
        'check_number' => 5001,
        'weekly_timesheet_ids' => [$paid->id],
    ])->assertCreated();

    $checkId = $response->json('data.check.id');
    expect($checkId)->not->toBeNull();

    $paid->refresh();
    $unselected->refresh();
    expect($paid->check_id)->toBe($checkId)
        ->and($unselected->check_id)->toBeNull();

    $check = Check::withoutGlobalScopes()->find($checkId);
    expect((float) $check->amount)->toBe(300.0)
        ->and($check->user_id)->toBe($member->id)
        ->and($check->vendor_id)->toBeNull();
});

it('pays via an employee (paid_by) instead of writing a check', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appPayrollAdmin();
    $member = appPayrollMember($vendor);
    $payer = appPayrollMember($vendor);

    $timesheet = Timesheet::forceCreate([
        'date' => '2026-05-11', 'user_id' => $member->id, 'vendor_id' => $vendor->id,
        'hours' => 10, 'amount' => 300, 'hourly' => 30, 'created_by_user_id' => $member->id,
    ]);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->postJson("/api/app/v1/timesheets/payment/{$member->id}", [
        'date' => '2026-05-20',
        'paid_by' => $payer->id,
        'invoice' => 'INV-1',
        'weekly_timesheet_ids' => [$timesheet->id],
    ])->assertOk();

    expect($response->json('data.check'))->toBeNull();

    $timesheet->refresh();
    expect($timesheet->check_id)->toBeNull()
        ->and($timesheet->paid_by)->toBe($payer->id)
        ->and($timesheet->invoice)->toBe('INV-1');

    expect(Check::withoutGlobalScopes()->where('user_id', $member->id)->exists())->toBeFalse();
});

it('422s a payment selection that totals $0 or less', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appPayrollAdmin();
    ['account' => $account] = appPayrollBankAccount($vendor);
    $member = appPayrollMember($vendor);

    Sanctum::actingAs($admin, ['app']);

    $this->postJson("/api/app/v1/timesheets/payment/{$member->id}", [
        'date' => '2026-05-20',
        'bank_account_id' => $account->id,
        'check_type' => 'Cash',
        'weekly_timesheet_ids' => [999999],
    ])->assertStatus(422)->assertJsonValidationErrors('payment_total');
});

it('never settles a timesheet id that is not genuinely this member\'s own unsettled row', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appPayrollAdmin();
    ['account' => $account] = appPayrollBankAccount($vendor);
    $member = appPayrollMember($vendor);
    $otherMember = appPayrollMember($vendor);

    $own = Timesheet::forceCreate([
        'date' => '2026-05-11', 'user_id' => $member->id, 'vendor_id' => $vendor->id,
        'hours' => 10, 'amount' => 300, 'hourly' => 30, 'created_by_user_id' => $member->id,
    ]);
    $foreign = Timesheet::forceCreate([
        'date' => '2026-05-11', 'user_id' => $otherMember->id, 'vendor_id' => $vendor->id,
        'hours' => 10, 'amount' => 999, 'hourly' => 30, 'created_by_user_id' => $otherMember->id,
    ]);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->postJson("/api/app/v1/timesheets/payment/{$member->id}", [
        'date' => '2026-05-20',
        'bank_account_id' => $account->id,
        'check_type' => 'Cash',
        'weekly_timesheet_ids' => [$own->id, $foreign->id],
    ])->assertCreated();

    $check = Check::withoutGlobalScopes()->find($response->json('data.check.id'));
    expect((float) $check->amount)->toBe(300.0);

    $foreign->refresh();
    expect($foreign->check_id)->toBeNull();
});

/**
 * Pre-existing, intentionally-kept discrepancy (see
 * App\Services\Timesheets\AppPendingBalance's and
 * App\Services\Timesheets\TimesheetPaymentOptions' docblocks): the "Pay"
 * screen's own total (TimesheetPaymentCreate / this controller) never
 * subtracts a fifth term — expenses this member paid out of pocket that are
 * themselves earmarked as someone ELSE's reimbursement — that the
 * "Payable" list's preview (TimesheetPaymentIndex / AppPendingBalance's
 * payable()) DOES subtract. A member with a $300 weekly timesheet and a
 * $50 expense they paid that's flagged `reimbursment = 'V:12'` (owed back
 * by vendor 12, not by them) shows payable $250 on the list, but $300 here
 * — this test locks in the Pay screen's own number, not the list's.
 */
it('does not apply the Payable list\'s fifth deduction term on the Pay screen\'s own total', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appPayrollAdmin();
    $member = appPayrollMember($vendor);

    Timesheet::forceCreate([
        'date' => '2026-05-11', 'user_id' => $member->id, 'vendor_id' => $vendor->id,
        'hours' => 10, 'amount' => 300, 'hourly' => 30, 'created_by_user_id' => $member->id,
    ]);

    // Paid_by = member, but reimbursment points at a DIFFERENT party
    // (vendor 12) — excluded from "paid_expenses" (its own query excludes
    // non-null/non-'Client' reimbursment), yet AppPendingBalance::payable()
    // subtracts it anyway via its fifth term.
    Expense::create([
        'date' => '2026-05-12', 'amount' => 50, 'paid_by' => $member->id, 'reimbursment' => 'V:12',
        'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $member->id,
    ]);

    Sanctum::actingAs($admin, ['app']);

    $payTotal = $this->getJson("/api/app/v1/timesheets/payment/{$member->id}")->assertOk()->json('data.default_total');
    $payableRow = collect($this->getJson('/api/app/v1/timesheets/payable')->assertOk()->json('data'))
        ->firstWhere('user.id', $member->id);

    expect((float) $payTotal)->toBe(300.0)
        ->and((float) $payableRow['total'])->toBe(250.0);
});

// --- Byte-identical: paying a team member, web vs app --------------------

it('writes the exact same Check and Timesheet rows whether paid from the web or the app', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appPayrollAdmin();
    ['account' => $account] = appPayrollBankAccount($vendor);
    $webMember = appPayrollMember($vendor);
    $appMember = appPayrollMember($vendor);
    $project = appPayrollProject($vendor);

    $webTimesheet = Timesheet::forceCreate([
        'date' => '2026-05-11', 'user_id' => $webMember->id, 'vendor_id' => $vendor->id, 'project_id' => $project->id,
        'hours' => 10, 'amount' => 400, 'hourly' => 40, 'created_by_user_id' => $webMember->id,
    ]);
    $appTimesheet = Timesheet::forceCreate([
        'date' => '2026-05-11', 'user_id' => $appMember->id, 'vendor_id' => $vendor->id, 'project_id' => $project->id,
        'hours' => 10, 'amount' => 400, 'hourly' => 40, 'created_by_user_id' => $appMember->id,
    ]);

    Livewire::actingAs($admin)
        ->test(TimesheetPaymentCreate::class, ['user' => $webMember])
        ->set('form.date', '2026-05-22')
        ->set('bank_account_id', $account->id)
        ->set('check_type', 'Cash')
        ->call('save')
        ->assertRedirect();

    auth()->forgetGuards();
    Sanctum::actingAs($admin, ['app']);
    $response = $this->postJson("/api/app/v1/timesheets/payment/{$appMember->id}", [
        'date' => '2026-05-22',
        'bank_account_id' => $account->id,
        'check_type' => 'Cash',
        'weekly_timesheet_ids' => [$appTimesheet->id],
    ])->assertCreated();

    $webCheck = Check::withoutGlobalScopes()->where('user_id', $webMember->id)->first();
    $appCheck = Check::withoutGlobalScopes()->where('user_id', $appMember->id)->first();

    expect($webCheck)->not->toBeNull()->and($appCheck)->not->toBeNull();

    foreach (['amount', 'check_type', 'date', 'vendor_id'] as $field) {
        expect((string) $appCheck->{$field})->toBe((string) $webCheck->{$field});
    }

    $webTimesheet->refresh();
    $appTimesheet->refresh();
    expect($webTimesheet->check_id)->toBe($webCheck->id)
        ->and($appTimesheet->check_id)->toBe($appCheck->id)
        ->and((float) $webTimesheet->amount)->toBe((float) $appTimesheet->amount);

    expect($response->json('data.check.id'))->toBe($appCheck->id);
});
