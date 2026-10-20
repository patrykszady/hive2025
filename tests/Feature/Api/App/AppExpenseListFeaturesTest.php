<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Client;
use App\Models\Expense;
use App\Models\ExpenseSplits;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/** @return array{vendor: Vendor, admin: User, member: User} */
function appExpenseListTeam(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);

    $admin = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($admin->id, ['role_id' => 1]);

    $member = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($member->id, ['role_id' => 2]);

    return compact('vendor', 'admin', 'member');
}

function appExpenseListExpense(Vendor $vendor, array $overrides = []): Expense
{
    $merchant = Vendor::factory()->create(['business_name' => 'Home Depot']);

    return Expense::create(array_merge([
        'amount' => 100.00,
        'date' => now()->format('Y-m-d'),
        'vendor_id' => $merchant->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => 1,
    ], $overrides));
}

function appExpenseListProject(Vendor $vendor): Project
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

    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    return $project;
}

// --- bulk delete (ExpenseIndex::bulkDelete()) ------------------------------

it('needs a token for bulk delete', function () {
    $this->deleteJson('/api/app/v1/expenses/bulk', ['ids' => [1]])->assertUnauthorized();
});

it('bulk-deletes selected expenses an admin may delete', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseListTeam();
    $one = appExpenseListExpense($vendor);
    $two = appExpenseListExpense($vendor);
    $untouched = appExpenseListExpense($vendor);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->deleteJson('/api/app/v1/expenses/bulk', ['ids' => [$one->id, $two->id]])->assertOk();

    expect($response->json('data.deleted'))->toEqualCanonicalizing([$one->id, $two->id])
        ->and($response->json('data.skipped'))->toBe(0)
        ->and(Expense::withoutGlobalScopes()->whereKey($one->id)->first()->trashed())->toBeTrue()
        ->and(Expense::withoutGlobalScopes()->whereKey($two->id)->first()->trashed())->toBeTrue()
        ->and(Expense::withoutGlobalScopes()->whereKey($untouched->id)->first()->trashed())->toBeFalse();
});

it('skips a member\'s selected expense that already has a matched transaction, same guard as single delete', function () {
    ['vendor' => $vendor, 'member' => $member] = appExpenseListTeam();
    $deletable = appExpenseListExpense($vendor, ['paid_by' => $member->id]);
    $blocked = appExpenseListExpense($vendor, ['paid_by' => $member->id]);
    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $vendor->id, 'plaid_ins_id' => 'ins_'.uniqid()]);
    $account = BankAccount::create(['vendor_id' => $vendor->id, 'bank_id' => $bank->id, 'account_number' => '4903', 'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'checking']);
    Transaction::create([
        'transaction_date' => now()->format('Y-m-d'), 'amount' => $blocked->amount,
        'bank_account_id' => $account->id, 'expense_id' => $blocked->id, 'plaid_transaction_id' => 'tx_'.uniqid(),
    ]);

    Sanctum::actingAs($member, ['app']);

    $response = $this->deleteJson('/api/app/v1/expenses/bulk', ['ids' => [$deletable->id, $blocked->id]])->assertOk();

    expect($response->json('data.deleted'))->toBe([$deletable->id])
        ->and($response->json('data.skipped'))->toBe(1)
        ->and(Expense::withoutGlobalScopes()->whereKey($blocked->id)->first()->trashed())->toBeFalse();
});

it('validates that bulk delete needs a non-empty ids array', function () {
    ['admin' => $admin] = appExpenseListTeam();
    Sanctum::actingAs($admin, ['app']);

    $this->deleteJson('/api/app/v1/expenses/bulk', ['ids' => []])->assertStatus(422)->assertJsonValidationErrors(['ids']);
});

// --- trashed / restore (ExpenseIndex::isShowingTrashed()/restoreExpense()) --

it('the Deleted status filter lists only trashed expenses', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseListTeam();
    $active = appExpenseListExpense($vendor);
    $trashed = appExpenseListExpense($vendor);
    $trashed->delete();

    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson('/api/app/v1/expenses?status[]=Deleted')->assertOk();

    expect($response->json('data.*.id'))->toBe([$trashed->id])
        ->and($response->json('data.0.trashed'))->toBeTrue();

    // The default (no status filter) listing excludes it, same as the web's default view.
    $this->getJson('/api/app/v1/expenses')->assertOk()->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $active->id);
});

it('an admin can restore a trashed expense', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseListTeam();
    $expense = appExpenseListExpense($vendor);
    $expense->delete();

    Sanctum::actingAs($admin, ['app']);

    $response = $this->postJson('/api/app/v1/expenses/'.$expense->id.'/restore')->assertOk();

    expect($response->json('data.trashed'))->toBeFalse()
        ->and(Expense::withoutGlobalScopes()->whereKey($expense->id)->first()->trashed())->toBeFalse();
});

it('a member cannot restore a trashed expense', function () {
    ['vendor' => $vendor, 'member' => $member] = appExpenseListTeam();
    $expense = appExpenseListExpense($vendor, ['paid_by' => $member->id]);
    $expense->delete();

    Sanctum::actingAs($member, ['app']);

    $this->postJson('/api/app/v1/expenses/'.$expense->id.'/restore')->assertForbidden();

    expect(Expense::withoutGlobalScopes()->whereKey($expense->id)->first()->trashed())->toBeTrue();
});

it('404s restoring an expense that isn\'t trashed', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseListTeam();
    $expense = appExpenseListExpense($vendor);

    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/expenses/'.$expense->id.'/restore')->assertNotFound();
});

// --- reimbursement filter (ExpenseIndex::updatedReimbursementFilter()) ----

it('filters by reimbursement, matching either the expense\'s own value or a split\'s', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseListTeam();
    $projectA = appExpenseListProject($vendor);
    $clientReimbursed = appExpenseListExpense($vendor, ['reimbursment' => 'Client']);
    appExpenseListExpense($vendor);
    $splitReimbursed = appExpenseListExpense($vendor, ['project_id' => null]);
    ExpenseSplits::create(['expense_id' => $splitReimbursed->id, 'amount' => 100, 'project_id' => $projectA->id, 'reimbursment' => 'Client', 'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $admin->id]);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson('/api/app/v1/expenses?reimbursement=Client')->assertOk();

    expect(collect($response->json('data.*.id'))->sort()->values()->all())
        ->toBe(collect([$clientReimbursed->id, $splitReimbursed->id])->sort()->values()->all());
});

// --- sort (ExpenseIndex::sort()) — only the Date column is sortable on the web --

it('sorts by date ascending when asked, descending by default', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseListTeam();
    $older = appExpenseListExpense($vendor, ['date' => now()->subDays(5)->format('Y-m-d')]);
    $newer = appExpenseListExpense($vendor, ['date' => now()->format('Y-m-d')]);

    Sanctum::actingAs($admin, ['app']);

    expect($this->getJson('/api/app/v1/expenses')->json('data.*.id'))->toBe([$newer->id, $older->id])
        ->and($this->getJson('/api/app/v1/expenses?direction=asc')->json('data.*.id'))->toBe([$older->id, $newer->id]);
});

// --- split rows under their parent, like the web's table -------------------

it('includes lightweight split rows on the list, not just the detail payload', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseListTeam();
    $projectA = appExpenseListProject($vendor);
    $expense = appExpenseListExpense($vendor, ['project_id' => null]);
    ExpenseSplits::create(['expense_id' => $expense->id, 'amount' => 60, 'project_id' => $projectA->id, 'reimbursment' => 'Client', 'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $admin->id]);
    ExpenseSplits::create(['expense_id' => $expense->id, 'amount' => 40, 'project_id' => $projectA->id, 'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $admin->id]);
    appExpenseListExpense($vendor); // a plain, non-split expense — splits should be []

    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson('/api/app/v1/expenses')->assertOk();

    $splitRow = collect($response->json('data'))->firstWhere('id', $expense->id);
    expect($splitRow['is_split'])->toBeTrue()
        ->and($splitRow['splits'])->toHaveCount(2)
        ->and($splitRow['splits'][0]['reimbursement'])->toBe('Client');

    $plainRow = collect($response->json('data'))->firstWhere('id', '!=', $expense->id);
    expect($plainRow['splits'])->toBe([]);
});

// --- form-options: via_vendor_employees (ExpenseIndex::via_vendor_employees()) --

it('lists via_vendor_employees on the form-options endpoint, for the Reimbursement filter', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpenseListTeam();
    $viaVendor = Vendor::factory()->create();
    $hire = User::factory()->create(['primary_vendor_id' => $vendor->id, 'first_name' => 'Cezary']);
    $vendor->users()->attach($hire->id, ['role_id' => 2, 'is_employed' => 1, 'via_vendor_id' => $viaVendor->id]);
    $notViaVendor = User::factory()->create(['primary_vendor_id' => $vendor->id, 'first_name' => 'Greg']);
    $vendor->users()->attach($notViaVendor->id, ['role_id' => 2, 'is_employed' => 1]);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson('/api/app/v1/expenses/form-options')->assertOk();

    $names = collect($response->json('data.via_vendor_employees'))->pluck('name');
    expect($names)->toContain('Cezary')->not->toContain('Greg');
});
