<?php

use App\Livewire\Expenses\ExpenseCreate;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Client;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\HandleComponents;

uses(RefreshDatabase::class);

/**
 * /expenses?transaction_search=netfl: tick the month's Netflix charges on the
 * Transactions card and link them all to one project or distribution
 * (2026-10-21), instead of one Create Expense modal per charge.
 */
beforeEach(function (): void {
    config(['scout.driver' => null]);

    $this->company = Vendor::factory()->create(['business_name' => 'GS Test Co']);
    $this->company->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['primary_vendor_id' => $this->company->id, 'registration' => ['registered' => true]])->save();
    $this->company->users()->attach($this->admin->id, ['role_id' => 1]);

    $client = Client::factory()->create();
    $client->vendors()->attach($this->company->id);
    $this->project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Office', 'client_id' => $client->id, 'address' => '400 W Wheeling Rd',
        'city' => 'Prospect Heights', 'state' => 'IL', 'zip_code' => '60070', 'belongs_to_vendor_id' => $this->company->id,
    ]));
    $this->project->vendors()->attach($this->company->id, ['client_id' => $client->id]);
    $this->distribution = Distribution::forceCreate(['name' => 'OFFICE', 'vendor_id' => $this->company->id, 'user_id' => 0]);

    $bank = Bank::create(['name' => 'Capital One', 'vendor_id' => $this->company->id, 'plaid_ins_id' => 'ins_bulk_'.uniqid()]);
    $this->account = BankAccount::create(['vendor_id' => $this->company->id, 'bank_id' => $bank->id, 'account_number' => '4060', 'plaid_account_id' => 'acc_bulk_'.uniqid(), 'type' => 'Credit Card']);
    $this->netflix = Vendor::factory()->create(['business_name' => 'Netflix', 'business_type' => 'Retail']);
});

function bulk_charge(array $attrs): Transaction
{
    return Transaction::withoutGlobalScopes()->create(array_merge([
        'bank_account_id' => test()->account->id,
        'transaction_date' => '2026-10-02',
        'amount' => 24.99,
        'plaid_merchant_description' => 'NETFLIX.COM',
    ], $attrs));
}

/** Runs a component method the way a request would (Flux's modal() looks the component up). */
function bulk_call(ExpenseCreate $component, Closure $call): void
{
    HandleComponents::$componentStack[] = $component;

    try {
        $call($component);
    } finally {
        array_pop(HandleComponents::$componentStack);
    }
}

function bulk_component(): ExpenseCreate
{
    return Livewire::actingAs(test()->admin)->test(ExpenseCreate::class)->instance();
}

it('makes every ticked card charge an expense on the chosen project', function () {
    $september = bulk_charge(['vendor_id' => $this->netflix->id, 'transaction_date' => '2026-09-02']);
    $october = bulk_charge(['vendor_id' => $this->netflix->id, 'transaction_date' => '2026-10-02']);

    $component = bulk_component();
    bulk_call($component, fn ($c) => $c->linkTransactionsInBulk([$september->id, $october->id]));
    $component->bulkTransactionTarget = (string) $this->project->id;
    bulk_call($component, fn ($c) => $c->linkBulkTransactions());

    $expenses = Expense::withoutGlobalScopes()->where('vendor_id', $this->netflix->id)->orderBy('date')->get();

    expect($expenses)->toHaveCount(2)
        ->and($expenses->pluck('project_id')->unique()->all())->toBe([$this->project->id])
        ->and($expenses->map(fn ($e) => [$e->date->toDateString(), (float) $e->amount])->all())->toBe([['2026-09-02', 24.99], ['2026-10-02', 24.99]])
        ->and($expenses->pluck('belongs_to_vendor_id')->unique()->all())->toBe([$this->company->id])
        ->and($september->fresh()->expense_id)->toBe($expenses[0]->id)
        ->and($october->fresh()->expense_id)->toBe($expenses[1]->id)
        ->and($component->bulkTransactionIds)->toBe([]);
});

it('links them to a distribution', function () {
    $charge = bulk_charge(['vendor_id' => $this->netflix->id]);

    $component = bulk_component();
    bulk_call($component, fn ($c) => $c->linkTransactionsInBulk([$charge->id]));
    $component->bulkTransactionTarget = 'D:'.$this->distribution->id;
    bulk_call($component, fn ($c) => $c->linkBulkTransactions());

    expect($charge->fresh()->expense)->distribution_id->toBe($this->distribution->id)->project_id->toBeNull();
});

it('skips charges without a vendor, checks and charges already matched', function () {
    $noVendor = bulk_charge(['vendor_id' => null]);
    $check = bulk_charge(['vendor_id' => $this->netflix->id, 'check_number' => '2700', 'plaid_merchant_description' => 'CHECK 2700']);
    $matched = bulk_charge(['vendor_id' => $this->netflix->id]);
    $existing = Expense::query()->create(['amount' => 24.99, 'date' => '2026-10-02', 'vendor_id' => $this->netflix->id, 'belongs_to_vendor_id' => $this->company->id, 'created_by_user_id' => 0]);
    Transaction::withoutGlobalScopes()->whereKey($matched->id)->update(['expense_id' => $existing->id]);
    $good = bulk_charge(['vendor_id' => $this->netflix->id, 'transaction_date' => '2026-10-05']);

    $component = bulk_component();
    bulk_call($component, fn ($c) => $c->linkTransactionsInBulk([$noVendor->id, $check->id, $matched->id, $good->id]));
    $component->bulkTransactionTarget = (string) $this->project->id;
    bulk_call($component, fn ($c) => $c->linkBulkTransactions());

    expect($good->fresh()->expense_id)->not->toBeNull()
        ->and($noVendor->fresh()->expense_id)->toBeNull()
        ->and($check->fresh()->expense_id)->toBeNull()
        ->and($matched->fresh()->expense_id)->toBe($existing->id)
        ->and(Expense::withoutGlobalScopes()->count())->toBe(2);
});

it('wants a project or distribution', function () {
    $charge = bulk_charge(['vendor_id' => $this->netflix->id]);

    $component = bulk_component();
    bulk_call($component, fn ($c) => $c->linkTransactionsInBulk([$charge->id]));

    expect(fn () => bulk_call($component, fn ($c) => $c->linkBulkTransactions()))->toThrow(\Illuminate\Validation\ValidationException::class)
        ->and($charge->fresh()->expense_id)->toBeNull();
});
