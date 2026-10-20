<?php

use App\Jobs\SendVendorPaymentEmailJob;
use App\Livewire\Vendors\VendorPaymentCreate;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Check;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectStatus;
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

    // The vendor payment page mounts child components unavailable in the
    // test environment — same stubs as VendorPaymentReimbursementTest.
    Livewire::component('bids.bid-create', AppVendorPaymentsChildStub::class);
    Livewire::component('vendor-docs.vendor-docs-card', AppVendorPaymentsChildStub::class);
    Livewire::component('vendor-docs.vendor-doc-create', AppVendorPaymentsChildStub::class);
});

class AppVendorPaymentsChildStub extends \Livewire\Component
{
    public function render()
    {
        return '<div></div>';
    }
}

/** Vendor id 1 pinned (GS Construction), as in the other App API test files. @return array{vendor: Vendor, admin: User} */
function appVendorPaymentsAdmin(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction', 'business_type' => 'Sub']);
    $admin = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $admin->vendors()->attach($vendor->id, ['role_id' => 1, 'is_employed' => 1]);

    return compact('vendor', 'admin');
}

function appVendorPaymentsMember(Vendor $vendor): User
{
    $member = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $member->vendors()->attach($vendor->id, ['role_id' => 2, 'is_employed' => 1]);

    return $member;
}

/** A Sub vendor the company can pay, linked into the company's directory. */
function appVendorPaymentsSub(Vendor $company, array $overrides = []): Vendor
{
    $sub = Vendor::factory()->create(array_merge(['business_type' => 'Sub', 'business_email' => 'sub@example.com'], $overrides));
    $company->vendors()->attach($sub->id);

    return $sub;
}

function appVendorPaymentsProject(Vendor $company, Vendor $sub, float $bidAmount): Project
{
    $client = Client::factory()->create();
    $project = Project::factory()->create([
        'belongs_to_vendor_id' => $company->id,
        'client_id' => $client->id,
    ]);
    $project->vendors()->attach($company->id, ['client_id' => $client->id]);
    ProjectStatus::forceCreate([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $company->id,
        'status_code' => 6,
        'start_date' => now()->addDay(),
    ]);
    $project->bids()->create(['vendor_id' => $sub->id, 'amount' => $bidAmount, 'type' => 'Labor']);

    return $project;
}

/** @return array{bank: Bank, account: BankAccount} */
function appVendorPaymentsBankAccount(Vendor $vendor): array
{
    $bank = Bank::query()->create([
        'name' => 'Test Vendor Pay Bank',
        'vendor_id' => $vendor->id,
        'plaid_ins_id' => 'ins_vendor_pay_test',
        'plaid_access_token' => 'access-test-token',
    ]);
    $account = BankAccount::query()->create([
        'bank_id' => $bank->id,
        'vendor_id' => $vendor->id,
        'account_number' => 9876,
        'plaid_account_id' => 'acct_vendor_pay_test',
        'type' => 'Checking',
    ]);

    return compact('bank', 'account');
}

it('needs a token for every vendor payment route', function () {
    ['vendor' => $company] = appVendorPaymentsAdmin();
    $sub = appVendorPaymentsSub($company);

    $this->getJson("/api/app/v1/vendors/{$sub->id}/payment")->assertUnauthorized();
    $this->postJson("/api/app/v1/vendors/{$sub->id}/payment", [])->assertUnauthorized();
});

it('refuses the pay screen to a Member, and to a 1099 vendor\'s own Admin', function () {
    ['vendor' => $company, 'admin' => $admin] = appVendorPaymentsAdmin();
    $this->actingAs($admin);
    $sub = appVendorPaymentsSub($company);
    $member = appVendorPaymentsMember($company);

    Sanctum::actingAs($member, ['app']);
    $this->getJson("/api/app/v1/vendors/{$sub->id}/payment")->assertForbidden();
    $this->postJson("/api/app/v1/vendors/{$sub->id}/payment", ['date' => '2026-07-14'])->assertForbidden();

    auth()->forgetGuards();
    $company->forceFill(['business_type' => '1099'])->save();
    Sanctum::actingAs($admin, ['app']);
    $this->getJson("/api/app/v1/vendors/{$sub->id}/payment")->assertForbidden();
});

it('shows eligible projects with their balances and unsettled reimbursements', function () {
    ['vendor' => $company, 'admin' => $admin] = appVendorPaymentsAdmin();
    $this->actingAs($admin);
    $sub = appVendorPaymentsSub($company);
    $project = appVendorPaymentsProject($company, $sub, 1000.00);
    Expense::forceCreate([
        'date' => '2026-06-23', 'amount' => 70.00, 'vendor_id' => $sub->id, 'reimbursment' => 'V:'.$sub->id,
        'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => $admin->id,
    ]);

    Sanctum::actingAs($admin, ['app']);
    $response = $this->getJson("/api/app/v1/vendors/{$sub->id}/payment")->assertOk();

    $projectRow = collect($response->json('data.projects'))->firstWhere('id', $project->id);
    expect($projectRow['has_outstanding_balance'])->toBeTrue()
        ->and((float) $projectRow['balance'])->toBe(1000.0)
        ->and($response->json('data.reimbursement_expenses'))->toHaveCount(1)
        ->and((float) $response->json('data.reimbursement_expenses.0.amount'))->toBe(70.0);
});

it('pays a vendor by check, netting a selected reimbursement, and queues the payment email', function () {
    ['vendor' => $company, 'admin' => $admin] = appVendorPaymentsAdmin();
    $this->actingAs($admin);
    ['account' => $account] = appVendorPaymentsBankAccount($company);
    $sub = appVendorPaymentsSub($company);
    $project = appVendorPaymentsProject($company, $sub, 1000.00);
    $reimbursement = Expense::forceCreate([
        'date' => '2026-06-23', 'amount' => 70.00, 'vendor_id' => $sub->id, 'reimbursment' => 'V:'.$sub->id,
        'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => $admin->id,
    ]);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->postJson("/api/app/v1/vendors/{$sub->id}/payment", [
        'date' => '2026-07-14',
        'bank_account_id' => $account->id,
        'check_type' => 'Cash',
        'projects' => [['id' => $project->id, 'amount' => 500]],
        'reimbursement_expense_ids' => [$reimbursement->id],
    ])->assertCreated();

    $checkId = $response->json('data.check.id');
    $check = Check::withoutGlobalScopes()->find($checkId);
    expect((float) $check->amount)->toBe(430.0)
        ->and($check->vendor_id)->toBe($sub->id);

    $reimbursement->refresh();
    expect($reimbursement->check_id)->toBe($check->id);

    $paymentExpense = Expense::withoutGlobalScopes()->where('check_id', $check->id)->where('project_id', $project->id)->first();
    expect((float) $paymentExpense->amount)->toBe(500.0);

    Queue::assertPushed(SendVendorPaymentEmailJob::class);
});

it('pays a vendor via an employee instead of a check, and never emails a payment notice for it', function () {
    ['vendor' => $company, 'admin' => $admin] = appVendorPaymentsAdmin();
    $this->actingAs($admin);
    $sub = appVendorPaymentsSub($company);
    $payer = appVendorPaymentsMember($company);
    $project = appVendorPaymentsProject($company, $sub, 1000.00);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->postJson("/api/app/v1/vendors/{$sub->id}/payment", [
        'date' => '2026-07-14',
        'paid_by' => $payer->id,
        'invoice' => 'INV-9',
        'projects' => [['id' => $project->id, 'amount' => 500]],
    ])->assertOk();

    expect($response->json('data.check'))->toBeNull();

    $expense = Expense::withoutGlobalScopes()->where('project_id', $project->id)->where('vendor_id', $sub->id)->first();
    expect($expense->check_id)->toBeNull()
        ->and($expense->paid_by)->toBe($payer->id)
        ->and($expense->invoice)->toBe('INV-9');

    Queue::assertNotPushed(SendVendorPaymentEmailJob::class);
});

it('422s when no project has a positive amount', function () {
    ['vendor' => $company, 'admin' => $admin] = appVendorPaymentsAdmin();
    $this->actingAs($admin);
    ['account' => $account] = appVendorPaymentsBankAccount($company);
    $sub = appVendorPaymentsSub($company);
    $project = appVendorPaymentsProject($company, $sub, 1000.00);

    Sanctum::actingAs($admin, ['app']);

    $this->postJson("/api/app/v1/vendors/{$sub->id}/payment", [
        'date' => '2026-07-14',
        'bank_account_id' => $account->id,
        'check_type' => 'Cash',
        'projects' => [['id' => $project->id, 'amount' => null]],
    ])->assertStatus(422);
});

it('422s pairing paid_by with a selected reimbursement — deductions only settle through a check', function () {
    ['vendor' => $company, 'admin' => $admin] = appVendorPaymentsAdmin();
    $this->actingAs($admin);
    $sub = appVendorPaymentsSub($company);
    $payer = appVendorPaymentsMember($company);
    $project = appVendorPaymentsProject($company, $sub, 1000.00);
    $reimbursement = Expense::forceCreate([
        'date' => '2026-06-23', 'amount' => 70.00, 'vendor_id' => $sub->id, 'reimbursment' => 'V:'.$sub->id,
        'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => $admin->id,
    ]);

    Sanctum::actingAs($admin, ['app']);

    $this->postJson("/api/app/v1/vendors/{$sub->id}/payment", [
        'date' => '2026-07-14',
        'paid_by' => $payer->id,
        'invoice' => 'INV-1',
        'projects' => [['id' => $project->id, 'amount' => 500]],
        'reimbursement_expense_ids' => [$reimbursement->id],
    ])->assertStatus(422)->assertJsonValidationErrors('paid_by');

    expect(Check::withoutGlobalScopes()->where('vendor_id', $sub->id)->exists())->toBeFalse();
});

it('refuses a check number already used on a sibling account under the same bank, like the web', function () {
    ['vendor' => $company, 'admin' => $admin] = appVendorPaymentsAdmin();
    $this->actingAs($admin);
    ['bank' => $bank, 'account' => $accountA] = appVendorPaymentsBankAccount($company);
    // A re-linked Plaid item duplicates accounts under the same bank — same
    // scenario CheckNumberSuggester::siblingAccountIds() exists for.
    $accountB = BankAccount::query()->create([
        'bank_id' => $bank->id, 'vendor_id' => $company->id, 'account_number' => 9877,
        'plaid_account_id' => 'acct_vendor_pay_sibling_'.uniqid(), 'type' => 'Checking',
    ]);
    $sub = appVendorPaymentsSub($company);
    $project = appVendorPaymentsProject($company, $sub, 1000.00);

    Check::create(['check_type' => 'Check', 'check_number' => '5050', 'date' => '2026-07-01', 'amount' => 100, 'bank_account_id' => $accountA->id, 'vendor_id' => $sub->id, 'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => $admin->id]);

    Sanctum::actingAs($admin, ['app']);

    $this->postJson("/api/app/v1/vendors/{$sub->id}/payment", [
        'date' => '2026-07-14',
        'bank_account_id' => $accountB->id,
        'check_type' => 'Check',
        'check_number' => '5050',
        'projects' => [['id' => $project->id, 'amount' => 500]],
    ])->assertStatus(422)->assertJsonValidationErrors('check_number');

    expect(Check::withoutGlobalScopes()->where('bank_account_id', $accountB->id)->exists())->toBeFalse();
});

it('never pays a project outside the tenant, even if the id rides in on the request', function () {
    ['vendor' => $company, 'admin' => $admin] = appVendorPaymentsAdmin();
    $this->actingAs($admin);
    ['account' => $account] = appVendorPaymentsBankAccount($company);
    $sub = appVendorPaymentsSub($company);
    $foreignProject = Project::factory()->create([
        'belongs_to_vendor_id' => Vendor::factory()->create()->id,
        'client_id' => Client::factory()->create()->id,
    ]);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->postJson("/api/app/v1/vendors/{$sub->id}/payment", [
        'date' => '2026-07-14',
        'bank_account_id' => $account->id,
        'check_type' => 'Cash',
        'projects' => [['id' => $foreignProject->id, 'amount' => 500]],
    ])->assertStatus(422);

    expect(Check::withoutGlobalScopes()->where('vendor_id', $sub->id)->exists())->toBeFalse();
});

it('exposes can_pay on the vendor resource: never for Retail, never for your own company, never for a Member', function () {
    ['vendor' => $company, 'admin' => $admin] = appVendorPaymentsAdmin();
    $this->actingAs($admin);
    $sub = appVendorPaymentsSub($company);
    $retail = appVendorPaymentsSub($company, ['business_type' => 'Retail']);
    $member = appVendorPaymentsMember($company);

    Sanctum::actingAs($admin, ['app']);
    $this->getJson("/api/app/v1/vendors/{$sub->id}")->assertOk()->assertJsonPath('data.can_pay', true);
    $this->getJson("/api/app/v1/vendors/{$retail->id}")->assertOk()->assertJsonPath('data.can_pay', false);
    $this->getJson("/api/app/v1/vendors/{$company->id}")->assertOk()->assertJsonPath('data.can_pay', false);

    auth()->forgetGuards();
    Sanctum::actingAs($member, ['app']);
    $this->getJson("/api/app/v1/vendors/{$sub->id}")->assertOk()->assertJsonPath('data.can_pay', false);
});

// --- Byte-identical: paying a vendor, web vs app --------------------------

it('writes the exact same Check and Expense rows whether a vendor is paid from the web or the app', function () {
    ['vendor' => $company, 'admin' => $admin] = appVendorPaymentsAdmin();
    $this->actingAs($admin);
    ['account' => $account] = appVendorPaymentsBankAccount($company);
    $webSub = appVendorPaymentsSub($company);
    $appSub = appVendorPaymentsSub($company);
    $webProject = appVendorPaymentsProject($company, $webSub, 1000.00);
    $appProject = appVendorPaymentsProject($company, $appSub, 1000.00);

    Livewire::actingAs($admin)
        ->test(VendorPaymentCreate::class, ['vendor' => $webSub])
        ->set('form.date', '2026-07-14')
        ->set('bank_account_id', $account->id)
        ->set('check_type', 'Cash')
        ->set("projects.{$webProject->id}.amount", 500)
        ->call('save')
        ->assertRedirect();

    auth()->forgetGuards();
    Sanctum::actingAs($admin, ['app']);
    $response = $this->postJson("/api/app/v1/vendors/{$appSub->id}/payment", [
        'date' => '2026-07-14',
        'bank_account_id' => $account->id,
        'check_type' => 'Cash',
        'projects' => [['id' => $appProject->id, 'amount' => 500]],
    ])->assertCreated();

    $webCheck = Check::withoutGlobalScopes()->where('vendor_id', $webSub->id)->latest('id')->first();
    $appCheck = Check::withoutGlobalScopes()->where('vendor_id', $appSub->id)->latest('id')->first();

    expect($webCheck)->not->toBeNull()->and($appCheck)->not->toBeNull();

    foreach (['amount', 'check_type', 'date', 'user_id'] as $field) {
        expect((string) $appCheck->{$field})->toBe((string) $webCheck->{$field});
    }

    $webExpense = Expense::withoutGlobalScopes()->where('check_id', $webCheck->id)->first();
    $appExpense = Expense::withoutGlobalScopes()->where('check_id', $appCheck->id)->first();

    foreach (['amount', 'date', 'invoice'] as $field) {
        expect((string) $appExpense->{$field})->toBe((string) $webExpense->{$field});
    }

    expect($response->json('data.check.id'))->toBe($appCheck->id);
    Queue::assertPushed(SendVendorPaymentEmailJob::class, 2);
});
