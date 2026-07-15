<?php

use App\Livewire\Checks\CheckShow;
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
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'collection']);
    \Illuminate\Support\Facades\Queue::fake();

    // The vendor payment page mounts child components that need services
    // unavailable in the test environment — stub them out.
    Livewire::component('bids.bid-create', VendorPaymentChildStub::class);
    Livewire::component('vendor-docs.vendor-docs-card', VendorPaymentChildStub::class);
    Livewire::component('vendor-docs.vendor-doc-create', VendorPaymentChildStub::class);
});

class VendorPaymentChildStub extends \Livewire\Component
{
    public function render()
    {
        return '<div></div>';
    }
}

/**
 * Company (auth) vendor + admin user, a Sub vendor owned by the company,
 * a merchant vendor, and a bank account for check payments.
 */
function vendorReimbursementSetup(): array
{
    $company = Vendor::factory()->create();
    $company->forceFill([
        'business_type' => 'LLC',
        'registration' => ['registered' => true],
    ])->save();

    $admin = User::query()->create([
        'first_name' => 'Test',
        'last_name' => 'Admin',
        'email' => 'vendor-payment-admin@example.com',
        'cell_phone' => '5551230000',
        'password' => bcrypt('password'),
        'primary_vendor_id' => $company->id,
    ]);
    $admin->vendors()->attach($company->id, ['role_id' => 1]);

    $sub = Vendor::factory()->create();
    $sub->forceFill(['business_type' => 'Sub', 'business_name' => 'PMG Test Carpentry', 'business_email' => 'sub@example.com'])->save();
    $company->vendors()->attach($sub->id);

    $merchant = Vendor::factory()->create();
    $merchant->forceFill(['business_type' => 'Retail', 'business_name' => 'Village Test Hall'])->save();
    $company->vendors()->attach($merchant->id);

    $bank = Bank::query()->create([
        'name' => 'Test Bank',
        'vendor_id' => $company->id,
        'plaid_ins_id' => 'ins_test',
        'plaid_access_token' => 'access-test-token',
    ]);
    $bankAccount = BankAccount::query()->create([
        'bank_id' => $bank->id,
        'vendor_id' => $company->id,
        'account_number' => 1234,
        'plaid_account_id' => 'acct_test',
        'type' => 'Checking',
    ]);

    return [$company, $admin, $sub, $merchant, $bankAccount];
}

function makeVendorReimbursementExpense(Vendor $company, Vendor $sub, Vendor $merchant, float $amount = 70.00): Expense
{
    return Expense::forceCreate([
        'date' => '2026-06-23',
        'amount' => $amount,
        'vendor_id' => $merchant->id,
        'reimbursment' => 'V:'.$sub->id,
        'belongs_to_vendor_id' => $company->id,
        'created_by_user_id' => 0,
    ]);
}

function makeProjectWithBid(Vendor $company, Vendor $sub, float $bidAmount): Project
{
    $client = Client::factory()->create();
    $project = Project::factory()->create([
        'belongs_to_vendor_id' => $company->id,
        'client_id' => $client->id,
    ]);
    $project->vendors()->attach($company->id, ['client_id' => $client->id]); // ProjectScope visibility
    ProjectStatus::forceCreate([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $company->id,
        'status_code' => 6, // Active
        // Must be later than the auto-created initial status (today) so
        // latestOfMany picks it
        'start_date' => now()->addDay(),
    ]);
    $project->bids()->create([
        'vendor_id' => $sub->id,
        'amount' => $bidAmount,
        'type' => 'Labor',
    ]);

    return $project;
}

it('lists unsettled vendor reimbursements and deducts selected ones from the check total', function () {
    [$company, $admin, $sub, $merchant, $bankAccount] = vendorReimbursementSetup();
    $this->actingAs($admin);
    $expense = makeVendorReimbursementExpense($company, $sub, $merchant);
    $project = makeProjectWithBid($company, $sub, 1000.00);

    $component = Livewire::actingAs($admin)
        ->test(VendorPaymentCreate::class, ['vendor' => $sub])
        ->assertSee('owes for Expenses')
        ->assertSee('70.00');

    // Unselected by default — total is just the project amount
    $component->set("projects.{$project->id}.amount", 500);
    expect($component->instance()->getVendorCheckSumProperty())->toEqual(500.0);

    // Selecting the reimbursement subtracts it
    $component->set("selectedVendorReimbursementExpenses.{$expense->id}", true);
    expect($component->instance()->getVendorCheckSumProperty())->toEqual(430.0);

    // Paid By must be disabled while a reimbursement is selected
    expect($component->instance()->getDisablePaidByProperty())->toBeTrue();
});

it('settles selected reimbursements on save: check amount is net, expense links to the check', function () {
    [$company, $admin, $sub, $merchant, $bankAccount] = vendorReimbursementSetup();
    $this->actingAs($admin);
    $expense = makeVendorReimbursementExpense($company, $sub, $merchant);
    $project = makeProjectWithBid($company, $sub, 1000.00);

    Livewire::actingAs($admin)
        ->test(VendorPaymentCreate::class, ['vendor' => $sub])
        ->set('form.date', '2026-07-14')
        ->set('bank_account_id', $bankAccount->id)
        ->set('check_type', 'Cash')
        ->set("projects.{$project->id}.amount", 500)
        ->set("selectedVendorReimbursementExpenses.{$expense->id}", true)
        ->call('save')
        ->assertRedirect();

    $check = Check::withoutGlobalScopes()->where('vendor_id', $sub->id)->latest('id')->first();
    expect($check)->not->toBeNull();

    // Net total: 500 project payment − 70 reimbursement
    expect((float) $check->amount)->toEqual(430.0);

    // The reimbursement expense is settled (linked to the check), amount stays
    // positive in the DB, and reimbursment is unchanged
    $expense->refresh();
    expect($expense->check_id)->toBe($check->id)
        ->and((float) $expense->amount)->toEqual(70.0)
        ->and($expense->getRawOriginal('reimbursment'))->toBe('V:'.$sub->id);

    // The project payment expense was created for the gross amount
    $paymentExpense = Expense::withoutGlobalScopes()
        ->where('check_id', $check->id)
        ->where('project_id', $project->id)
        ->first();
    expect((float) $paymentExpense->amount)->toEqual(500.0);
});

it('leaves unselected reimbursements untouched on save', function () {
    [$company, $admin, $sub, $merchant, $bankAccount] = vendorReimbursementSetup();
    $this->actingAs($admin);
    $expense = makeVendorReimbursementExpense($company, $sub, $merchant);
    $project = makeProjectWithBid($company, $sub, 1000.00);

    Livewire::actingAs($admin)
        ->test(VendorPaymentCreate::class, ['vendor' => $sub])
        ->set('form.date', '2026-07-14')
        ->set('bank_account_id', $bankAccount->id)
        ->set('check_type', 'Cash')
        ->set("projects.{$project->id}.amount", 500)
        ->call('save')
        ->assertRedirect();

    $check = Check::withoutGlobalScopes()->where('vendor_id', $sub->id)->latest('id')->first();
    expect((float) $check->amount)->toEqual(500.0);

    $expense->refresh();
    expect($expense->check_id)->toBeNull();
});

it('shows the vendor reimbursement bucket on the check page', function () {
    [$company, $admin, $sub, $merchant, $bankAccount] = vendorReimbursementSetup();
    $expense = makeVendorReimbursementExpense($company, $sub, $merchant);

    $check = Check::forceCreate([
        'check_type' => 'Cash',
        'date' => '2026-07-14',
        'bank_account_id' => $bankAccount->id,
        'vendor_id' => $sub->id,
        'belongs_to_vendor_id' => $company->id,
        'created_by_user_id' => $admin->id,
    ]);
    $expense->forceFill(['check_id' => $check->id])->save();
    $paymentExpense = Expense::forceCreate([
        'date' => '2026-07-14',
        'amount' => 500.00,
        'vendor_id' => $sub->id,
        'check_id' => $check->id,
        'belongs_to_vendor_id' => $company->id,
        'created_by_user_id' => $admin->id,
    ]);
    $check->recalculateAmount();

    expect((float) $check->amount)->toEqual(430.0);

    // Remove the plain payment expense before rendering: the nested
    // expense-index it triggers needs a real Meilisearch. The reimbursement
    // card renders from the CheckShow bucket directly.
    $paymentExpense->delete();

    Livewire::actingAs($admin)
        ->test(CheckShow::class, ['check' => $check])
        ->assertSee('Paid back these Expenses')
        ->assertSee('Village Test Hall');
});

it('unlinks (not deletes) settled reimbursements when the check is deleted, but deletes payment expenses', function () {
    [$company, $admin, $sub, $merchant, $bankAccount] = vendorReimbursementSetup();
    $this->actingAs($admin);

    $reimbursement = makeVendorReimbursementExpense($company, $sub, $merchant);

    $check = Check::forceCreate([
        'check_type' => 'Cash',
        'date' => '2026-07-14',
        'bank_account_id' => $bankAccount->id,
        'vendor_id' => $sub->id,
        'belongs_to_vendor_id' => $company->id,
        'created_by_user_id' => $admin->id,
    ]);
    $reimbursement->forceFill(['check_id' => $check->id])->save();
    $paymentExpense = Expense::forceCreate([
        'date' => '2026-07-14',
        'amount' => 500.00,
        'vendor_id' => $sub->id,
        'check_id' => $check->id,
        'belongs_to_vendor_id' => $company->id,
        'created_by_user_id' => $admin->id,
    ]);

    $check->delete();

    // Reimbursement survives, unlinked — deductible again on the next payment
    $reimbursement->refresh();
    expect($reimbursement->deleted_at)->toBeNull()
        ->and($reimbursement->check_id)->toBeNull();

    // The payment-created expense dies with the check
    expect(Expense::withoutGlobalScopes()->withTrashed()->find($paymentExpense->id)->deleted_at)
        ->not->toBeNull();
});

it('resolves V:{vendor_id} reimbursment values to the vendor business name for display', function () {
    [$company, $admin, $sub, $merchant] = vendorReimbursementSetup();
    $expense = makeVendorReimbursementExpense($company, $sub, $merchant);

    expect($expense->reimbursment)->toBe('Pmg Test Carpentry')
        ->and($expense->getRawOriginal('reimbursment'))->toBe('V:'.$sub->id);
});

it('blocks submitting when selected reimbursements exceed the payment amount', function () {
    [$company, $admin, $sub, $merchant, $bankAccount] = vendorReimbursementSetup();
    $this->actingAs($admin);
    $expense = makeVendorReimbursementExpense($company, $sub, $merchant, 700.00);
    $project = makeProjectWithBid($company, $sub, 1000.00);

    $component = Livewire::actingAs($admin)
        ->test(VendorPaymentCreate::class, ['vendor' => $sub])
        ->set('form.date', '2026-07-14')
        ->set('bank_account_id', $bankAccount->id)
        ->set('check_type', 'Cash')
        ->set("projects.{$project->id}.amount", 500)
        ->set("selectedVendorReimbursementExpenses.{$expense->id}", true);

    // Net total would be −200 — submit gate must be closed
    expect($component->instance()->getVendorCheckSumProperty())->toEqual(-200.0)
        ->and($component->instance()->getCanSubmitPaymentProperty())->toBeFalse();

    $component->call('confirmPayment')
        ->assertHasErrors('check_total_min');

    expect(Check::withoutGlobalScopes()->where('vendor_id', $sub->id)->exists())->toBeFalse();
});
it('does not negate a reimbursement on its own merchant-payment check, only on the settlement check', function () {
    [$company, $admin, $sub, $merchant, $bankAccount] = vendorReimbursementSetup();
    $expense = makeVendorReimbursementExpense($company, $sub, $merchant); // V:{sub}, $70, merchant vendor

    // Company pays the MERCHANT for this expense (check payee = merchant):
    // the V:{sub} marker must NOT deduct here — the check paid $70 out.
    $merchantCheck = Check::forceCreate([
        'check_type' => 'Cash',
        'date' => '2026-06-23',
        'bank_account_id' => $bankAccount->id,
        'vendor_id' => $merchant->id,
        'belongs_to_vendor_id' => $company->id,
        'created_by_user_id' => $admin->id,
    ]);
    $expense->forceFill(['check_id' => $merchantCheck->id])->save();
    $merchantCheck->recalculateAmount();
    expect((float) $merchantCheck->amount)->toEqual(70.0);

    // Deleting the merchant check soft-deletes the expense with it (it is the
    // payment for it, not a settlement) — no phantom receivable survives.
    $this->actingAs($admin);
    $merchantCheck->delete();
    expect(Expense::withoutGlobalScopes()->withTrashed()->find($expense->id)->deleted_at)->not->toBeNull();
});

it('clears Paid By when a reimbursement is selected after it, and store() refuses the combination', function () {
    [$company, $admin, $sub, $merchant, $bankAccount] = vendorReimbursementSetup();
    $this->actingAs($admin);
    $expense = makeVendorReimbursementExpense($company, $sub, $merchant);
    $employee = User::query()->create([
        'first_name' => 'Emp',
        'last_name' => 'Loyee',
        'email' => 'employee-vp@example.com',
        'cell_phone' => '5551230001',
        'password' => bcrypt('password'),
        'primary_vendor_id' => $company->id,
    ]);
    $employee->vendors()->attach($company->id, ['role_id' => 2, 'is_employed' => 1]);

    // Paid By chosen FIRST, reimbursement ticked after → Paid By is cleared
    $component = Livewire::actingAs($admin)
        ->test(VendorPaymentCreate::class, ['vendor' => $sub])
        ->set('form.paid_by', $employee->id)
        ->set("selectedVendorReimbursementExpenses.{$expense->id}", true);

    expect($component->instance()->form->paid_by)->toBeNull();
});

it('disables reimbursement selection until a project is in the payment, and unchecks when the last project is removed', function () {
    [$company, $admin, $sub, $merchant, $bankAccount] = vendorReimbursementSetup();
    $this->actingAs($admin);
    $expense = makeVendorReimbursementExpense($company, $sub, $merchant);
    $project = makeProjectWithBid($company, $sub, 1000.00);

    $component = Livewire::actingAs($admin)
        ->test(VendorPaymentCreate::class, ['vendor' => $sub]);

    // Project is in the payment from mount but has NO amount yet → still gated
    expect($component->instance()->getHasPaymentProjectsProperty())->toBeFalse();

    // Funding the project opens the gate
    $component->set("projects.{$project->id}.amount", 500);
    expect($component->instance()->getHasPaymentProjectsProperty())->toBeTrue();

    // Select the reimbursement, then zero the project amount → unchecked
    $component->set("selectedVendorReimbursementExpenses.{$expense->id}", true)
        ->set("projects.{$project->id}.amount", null);

    $instance = $component->instance();
    expect($instance->getHasPaymentProjectsProperty())->toBeFalse()
        ->and($instance->selectedVendorReimbursementExpenses[$expense->id])->toBeFalse();

    // Same when the only project is removed after re-funding + re-selecting
    $component->set("projects.{$project->id}.amount", 500)
        ->set("selectedVendorReimbursementExpenses.{$expense->id}", true)
        ->call('removeProject', $project->id);

    $instance = $component->instance();
    expect($instance->getHasPaymentProjectsProperty())->toBeFalse()
        ->and($instance->selectedVendorReimbursementExpenses[$expense->id])->toBeFalse()
        ->and($instance->getVendorCheckSumProperty())->toEqual(0.0);
});

it('shows bank and check number / transfer in the confirm payment modal', function () {
    [$company, $admin, $sub, $merchant, $bankAccount] = vendorReimbursementSetup();
    $this->actingAs($admin);
    $project = makeProjectWithBid($company, $sub, 1000.00);

    // Seed one paper check so autoCheckNumber() (fired by check_type=Check) works
    Check::forceCreate([
        'check_type' => 'Check',
        'check_number' => 99000,
        'date' => '2026-07-01',
        'bank_account_id' => $bankAccount->id,
        'vendor_id' => $merchant->id,
        'belongs_to_vendor_id' => $company->id,
        'created_by_user_id' => $admin->id,
    ]);

    $component = Livewire::actingAs($admin)
        ->test(VendorPaymentCreate::class, ['vendor' => $sub])
        ->set('form.date', '2026-07-14')
        ->set('bank_account_id', $bankAccount->id)
        ->set('check_type', 'Check')
        ->set('check_number', 99123)
        ->set("projects.{$project->id}.amount", 930);

    $component->assertSee('Test Bank')->assertSee('Check #99123');

    $component->set('check_type', 'Transfer')
        ->assertSee('Test Bank')
        ->assertDontSee('Check #99123');
});
