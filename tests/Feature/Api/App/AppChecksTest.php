<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\CheckImage;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\Timesheet;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * AppAuthTest's appTeamUser(). $role is the vendor_user pivot's role_id
 * (1 = Admin, 2 = Member).
 *
 * @return array{vendor: Vendor, user: User}
 */
function appChecksTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction', 'business_type' => 'LLC']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role, 'is_employed' => 1]);

    return compact('vendor', 'user');
}

/** A check written by $vendor — the minimal columns checks.* requires, plus whatever $overrides sets. */
function appChecksCheck(Vendor $vendor, array $overrides = []): Check
{
    return Check::create(array_merge([
        'check_type' => 'Check',
        'check_number' => random_int(1000, 999999),
        'date' => now()->format('Y-m-d'),
        'amount' => 250,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $vendor->users()->first()?->id ?? User::factory()->create(['primary_vendor_id' => $vendor->id])->id,
    ], $overrides));
}

it('needs a token to list checks', function () {
    $this->getJson('/api/app/v1/checks')->assertUnauthorized();
});

it('allows an Admin to list the company\'s checks', function () {
    ['vendor' => $vendor, 'user' => $admin] = appChecksTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $check = appChecksCheck($vendor, ['check_number' => 4001]);

    $response = $this->getJson('/api/app/v1/checks')->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$check->id])
        ->and($response->json('data.0.check_number'))->toBe(4001);
});

it('scopes a Member to only their own checks, like the web\'s CheckScope', function () {
    ['vendor' => $vendor, 'user' => $member] = appChecksTeamUser(role: 2);
    $otherEmployee = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($otherEmployee->id, ['role_id' => 2, 'is_employed' => 1]);

    $ownCheck = appChecksCheck($vendor, ['user_id' => $member->id, 'check_number' => 5001]);
    appChecksCheck($vendor, ['user_id' => $otherEmployee->id, 'check_number' => 5002]);

    Sanctum::actingAs($member, ['app']);

    $response = $this->getJson('/api/app/v1/checks')->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$ownCheck->id]);
});

it('filters checks by check number and amount', function () {
    ['vendor' => $vendor, 'user' => $admin] = appChecksTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $target = appChecksCheck($vendor, ['check_number' => 7777, 'amount' => 123.45]);
    appChecksCheck($vendor, ['check_number' => 8888, 'amount' => 999]);

    $byNumber = $this->getJson('/api/app/v1/checks?check_number=777')->assertOk();
    expect(collect($byNumber->json('data'))->pluck('id')->all())->toBe([$target->id]);

    $byAmount = $this->getJson('/api/app/v1/checks?amount=123')->assertOk();
    expect(collect($byAmount->json('data'))->pluck('id')->all())->toBe([$target->id]);
});

it('lists one Bank filter option per plaid_ins_id, grouping sibling accounts under it like ChecksIndex::mount()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appChecksTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $vendor->id, 'plaid_ins_id' => 'ins_shared']);
    BankAccount::create(['vendor_id' => $vendor->id, 'bank_id' => $bank->id, 'account_number' => '1', 'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'Checking']);
    // A sibling Bank row sharing the same plaid_ins_id (another account at
    // the same institution) must collapse into the SAME filter option.
    $siblingBank = Bank::create(['name' => 'Citibank Savings', 'vendor_id' => $vendor->id, 'plaid_ins_id' => 'ins_shared']);
    BankAccount::create(['vendor_id' => $vendor->id, 'bank_id' => $siblingBank->id, 'account_number' => '2', 'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'Savings']);

    $response = $this->getJson('/api/app/v1/checks/bank-options')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.name'))->toBe('Citibank');
});

it('404s a check that does not exist', function () {
    ['user' => $admin] = appChecksTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $this->getJson('/api/app/v1/checks/999999')->assertNotFound();
});

it('refuses a check belonging to a vendor outside the team entirely, via CheckScope', function () {
    ['user' => $admin] = appChecksTeamUser(role: 1);
    $outsiderVendor = Vendor::factory()->create(['business_type' => 'LLC']);
    $outsiderCheck = appChecksCheck($outsiderVendor);

    Sanctum::actingAs($admin, ['app']);

    $this->getJson("/api/app/v1/checks/{$outsiderCheck->id}")->assertForbidden();
});

it('shows a check\'s detail with its expenses, timesheets and images', function () {
    ['vendor' => $vendor, 'user' => $admin] = appChecksTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $payee = User::factory()->create(['primary_vendor_id' => $vendor->id, 'nickname' => 'Czarek']);
    $vendor->users()->attach($payee->id, ['role_id' => 2, 'is_employed' => 1]);

    $check = appChecksCheck($vendor, ['user_id' => $payee->id, 'amount' => 900]);

    $expense = Expense::create([
        'amount' => 400, 'date' => now()->format('Y-m-d'), 'check_id' => $check->id,
        'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $admin->id,
    ]);

    // A distribution expense settled by the same check — CheckShow's own
    // "Paid Distributions" card (checks/show.blade.php:152-175).
    $distribution = Distribution::create(['name' => 'Profit Share', 'user_id' => $admin->id, 'vendor_id' => $vendor->id]);
    $distributionExpense = Expense::create([
        'amount' => 50, 'date' => now()->format('Y-m-d'), 'check_id' => $check->id,
        'distribution_id' => $distribution->id,
        'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $admin->id,
    ]);

    $timesheet = Timesheet::create([
        'date' => now()->format('Y-m-d'), 'user_id' => $payee->id, 'vendor_id' => $vendor->id, 'check_id' => $check->id,
        'hours' => 40, 'amount' => 500, 'hourly' => 12.5, 'created_by_user_id' => $admin->id,
    ]);

    // A timesheet belonging to ANOTHER team member, paid off by this check's
    // payee — mirrors CheckShow's $employee_weekly_timesheets ("Employee
    // Paid Timesheets" card).
    $otherEmployee = User::factory()->create(['primary_vendor_id' => $vendor->id, 'first_name' => 'Robert', 'last_name' => 'Smith']);
    $vendor->users()->attach($otherEmployee->id, ['role_id' => 2, 'is_employed' => 1]);
    $employeePaidTimesheet = Timesheet::create([
        'date' => now()->format('Y-m-d'), 'user_id' => $otherEmployee->id, 'paid_by' => $payee->id,
        'vendor_id' => $vendor->id, 'check_id' => $check->id, 'hours' => 10, 'amount' => 125, 'hourly' => 12.5,
        'created_by_user_id' => $admin->id,
    ]);

    $image = CheckImage::create([
        'image_filename' => 'check-'.$check->id.'.jpg',
        'statement_filename' => 'statement-'.$check->id.'.pdf',
        'check_id' => $check->id,
        'belongs_to_vendor_id' => $vendor->id,
        'check_date' => now()->format('Y-m-d'),
    ]);

    $bank = Bank::create(['name' => 'PSFCU', 'vendor_id' => $vendor->id, 'plaid_ins_id' => 'ins_'.uniqid()]);
    $bankAccount = BankAccount::create([
        'bank_id' => $bank->id, 'vendor_id' => $vendor->id, 'type' => 'Checking',
        'account_number' => 1234, 'plaid_account_id' => 'acc_'.uniqid(),
    ]);
    $transaction = Transaction::create([
        'amount' => 900, 'transaction_date' => now()->format('Y-m-d'), 'bank_account_id' => $bankAccount->id, 'check_id' => $check->id,
    ]);

    $response = $this->getJson("/api/app/v1/checks/{$check->id}")->assertOk();

    expect($response->json('data.id'))->toBe($check->id)
        ->and(collect($response->json('data.expenses'))->pluck('id')->all())->toContain($expense->id, $distributionExpense->id)
        ->and($response->json('data.expenses.0.is_deduction'))->toBeFalse()
        ->and($response->json('data.payee_first_name'))->toBe('Czarek')
        // The payee is a user (no via-vendor) — Check::payeeRoute() points
        // the app straight at /users/{id} (checks/show.blade.php:24).
        ->and($response->json('data.payee.id'))->toBe($payee->id)
        ->and($response->json('data.payee.type'))->toBe('user')
        ->and(collect($response->json('data.timesheets'))->pluck('id')->all())->toBe([$timesheet->id])
        ->and(collect($response->json('data.employee_paid_timesheets'))->pluck('id')->all())->toBe([$employeePaidTimesheet->id])
        ->and($response->json('data.employee_paid_timesheets.0.team_member'))->toBe('Robert Smith')
        ->and(collect($response->json('data.transactions'))->pluck('id')->all())->toBe([$transaction->id])
        ->and($response->json('data.transactions.0.bank'))->toBe('PSFCU')
        ->and($response->json('data.transactions.0.account'))->toBe('1234')
        ->and($response->json('data.images.0.id'))->toBe($image->id)
        ->and($response->json('data.images.0.url'))->toContain("checks/images/{$image->id}/signed")
        // "View Full Statement" (checks/show.blade.php:251-262) — a signed
        // link to the same files/checks/files/ folder the web serves from.
        ->and($response->json('data.images.0.statement_url'))->toContain('checks/statements/statement-'.$check->id.'.pdf/signed');

    $distributionRow = collect($response->json('data.expenses'))->firstWhere('id', $distributionExpense->id);
    expect($distributionRow['distribution'])->toBe(['id' => $distribution->id, 'name' => 'Profit Share']);
});

it('points a vendor-paid check\'s payee at the vendor, not a user', function () {
    ['vendor' => $vendor, 'user' => $admin] = appChecksTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $payeeVendor = Vendor::factory()->create(['business_type' => 'Sub', 'business_name' => 'ABC Plumbing']);
    $vendor->vendors()->attach($payeeVendor->id);
    $check = appChecksCheck($vendor, ['vendor_id' => $payeeVendor->id]);

    $response = $this->getJson("/api/app/v1/checks/{$check->id}")->assertOk();

    expect($response->json('data.payee.id'))->toBe($payeeVendor->id)
        ->and($response->json('data.payee.type'))->toBe('vendor');
});

it('falls back to the payee\'s first name when they have no nickname', function () {
    ['vendor' => $vendor, 'user' => $admin] = appChecksTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $payee = User::factory()->create(['primary_vendor_id' => $vendor->id, 'first_name' => 'Patryk', 'nickname' => null]);
    $vendor->users()->attach($payee->id, ['role_id' => 2, 'is_employed' => 1]);
    $check = appChecksCheck($vendor, ['user_id' => $payee->id]);
    Timesheet::create([
        'date' => now()->format('Y-m-d'), 'user_id' => $payee->id, 'vendor_id' => $vendor->id, 'check_id' => $check->id,
        'hours' => 8, 'amount' => 100, 'hourly' => 12.5, 'created_by_user_id' => $admin->id,
    ]);

    $response = $this->getJson("/api/app/v1/checks/{$check->id}")->assertOk();

    expect($response->json('data.payee_first_name'))->toBe('Patryk');
});

it('refuses viewing a check the Member does not own, like the web', function () {
    ['vendor' => $vendor, 'user' => $member] = appChecksTeamUser(role: 2);
    $otherEmployee = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($otherEmployee->id, ['role_id' => 2, 'is_employed' => 1]);

    $othersCheck = appChecksCheck($vendor, ['user_id' => $otherEmployee->id]);

    Sanctum::actingAs($member, ['app']);

    // The check exists (same company), but CheckScope excludes it from this
    // Member's own scoped query — same 403 the visibleProject()/
    // visibleClient() pattern gives for "exists, but not yours".
    $this->getJson("/api/app/v1/checks/{$othersCheck->id}")->assertForbidden();
});
