<?php

use App\Models\BankAccount;
use App\Models\Check;
use App\Models\Client;
use App\Models\Distribution;
use App\Models\Expense;
use App\Models\ExpenseSplits;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/**
 * Proves the app API's expense write path matches the web's
 * App\Livewire\Forms\ExpenseForm::save_splits()/store()/update() exactly
 * for the gaps closed in this round:
 *  - editing a split expense updates existing ExpenseSplits rows IN PLACE
 *    (same id, preserved receipt_items) and only deletes/creates what
 *    actually changed, via the shared App\Services\Expenses\
 *    ExpenseSplitsWriter — not AppExpenseDirectory's old delete-everything
 *    replaceSplits();
 *  - distribution expenses (project_id "D:{id}" on the web) round-trip
 *    through the app's own project_id/distribution_id fields end to end;
 *  - a failed receipt OCR surfaces as a warning instead of being silently
 *    dropped;
 *  - paid_by/reimbursement are restricted server-side the same way the
 *    web's own selects are, and an omitted split reimbursement is stored
 *    as 'None' (matching ExpenseSplitsCreate's own default), not null.
 */
function splitsFxTeam(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);

    $admin = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($admin->id, ['role_id' => 1]);

    return compact('vendor', 'admin');
}

function splitsFxProject(Vendor $vendor, array $overrides = []): Project
{
    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create(array_merge([
        'project_name' => 'Kitchen Remodel',
        'client_id' => $client->id,
        'address' => '123 Main St',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => '60062',
        'belongs_to_vendor_id' => $vendor->id,
    ], $overrides)));

    $project->vendors()->attach($vendor->id, ['client_id' => $client->id]);

    return $project;
}

function splitsFxDistribution(Vendor $vendor, array $overrides = []): Distribution
{
    return Distribution::create(array_merge([
        'name' => 'Owner Draw',
        'vendor_id' => $vendor->id,
        'user_id' => null,
    ], $overrides));
}

function splitsFxExpense(Vendor $vendor, array $overrides = []): Expense
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

// --- split sync: update in place, preserve receipt_items, delete only what's gone ---

it('updates existing splits in place by id, preserving receipt_items the app never sends', function () {
    ['vendor' => $vendor, 'admin' => $admin] = splitsFxTeam();
    $projectA = splitsFxProject($vendor, ['project_name' => 'Job A']);
    $projectB = splitsFxProject($vendor, ['project_name' => 'Job B']);
    $expense = splitsFxExpense($vendor, ['project_id' => null, 'amount' => 100]);

    $splitA = ExpenseSplits::create([
        'expense_id' => $expense->id, 'amount' => 60, 'project_id' => $projectA->id,
        'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $admin->id,
        'receipt_items' => ['merchant_name' => 'Home Depot', 'items' => [['Description' => 'Lumber']]],
    ]);
    $splitB = ExpenseSplits::create([
        'expense_id' => $expense->id, 'amount' => 40, 'project_id' => $projectB->id,
        'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $admin->id,
    ]);

    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/expenses/'.$expense->id, [
        'amount' => '100.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $expense->vendor_id,
        'splits' => [
            ['id' => $splitA->id, 'amount' => 70, 'project_id' => $projectA->id, 'note' => 'Updated A'],
            ['id' => $splitB->id, 'amount' => 30, 'project_id' => $projectB->id],
        ],
    ])->assertOk();

    // Same row ids — not deleted and recreated.
    expect(ExpenseSplits::whereKey($splitA->id)->exists())->toBeTrue()
        ->and(ExpenseSplits::whereKey($splitB->id)->exists())->toBeTrue()
        ->and($expense->splits()->count())->toBe(2);

    $splitA->refresh();
    expect((float) $splitA->amount)->toBe(70.0)
        ->and($splitA->note)->toBe('Updated A')
        // receipt_items survives even though the app's request carried no
        // 'items' key at all for this split.
        ->and($splitA->receipt_items['merchant_name'])->toBe('Home Depot')
        ->and($splitA->receipt_items['items'][0]['Description'])->toBe('Lumber')
        // belongs_to_vendor_id is the acting (hub) vendor, not some other
        // owner vendor — ExpenseForm::save_splits()'s own rule.
        ->and((int) $splitA->belongs_to_vendor_id)->toBe($vendor->id);
});

it('creates new splits and deletes any no longer submitted, on the same save', function () {
    ['vendor' => $vendor, 'admin' => $admin] = splitsFxTeam();
    $projectA = splitsFxProject($vendor, ['project_name' => 'Job A']);
    $projectB = splitsFxProject($vendor, ['project_name' => 'Job B']);
    $projectC = splitsFxProject($vendor, ['project_name' => 'Job C']);
    $expense = splitsFxExpense($vendor, ['project_id' => null, 'amount' => 100]);

    $splitA = ExpenseSplits::create(['expense_id' => $expense->id, 'amount' => 50, 'project_id' => $projectA->id, 'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $admin->id]);
    $splitB = ExpenseSplits::create(['expense_id' => $expense->id, 'amount' => 50, 'project_id' => $projectB->id, 'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $admin->id]);

    Sanctum::actingAs($admin, ['app']);

    // B is dropped, A is kept (same id), C is brand new.
    $this->postJson('/api/app/v1/expenses/'.$expense->id, [
        'amount' => '100.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $expense->vendor_id,
        'splits' => [
            ['id' => $splitA->id, 'amount' => 40, 'project_id' => $projectA->id],
            ['amount' => 60, 'project_id' => $projectC->id],
        ],
    ])->assertOk();

    expect(ExpenseSplits::whereKey($splitA->id)->exists())->toBeTrue()
        ->and(ExpenseSplits::whereKey($splitB->id)->exists())->toBeFalse()
        ->and($expense->splits()->count())->toBe(2)
        ->and($expense->splits()->where('project_id', $projectC->id)->exists())->toBeTrue();
});

it('refuses a split id that belongs to a different expense', function () {
    ['vendor' => $vendor, 'admin' => $admin] = splitsFxTeam();
    $projectA = splitsFxProject($vendor);
    $expenseOne = splitsFxExpense($vendor, ['project_id' => null, 'amount' => 50]);
    $expenseTwo = splitsFxExpense($vendor, ['project_id' => null, 'amount' => 50]);
    $foreignSplit = ExpenseSplits::create(['expense_id' => $expenseTwo->id, 'amount' => 50, 'project_id' => $projectA->id, 'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $admin->id]);

    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/expenses/'.$expenseOne->id, [
        'amount' => '50.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $expenseOne->vendor_id,
        'splits' => [
            ['id' => $foreignSplit->id, 'amount' => 50, 'project_id' => $projectA->id],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors(['splits.0.id']);

    // The foreign split was never touched.
    expect($foreignSplit->refresh()->expense_id)->toBe($expenseTwo->id);
});

it('defaults an omitted split reimbursement to "None", matching the web split editor', function () {
    ['vendor' => $vendor, 'admin' => $admin] = splitsFxTeam();
    $projectA = splitsFxProject($vendor);
    $projectB = splitsFxProject($vendor);
    $merchant = Vendor::factory()->create();

    Sanctum::actingAs($admin, ['app']);

    $response = $this->postJson('/api/app/v1/expenses', [
        'amount' => '100.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'splits' => [
            ['amount' => 60, 'project_id' => $projectA->id],
            ['amount' => 40, 'project_id' => $projectB->id, 'reimbursement' => 'Client'],
        ],
    ])->assertCreated();

    $expense = Expense::findOrFail($response->json('data.id'));
    expect($expense->splits->firstWhere('project_id', $projectA->id)->getRawOriginal('reimbursment'))->toBe('None')
        ->and($expense->splits->firstWhere('project_id', $projectB->id)->getRawOriginal('reimbursment'))->toBe('Client');
});

it('refuses a split reimbursement value outside None/Client', function () {
    ['vendor' => $vendor, 'admin' => $admin] = splitsFxTeam();
    $projectA = splitsFxProject($vendor);
    $projectB = splitsFxProject($vendor);
    $merchant = Vendor::factory()->create();

    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/expenses', [
        'amount' => '100.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'splits' => [
            ['amount' => 60, 'project_id' => $projectA->id, 'reimbursement' => 'V:12'],
            ['amount' => 40, 'project_id' => $projectB->id],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors(['splits.0.reimbursement']);
});

it('requires exactly one of project_id/distribution_id per split', function () {
    ['vendor' => $vendor, 'admin' => $admin] = splitsFxTeam();
    $project = splitsFxProject($vendor);
    $distribution = splitsFxDistribution($vendor);
    $merchant = Vendor::factory()->create();

    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/expenses', [
        'amount' => '100.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'splits' => [
            ['amount' => 100],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors(['splits.0.project_id']);

    $this->postJson('/api/app/v1/expenses', [
        'amount' => '100.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'splits' => [
            ['amount' => 100, 'project_id' => $project->id, 'distribution_id' => $distribution->id],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors(['splits.0.project_id']);
});

// --- distribution expenses round-trip end to end ----------------------------

it('creates a distribution expense, like the web\'s "D:{id}" project selector', function () {
    ['vendor' => $vendor, 'admin' => $admin] = splitsFxTeam();
    $distribution = splitsFxDistribution($vendor);
    $merchant = Vendor::factory()->create();

    Sanctum::actingAs($admin, ['app']);

    $response = $this->postJson('/api/app/v1/expenses', [
        'amount' => '40.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'distribution_id' => $distribution->id,
    ])->assertCreated();

    $expense = Expense::findOrFail($response->json('data.id'));
    expect($expense->project_id)->toBeNull()
        ->and((int) $expense->distribution_id)->toBe($distribution->id)
        // 'project' keeps carrying the distribution's NAME for backward
        // compatibility with the list row's display (its `id` stays null —
        // it is not a real project), while the new 'distribution' field
        // below carries the real id an edit form needs to round-trip it.
        ->and($response->json('data.project.id'))->toBeNull()
        ->and($response->json('data.project.name'))->toBe($distribution->name)
        ->and($response->json('data.distribution.id'))->toBe($distribution->id)
        ->and($response->json('data.distribution.name'))->toBe($distribution->name);
});

it('edits a distribution expense without forcing it onto a plain project', function () {
    ['vendor' => $vendor, 'admin' => $admin] = splitsFxTeam();
    $distribution = splitsFxDistribution($vendor);
    $merchant = Vendor::factory()->create();
    $expense = splitsFxExpense($vendor, ['project_id' => null, 'distribution_id' => $distribution->id, 'amount' => 40, 'vendor_id' => $merchant->id]);

    Sanctum::actingAs($admin, ['app']);

    // The app's edit form round-trips distribution_id back unchanged while
    // editing an unrelated field (note) — it must not silently reclassify
    // the expense onto a project (distribution_id => null) just because
    // the request shape is "project_id or nothing".
    $this->postJson('/api/app/v1/expenses/'.$expense->id, [
        'amount' => '45.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'distribution_id' => $distribution->id, 'note' => 'Edited',
    ])->assertOk();

    $expense->refresh();
    expect((float) $expense->amount)->toBe(45.0)
        ->and($expense->note)->toBe('Edited')
        ->and((int) $expense->distribution_id)->toBe($distribution->id)
        ->and($expense->project_id)->toBeNull();
});

it('refuses both a project and a distribution on the same expense', function () {
    ['vendor' => $vendor, 'admin' => $admin] = splitsFxTeam();
    $project = splitsFxProject($vendor);
    $distribution = splitsFxDistribution($vendor);
    $merchant = Vendor::factory()->create();

    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/expenses', [
        'amount' => '40.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'project_id' => $project->id, 'distribution_id' => $distribution->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['project_id']);
});

it('refuses a distribution outside the tenant', function () {
    ['admin' => $admin] = splitsFxTeam();
    $otherVendor = Vendor::factory()->create();
    $foreignDistribution = splitsFxDistribution($otherVendor);
    $merchant = Vendor::factory()->create();

    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/expenses', [
        'amount' => '40.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'distribution_id' => $foreignDistribution->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['project_id']);
});

it('records a new check with the distribution\'s own user_id, like ExpenseForm::store()', function () {
    ['vendor' => $vendor, 'admin' => $admin] = splitsFxTeam();
    $distUser = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($distUser->id, ['role_id' => 2]);
    $distribution = splitsFxDistribution($vendor, ['user_id' => $distUser->id]);
    $merchant = Vendor::factory()->create();
    $bank = \App\Models\Bank::create(['name' => 'Citibank', 'vendor_id' => $vendor->id, 'plaid_ins_id' => 'ins_'.uniqid()]);
    $account = BankAccount::create(['vendor_id' => $vendor->id, 'bank_id' => $bank->id, 'account_number' => '1234', 'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'Checking']);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->postJson('/api/app/v1/expenses', [
        'amount' => '40.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'distribution_id' => $distribution->id,
        'bank_account_id' => $account->id, 'check_type' => 'Check', 'check_number' => '910',
    ])->assertCreated();

    $expense = Expense::findOrFail($response->json('data.id'));
    $check = Check::findOrFail($expense->check_id);
    expect((int) $check->user_id)->toBe($distUser->id);
});

// --- paid_by / reimbursement server-side whitelisting -----------------------

it('refuses paid_by outside the hub\'s own employed team', function () {
    ['vendor' => $vendor, 'admin' => $admin] = splitsFxTeam();
    $project = splitsFxProject($vendor);
    $merchant = Vendor::factory()->create();
    $outsider = User::factory()->create();

    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/expenses', [
        'amount' => '10.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'project_id' => $project->id, 'paid_by' => $outsider->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['paid_by']);
});

it('refuses a reimbursement value that is not Client/an employee/a Sub-1099 vendor', function () {
    ['vendor' => $vendor, 'admin' => $admin] = splitsFxTeam();
    $project = splitsFxProject($vendor);
    $merchant = Vendor::factory()->create();
    $retailVendor = Vendor::factory()->create(['business_type' => 'Retail']);
    $vendor->vendors()->attach($retailVendor->id);

    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/expenses', [
        'amount' => '10.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'project_id' => $project->id, 'reimbursement' => 'V:'.$retailVendor->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['reimbursement']);

    $this->postJson('/api/app/v1/expenses', [
        'amount' => '10.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'project_id' => $project->id, 'reimbursement' => 'not-a-real-value',
    ])->assertStatus(422)->assertJsonValidationErrors(['reimbursement']);
});

it('accepts a Sub/1099 vendor reimbursement the hub can see', function () {
    ['vendor' => $vendor, 'admin' => $admin] = splitsFxTeam();
    $project = splitsFxProject($vendor);
    $merchant = Vendor::factory()->create();
    $subVendor = Vendor::factory()->create(['business_type' => 'Sub']);
    $vendor->vendors()->attach($subVendor->id);

    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/expenses', [
        'amount' => '10.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'project_id' => $project->id, 'reimbursement' => 'V:'.$subVendor->id,
    ])->assertCreated();
});

// --- a failed receipt OCR is surfaced, not silently dropped -----------------

it('surfaces a failed receipt OCR as a warning on create, without losing the expense', function () {
    Storage::fake('files');
    ['vendor' => $vendor, 'admin' => $admin] = splitsFxTeam();
    $project = splitsFxProject($vendor);
    $merchant = Vendor::factory()->create();

    $this->partialMock(\App\Http\Controllers\ReceiptController::class, function ($mock) {
        $mock->shouldReceive('extractReceipt')->andReturn(['error' => true]);
    });

    Sanctum::actingAs($admin, ['app']);

    $response = $this->post('/api/app/v1/expenses', [
        'amount' => '20.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'project_id' => $project->id,
        'receipt' => UploadedFile::fake()->create('receipt.jpg', 10, 'image/jpeg'),
    ])->assertCreated();

    expect($response->json('receipt_warning'))->toBe('Expense saved but the receipt could not be processed. Please try uploading the receipt again.');

    $expense = Expense::findOrFail($response->json('data.id'));
    expect($expense->receipts()->count())->toBe(0);
});

it('surfaces a failed receipt OCR as a warning on update, without losing the expense edits', function () {
    Storage::fake('files');
    ['vendor' => $vendor, 'admin' => $admin] = splitsFxTeam();
    $project = splitsFxProject($vendor);
    $expense = splitsFxExpense($vendor, ['project_id' => $project->id, 'amount' => 20]);

    $this->partialMock(\App\Http\Controllers\ReceiptController::class, function ($mock) {
        $mock->shouldReceive('extractReceipt')->andReturn(['error' => true]);
    });

    Sanctum::actingAs($admin, ['app']);

    $response = $this->post('/api/app/v1/expenses/'.$expense->id, [
        'amount' => '25.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $expense->vendor_id,
        'project_id' => $project->id,
        'receipt' => UploadedFile::fake()->create('receipt.jpg', 10, 'image/jpeg'),
    ])->assertOk();

    expect($response->json('receipt_warning'))->toBe('Expense updated but the receipt could not be processed. Please try uploading the receipt again.');

    $expense->refresh();
    expect((float) $expense->amount)->toBe(25.0)
        ->and($expense->receipts()->count())->toBe(0);
});

it('has no receipt_warning when no receipt was attached', function () {
    ['vendor' => $vendor, 'admin' => $admin] = splitsFxTeam();
    $project = splitsFxProject($vendor);
    $merchant = Vendor::factory()->create();

    Sanctum::actingAs($admin, ['app']);

    $response = $this->postJson('/api/app/v1/expenses', [
        'amount' => '20.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'project_id' => $project->id,
    ])->assertCreated();

    expect($response->json('receipt_warning'))->toBeNull();
});
