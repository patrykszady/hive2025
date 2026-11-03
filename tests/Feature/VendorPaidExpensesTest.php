<?php

use App\Livewire\Checks\CheckShow;
use App\Livewire\Expenses\ExpenseCreate;
use App\Livewire\Expenses\ExpenseShow;
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
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * A sub paid for an expense out of pocket (2026-11-02: Stan Palupski bought
 * the basement windows for 3154 Violet, expense 29009, which the client
 * reimburses). "Paid By" on the expense form takes the sub, the company
 * owes it back, and /vendors/{id}/payment offers the expense as something
 * to pay the sub — added to the check, unlike the reimbursements it deducts.
 */

beforeEach(function () {
    config(['scout.driver' => 'collection']);
    \Illuminate\Support\Facades\Queue::fake();

    Livewire::component('bids.bid-create', VendorPaidChildStub::class);
    Livewire::component('vendor-docs.vendor-docs-card', VendorPaidChildStub::class);
    Livewire::component('vendor-docs.vendor-doc-create', VendorPaidChildStub::class);
});

class VendorPaidChildStub extends \Livewire\Component
{
    public function render()
    {
        return '<div></div>';
    }
}

/** @return array{0: Vendor, 1: User, 2: Vendor, 3: Vendor, 4: BankAccount} company, admin, sub, merchant, bank account */
function paidForSetup(): array
{
    $company = Vendor::factory()->create();
    $company->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();

    $admin = User::query()->create([
        'first_name' => 'Test',
        'last_name' => 'Admin',
        'email' => 'paid-for-admin@example.com',
        'cell_phone' => '5551230001',
        'password' => bcrypt('password'),
        'primary_vendor_id' => $company->id,
        'registration' => ['registered' => true],
    ]);
    $admin->vendors()->attach($company->id, ['role_id' => 1, 'is_employed' => 1]);

    $sub = Vendor::factory()->create();
    $sub->forceFill(['business_type' => 'Sub', 'business_name' => 'Stan Test Construction', 'business_email' => 'stan@example.com'])->save();
    $company->vendors()->attach($sub->id);

    $merchant = Vendor::factory()->create();
    $merchant->forceFill(['business_type' => 'Retail', 'business_name' => 'Climate Test Window'])->save();
    $company->vendors()->attach($merchant->id);

    $bank = Bank::query()->create(['name' => 'Test Bank', 'vendor_id' => $company->id, 'plaid_ins_id' => 'ins_test', 'plaid_access_token' => 'access-test-token']);
    $bankAccount = BankAccount::query()->create(['bank_id' => $bank->id, 'vendor_id' => $company->id, 'account_number' => 1234, 'plaid_account_id' => 'acct_test', 'type' => 'Checking']);

    return [$company, $admin, $sub, $merchant, $bankAccount];
}

function paidForProject(Vendor $company, Vendor $sub, float $bidAmount = 1000.00): Project
{
    $client = Client::factory()->create();
    $client->vendors()->attach($company->id);
    $project = Project::factory()->create(['belongs_to_vendor_id' => $company->id, 'client_id' => $client->id]);
    $project->vendors()->attach($company->id, ['client_id' => $client->id]);
    ProjectStatus::forceCreate(['project_id' => $project->id, 'belongs_to_vendor_id' => $company->id, 'status_code' => 6, 'start_date' => now()->addDay()]);
    $project->bids()->create(['vendor_id' => $sub->id, 'amount' => $bidAmount, 'type' => 'Labor']);

    return $project;
}

/** The windows: bought from the merchant, paid by the sub, reimbursed by the client. */
function paidForExpense(Vendor $company, Vendor $sub, Vendor $merchant, ?Project $project = null, float $amount = 1022.90): Expense
{
    return Expense::forceCreate([
        'date' => '2026-11-02',
        'amount' => $amount,
        'vendor_id' => $merchant->id,
        'project_id' => $project?->id,
        'paid_by_vendor_id' => $sub->id,
        'reimbursment' => 'Client',
        'note' => 'basement windows',
        'belongs_to_vendor_id' => $company->id,
        'created_by_user_id' => 0,
    ]);
}

function paidForExpenseComponent(User $admin): ExpenseCreate
{
    return Livewire::actingAs($admin)->test(ExpenseCreate::class, ['embedded' => true])->instance();
}

it('stores a sub as the payer from the Paid By select and records no check', function () {
    [$company, $admin, $sub, $merchant] = paidForSetup();
    $this->actingAs($admin);
    $project = paidForProject($company, $sub);

    $component = paidForExpenseComponent($admin);
    $component->form->amount = '1022.90';
    $component->form->date = '2026-11-02';
    $component->form->vendor_id = $merchant->id;
    $component->form->project_id = $project->id;
    $component->form->reimbursment = 'Client';
    $component->form->paid_by = 'V:'.$sub->id;

    $expense = $component->form->store();

    expect($expense->paid_by_vendor_id)->toBe($sub->id)
        ->and($expense->paid_by)->toBeNull()
        ->and($expense->check_id)->toBeNull()
        ->and($expense->getRawOriginal('reimbursment'))->toBe('Client')
        ->and($expense->paidByVendor->id)->toBe($sub->id)
        ->and($expense->paidBySelectValue())->toBe('V:'.$sub->id)
        ->and(Check::withoutGlobalScopes()->count())->toBe(0);
});

it('offers the subs under Paid By and preselects the sub when editing', function () {
    [$company, $admin, $sub, $merchant] = paidForSetup();
    $this->actingAs($admin);
    $expense = paidForExpense($company, $sub, $merchant);

    $component = Livewire::actingAs($admin)->test(ExpenseCreate::class, ['embedded' => true]);
    $component->instance()->editExpense($expense);

    expect($component->instance()->form->paid_by)->toBe('V:'.$sub->id);

    Livewire::actingAs($admin)->test(ExpenseCreate::class, ['embedded' => true])
        ->assertSeeHtml('value="V:'.$sub->id.'"')
        ->assertSee('Stan Test Construction');
});

it('refuses a sub that both paid for the expense and owes the company for it', function () {
    [$company, $admin, $sub, $merchant] = paidForSetup();
    $this->actingAs($admin);
    $project = paidForProject($company, $sub);

    $component = paidForExpenseComponent($admin);
    $component->form->amount = '50.00';
    $component->form->date = '2026-11-02';
    $component->form->vendor_id = $merchant->id;
    $component->form->project_id = $project->id;
    $component->form->paid_by = 'V:'.$sub->id;
    $component->form->reimbursment = 'V:'.$sub->id;

    expect(fn () => $component->form->store())->toThrow(ValidationException::class);
    expect(Expense::withoutGlobalScopes()->count())->toBe(0);
});

it('shows who paid on the expense page and where it gets paid back', function () {
    [$company, $admin, $sub, $merchant] = paidForSetup();
    $expense = paidForExpense($company, $sub, $merchant);

    Livewire::actingAs($admin)
        ->test(ExpenseShow::class, ['expense' => $expense])
        ->assertSee('Stan Test Construction')
        ->assertSee('To be paid back on their next vendor payment')
        ->assertSeeHtml(route('vendors.payment', $sub->id));
});

it('lists what the sub paid for on its payment page and adds the selected ones to the check', function () {
    [$company, $admin, $sub, $merchant] = paidForSetup();
    $this->actingAs($admin);
    $project = paidForProject($company, $sub);
    $expense = paidForExpense($company, $sub, $merchant, $project);

    $component = Livewire::actingAs($admin)
        ->test(VendorPaymentCreate::class, ['vendor' => $sub])
        ->assertSee('paid for Expenses')
        ->assertSee('1,022.90')
        ->assertSee('Climate Test Window');

    // Unselected by default — nothing to pay yet
    expect($component->instance()->getVendorCheckSumProperty())->toEqual(0.0)
        ->and($component->instance()->getHasPaymentProjectsProperty())->toBeFalse();

    // Selecting it is a payment on its own: no project amount needed
    $component->set("selectedVendorPaidExpenses.{$expense->id}", true);
    expect($component->instance()->getVendorCheckSumProperty())->toEqual(1022.90)
        ->and($component->instance()->getHasPaymentProjectsProperty())->toBeTrue()
        ->and($component->instance()->getDisablePaidByProperty())->toBeTrue();

    // A project amount stacks on top
    $component->set("projects.{$project->id}.amount", 500);
    expect($component->instance()->getVendorCheckSumProperty())->toEqual(1522.90);
});

it('pays the sub back on save: the check carries the expense, which links to it and keeps its client reimbursement', function () {
    [$company, $admin, $sub, $merchant, $bankAccount] = paidForSetup();
    $this->actingAs($admin);
    $project = paidForProject($company, $sub);
    $expense = paidForExpense($company, $sub, $merchant, $project);
    $owed = Expense::forceCreate([
        'date' => '2026-06-23', 'amount' => 70.00, 'vendor_id' => $merchant->id, 'reimbursment' => 'V:'.$sub->id,
        'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => 0,
    ]);

    Livewire::actingAs($admin)
        ->test(VendorPaymentCreate::class, ['vendor' => $sub])
        ->set('form.date', '2026-11-03')
        ->set('bank_account_id', $bankAccount->id)
        ->set('check_type', 'Cash')
        ->set("projects.{$project->id}.amount", 500)
        ->set("selectedVendorPaidExpenses.{$expense->id}", true)
        ->set("selectedVendorReimbursementExpenses.{$owed->id}", true)
        ->call('save')
        ->assertRedirect();

    $check = Check::withoutGlobalScopes()->where('vendor_id', $sub->id)->latest('id')->first();
    expect($check)->not->toBeNull()
        // 500 project payment + 1022.90 paid back − 70 owed
        ->and((float) $check->amount)->toEqual(1452.90);

    $expense->refresh();
    expect($expense->check_id)->toBe($check->id)
        ->and($expense->paid_by_vendor_id)->toBe($sub->id)
        ->and($expense->vendor_id)->toBe($merchant->id)
        ->and($expense->getRawOriginal('reimbursment'))->toBe('Client');

    // Paid back, so no longer offered on the next payment
    Livewire::actingAs($admin)
        ->test(VendorPaymentCreate::class, ['vendor' => $sub])
        ->assertDontSee('paid for Expenses');
});

it('pays a sub back with nothing else on the check', function () {
    [$company, $admin, $sub, $merchant, $bankAccount] = paidForSetup();
    $this->actingAs($admin);
    $expense = paidForExpense($company, $sub, $merchant);

    Livewire::actingAs($admin)
        ->test(VendorPaymentCreate::class, ['vendor' => $sub])
        ->set('form.date', '2026-11-03')
        ->set('bank_account_id', $bankAccount->id)
        ->set('check_type', 'Cash')
        ->set("selectedVendorPaidExpenses.{$expense->id}", true)
        ->call('save')
        ->assertRedirect();

    $check = Check::withoutGlobalScopes()->where('vendor_id', $sub->id)->latest('id')->first();
    expect((float) $check->amount)->toEqual(1022.90)
        ->and($expense->fresh()->check_id)->toBe($check->id)
        // The check pays back an existing expense; it creates none of its own
        ->and(Expense::withoutGlobalScopes()->count())->toBe(1);
});

it('clears Paid By when a paid-for expense is selected after it, and refuses the combination on save', function () {
    [$company, $admin, $sub, $merchant] = paidForSetup();
    $this->actingAs($admin);
    $expense = paidForExpense($company, $sub, $merchant);

    $component = Livewire::actingAs($admin)
        ->test(VendorPaymentCreate::class, ['vendor' => $sub])
        ->set('form.paid_by', $admin->id)
        ->set('form.invoice', 'INV-1')
        ->set("selectedVendorPaidExpenses.{$expense->id}", true);

    expect($component->get('form.paid_by'))->toBeNull()
        ->and($component->get('form.invoice'))->toBeNull();

    $component->instance()->form->paid_by = $admin->id;
    $component->instance()->form->invoice = 'INV-1';
    $component->instance()->form->date = '2026-11-03';

    expect(fn () => $component->instance()->form->store())->toThrow(ValidationException::class);
    expect($expense->fresh()->check_id)->toBeNull();
});

it('shows what the sub paid for on the check page, apart from what the check pays for', function () {
    [$company, $admin, $sub, $merchant, $bankAccount] = paidForSetup();
    $expense = paidForExpense($company, $sub, $merchant);

    $check = Check::forceCreate([
        'check_type' => 'Cash', 'date' => '2026-11-03', 'bank_account_id' => $bankAccount->id,
        'vendor_id' => $sub->id, 'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => $admin->id,
    ]);
    $expense->forceFill(['check_id' => $check->id])->save();
    $check->recalculateAmount();

    expect((float) $check->amount)->toEqual(1022.90);

    Livewire::actingAs($admin)
        ->test(CheckShow::class, ['check' => $check])
        ->assertSee('paid for these Expenses')
        ->assertSee('Climate Test Window');
});

it('unlinks a paid-back expense when its check is deleted instead of deleting it', function () {
    [$company, $admin, $sub, $merchant, $bankAccount] = paidForSetup();
    $this->actingAs($admin);
    $expense = paidForExpense($company, $sub, $merchant);

    $check = Check::forceCreate([
        'check_type' => 'Cash', 'date' => '2026-11-03', 'bank_account_id' => $bankAccount->id,
        'vendor_id' => $sub->id, 'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => $admin->id,
    ]);
    $expense->forceFill(['check_id' => $check->id])->save();

    $check->delete();

    $expense->refresh();
    expect($expense->deleted_at)->toBeNull()
        ->and($expense->check_id)->toBeNull()
        ->and($expense->paid_by_vendor_id)->toBe($sub->id);
});

it('keeps a paid-back expense on the sub\'s check when the expense is edited', function () {
    [$company, $admin, $sub, $merchant, $bankAccount] = paidForSetup();
    $this->actingAs($admin);
    $project = paidForProject($company, $sub);
    $expense = paidForExpense($company, $sub, $merchant, $project);
    $check = Check::forceCreate([
        'check_type' => 'Cash', 'date' => '2026-11-03', 'bank_account_id' => $bankAccount->id,
        'vendor_id' => $sub->id, 'belongs_to_vendor_id' => $company->id, 'created_by_user_id' => $admin->id,
    ]);
    $expense->forceFill(['check_id' => $check->id])->save();
    $check->recalculateAmount();

    $component = paidForExpenseComponent($admin);
    $component->editExpense($expense);
    $component->form->note = 'basement windows, invoice 68766';
    $component->form->update();

    $expense->refresh();
    expect($expense->note)->toBe('basement windows, invoice 68766')
        ->and($expense->check_id)->toBe($check->id)
        ->and($expense->paid_by_vendor_id)->toBe($sub->id)
        ->and((float) $check->fresh()->amount)->toEqual(1022.90);
});
