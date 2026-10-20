<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Client;
use App\Models\Expense;
use App\Models\ExpenseReceipts;
use App\Models\ExpenseSplits;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ContentUnderstandingService;
use App\Services\Expenses\AppExpenseDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    // No real Meilisearch round trips from fixture setup — same reasoning
    // as AppProjectsTest/AppCallsTest.
    config(['scout.driver' => null]);
});

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * AppAuthTest's appTeamUser(). Returns an Admin (role_id 1, today Patryk/
 * Greg/Andzelina) and a Member (role_id 2, today Cezary) on that vendor.
 *
 * @return array{vendor: Vendor, admin: User, member: User}
 */
function appExpensesTeam(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);

    $admin = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($admin->id, ['role_id' => 1]);

    $member = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($member->id, ['role_id' => 2]);

    return compact('vendor', 'admin', 'member');
}

/** A project owned by $vendor and visible to it (ProjectScope), same pattern as AppProjectsTest's appProjectFixture(). */
function appExpensesProject(Vendor $vendor, array $overrides = []): Project
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

    if (! $client->vendors()->where('vendors.id', $vendor->id)->exists()) {
        $client->vendors()->attach($vendor->id);
    }

    return $project;
}

/** A plain expense belonging to $vendor's team (ExpenseScope's Admin visibility rule: belongs_to_vendor_id match, belongs_to_client_id null). */
function appExpensesExpense(Vendor $vendor, array $overrides = []): Expense
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

/** A bank + account + transaction matched to $expense, so canDelete()'s "has a matched transaction" rule has something real to check. @return Transaction */
function appExpensesMatchedTransaction(Vendor $vendor, Expense $expense): Transaction
{
    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $vendor->id, 'plaid_ins_id' => 'ins_'.uniqid()]);
    $account = BankAccount::create([
        'vendor_id' => $vendor->id, 'bank_id' => $bank->id, 'account_number' => '4903',
        'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'checking',
    ]);

    return Transaction::create([
        'transaction_date' => $expense->date->format('Y-m-d'),
        'amount' => $expense->amount,
        'bank_account_id' => $account->id,
        'expense_id' => $expense->id,
        'plaid_transaction_id' => 'tx_'.uniqid(),
    ]);
}

// --- index -------------------------------------------------------------

it('needs a token to list expenses', function () {
    $this->getJson('/api/app/v1/expenses')->assertUnauthorized();
});

it('lets an admin see the whole team\'s expenses, newest first', function () {
    ['vendor' => $vendor, 'admin' => $admin, 'member' => $member] = appExpensesTeam();
    Sanctum::actingAs($admin, ['app']);

    $older = appExpensesExpense($vendor, ['date' => now()->subDays(5)->format('Y-m-d'), 'paid_by' => $member->id]);
    $newer = appExpensesExpense($vendor, ['date' => now()->format('Y-m-d')]);

    $response = $this->getJson('/api/app/v1/expenses')->assertOk();

    expect($response->json('data.*.id'))->toBe([$newer->id, $older->id])
        ->and($response->json('meta.total'))->toBe(2);
});

it('limits a member to their own paid-by expenses', function () {
    ['vendor' => $vendor, 'member' => $member] = appExpensesTeam();
    $otherMember = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($otherMember->id, ['role_id' => 2]);

    $mine = appExpensesExpense($vendor, ['paid_by' => $member->id]);
    appExpensesExpense($vendor, ['paid_by' => $otherMember->id]);

    Sanctum::actingAs($member, ['app']);

    $response = $this->getJson('/api/app/v1/expenses')->assertOk();

    expect($response->json('data.*.id'))->toBe([$mine->id]);
});

it('filters by project', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpensesTeam();
    $project = appExpensesProject($vendor);
    $onProject = appExpensesExpense($vendor, ['project_id' => $project->id]);
    appExpensesExpense($vendor);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson('/api/app/v1/expenses?project='.$project->id)->assertOk();

    expect($response->json('data.*.id'))->toBe([$onProject->id]);
});

it('filters by vendor', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpensesTeam();
    $merchant = Vendor::factory()->create(['business_name' => 'Menards']);
    $match = appExpensesExpense($vendor, ['vendor_id' => $merchant->id]);
    appExpensesExpense($vendor);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson('/api/app/v1/expenses?vendor='.$merchant->id)->assertOk();

    expect($response->json('data.*.id'))->toBe([$match->id]);
});

it('filters by status, matching Expense::calculateStatus()\'s rules', function () {
    ['vendor' => $vendor, 'admin' => $admin, 'member' => $member] = appExpensesTeam();
    $project = appExpensesProject($vendor);

    $missingInfo = appExpensesExpense($vendor, ['project_id' => null]);
    $noProject = appExpensesExpense($vendor, ['project_id' => null, 'paid_by' => $member->id]);
    $noTransaction = appExpensesExpense($vendor, ['project_id' => $project->id]);
    $complete = appExpensesExpense($vendor, ['project_id' => $project->id, 'paid_by' => $member->id]);

    Sanctum::actingAs($admin, ['app']);

    expect($this->getJson('/api/app/v1/expenses?status[]=Missing Info')->json('data.*.id'))->toBe([$missingInfo->id])
        ->and($this->getJson('/api/app/v1/expenses?status[]=No Project')->json('data.*.id'))->toBe([$noProject->id])
        ->and($this->getJson('/api/app/v1/expenses?status[]=No Transaction')->json('data.*.id'))->toBe([$noTransaction->id])
        ->and($this->getJson('/api/app/v1/expenses?status[]=Complete')->json('data.*.id'))->toBe([$complete->id]);
});

it('filters by amount, exact for two decimals and prefix otherwise', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpensesTeam();
    $exact = appExpensesExpense($vendor, ['amount' => 112.20]);
    // "579.61" starts with the digits "57", same as "57.00" does — a bare
    // "57" is a PREFIX search (like the web's amountPrefixRanges()), so
    // both are expected to match it; "570" must not match either.
    $alsoPrefixed = appExpensesExpense($vendor, ['amount' => 579.61]);
    $prefixed = appExpensesExpense($vendor, ['amount' => 57.00]);

    Sanctum::actingAs($admin, ['app']);

    expect($this->getJson('/api/app/v1/expenses?amount=112.20')->json('data.*.id'))->toBe([$exact->id])
        ->and(collect($this->getJson('/api/app/v1/expenses?amount=57')->json('data.*.id'))->sort()->values()->all())
        ->toBe(collect([$alsoPrefixed->id, $prefixed->id])->sort()->values()->all())
        ->and($this->getJson('/api/app/v1/expenses?amount=570')->json('data.*.id'))->toBe([]);
});

// --- show ----------------------------------------------------------------

it('404s an unknown expense and 403s one outside the viewer\'s access', function () {
    ['vendor' => $vendor, 'admin' => $admin, 'member' => $member] = appExpensesTeam();
    $notMine = appExpensesExpense($vendor, ['paid_by' => $admin->id]);

    Sanctum::actingAs($member, ['app']);

    $this->getJson('/api/app/v1/expenses/999999999')->assertNotFound();
    $this->getJson('/api/app/v1/expenses/'.$notMine->id)->assertForbidden();
});

it('shows the full detail shape, including splits', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpensesTeam();
    $projectA = appExpensesProject($vendor, ['project_name' => 'Job A']);
    $projectB = appExpensesProject($vendor, ['project_name' => 'Job B']);
    $expense = appExpensesExpense($vendor, ['project_id' => null, 'note' => 'Split material run']);
    ExpenseSplits::create(['expense_id' => $expense->id, 'amount' => 60, 'project_id' => $projectA->id, 'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $admin->id]);
    ExpenseSplits::create(['expense_id' => $expense->id, 'amount' => 40, 'project_id' => $projectB->id, 'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $admin->id]);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson('/api/app/v1/expenses/'.$expense->id)->assertOk();

    $response->assertJsonPath('data.id', $expense->id)
        ->assertJsonPath('data.note', 'Split material run')
        ->assertJsonPath('data.is_split', true)
        ->assertJsonCount(2, 'data.splits')
        ->assertJsonStructure([
            'data' => [
                'id', 'amount', 'date', 'vendor', 'project', 'is_split', 'status', 'status_color',
                'invoice', 'note', 'reimbursement', 'has_receipt', 'web_path',
                'paid_by', 'created_by', 'splits', 'receipts',
            ],
        ]);
});

it('shows the matched bank transaction, like the web\'s Transaction card', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpensesTeam();
    $expense = appExpensesExpense($vendor, ['amount' => 20.00]);
    $transaction = appExpensesMatchedTransaction($vendor, $expense);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson('/api/app/v1/expenses/'.$expense->id)->assertOk();

    $response->assertJsonCount(1, 'data.transactions')
        ->assertJsonPath('data.transactions.0.id', $transaction->id)
        ->assertJsonPath('data.transactions.0.bank', 'Citibank');

    expect((string) $response->json('data.transactions.0.account'))->toBe('4903')
        ->and((float) $response->json('data.transactions.0.amount'))->toBe(20.0);
});

it('lists no transactions when nothing has matched yet', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpensesTeam();
    $expense = appExpensesExpense($vendor);

    Sanctum::actingAs($admin, ['app']);

    $this->getJson('/api/app/v1/expenses/'.$expense->id)->assertOk()->assertJsonCount(0, 'data.transactions');
});

// --- store -----------------------------------------------------------------

it('only an admin can create an expense', function () {
    ['vendor' => $vendor, 'member' => $member] = appExpensesTeam();
    $merchant = Vendor::factory()->create();

    Sanctum::actingAs($member, ['app']);

    $this->postJson('/api/app/v1/expenses', [
        'amount' => '75.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id, 'project_id' => null,
    ])->assertForbidden();

    expect(Expense::withoutGlobalScopes()->count())->toBe(0);
});

it('creates an expense on a project', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpensesTeam();
    $project = appExpensesProject($vendor);
    $merchant = Vendor::factory()->create(['business_name' => 'Ace Hardware']);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->postJson('/api/app/v1/expenses', [
        'amount' => '75.50', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'project_id' => $project->id, 'note' => 'Hinges',
    ])->assertCreated();

    $expense = Expense::findOrFail($response->json('data.id'));
    expect((float) $expense->amount)->toBe(75.50)
        ->and((int) $expense->project_id)->toBe($project->id)
        ->and((int) $expense->belongs_to_vendor_id)->toBe($vendor->id)
        ->and((int) $expense->created_by_user_id)->toBe($admin->id);
});

it('requires a project unless the expense is split', function () {
    ['admin' => $admin] = appExpensesTeam();
    $merchant = Vendor::factory()->create();

    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/expenses', [
        'amount' => '10.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['project_id']);
});

it('requires split amounts to add up to the expense amount', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpensesTeam();
    $projectA = appExpensesProject($vendor, ['project_name' => 'A']);
    $projectB = appExpensesProject($vendor, ['project_name' => 'B']);
    $merchant = Vendor::factory()->create();

    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/expenses', [
        'amount' => '100.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'splits' => [
            ['amount' => 60, 'project_id' => $projectA->id],
            ['amount' => 30, 'project_id' => $projectB->id],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors(['splits']);

    $response = $this->postJson('/api/app/v1/expenses', [
        'amount' => '100.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'splits' => [
            ['amount' => 60, 'project_id' => $projectA->id],
            ['amount' => 40, 'project_id' => $projectB->id],
        ],
    ])->assertCreated();

    $expense = Expense::findOrFail($response->json('data.id'));
    expect($expense->project_id)->toBeNull()
        ->and($expense->splits)->toHaveCount(2)
        ->and((float) $expense->splits->sum('amount'))->toBe(100.0);
});

it('refuses a project outside the tenant', function () {
    ['admin' => $admin] = appExpensesTeam();
    $otherVendor = Vendor::factory()->create();
    $foreignProject = appExpensesProject($otherVendor);
    $merchant = Vendor::factory()->create();

    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/expenses', [
        'amount' => '10.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'project_id' => $foreignProject->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['project_id']);

    expect(Expense::withoutGlobalScopes()->where('project_id', $foreignProject->id)->count())->toBe(0);
});

it('attaches a receipt via the shared OCR/save path (AppExpenseDirectory::attachReceiptFromUpload)', function () {
    Storage::fake('files');
    ['vendor' => $vendor] = appExpensesTeam();
    $expense = appExpensesExpense($vendor, ['amount' => 143.21]);

    $mock = Mockery::mock(ContentUnderstandingService::class);
    $mock->shouldReceive('analyze')->once()->andReturn([
        'analyzeResult' => [
            'documents' => [[
                'fields' => [
                    'MerchantName' => ['valueString' => 'MENARDS'],
                    'Total' => ['valueNumber' => 143.21],
                ],
            ]],
            'content' => "MENARDS\nTotal\n\$143.21\n",
            'styles' => [],
        ],
    ]);
    app()->instance(ContentUnderstandingService::class, $mock);

    $file = UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf');

    $saved = app(AppExpenseDirectory::class)->attachReceiptFromUpload($expense, $file, false);

    expect($saved)->toBeTrue()
        ->and(ExpenseReceipts::where('expense_id', $expense->id)->count())->toBe(1);
});

it('creates a material-order expense attributed to another vendor, like ExpenseForm::store()', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpensesTeam();
    $project = appExpensesProject($vendor);
    $merchant = Vendor::factory()->create();
    $ownerVendor = Vendor::factory()->create();
    $vendor->vendors()->attach($ownerVendor->id);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->postJson('/api/app/v1/expenses', [
        'amount' => '50.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'project_id' => $project->id, 'is_material_order' => true, 'belongs_to_vendor_id' => $ownerVendor->id,
    ])->assertCreated();

    // belongs_to_vendor_id is now the OTHER vendor, not the hub — Expense::findOrFail()
    // is scoped by ExpenseScope (Admin sees belongs_to_vendor_id === their own vendor
    // only), so this expense is correctly invisible to the hub admin from here on.
    $expense = Expense::withoutGlobalScopes()->findOrFail($response->json('data.id'));
    expect((int) $expense->belongs_to_vendor_id)->toBe($ownerVendor->id);
});

it('refuses a material order attributed to an unrelated vendor', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpensesTeam();
    $project = appExpensesProject($vendor);
    $merchant = Vendor::factory()->create();
    $unrelatedVendor = Vendor::factory()->create();

    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/expenses', [
        'amount' => '50.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'project_id' => $project->id, 'is_material_order' => true, 'belongs_to_vendor_id' => $unrelatedVendor->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['belongs_to_vendor_id']);

    expect(Expense::withoutGlobalScopes()->where('belongs_to_vendor_id', $unrelatedVendor->id)->count())->toBe(0);
});

it('attaches a new expense to an existing check and recalculates its amount', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpensesTeam();
    $project = appExpensesProject($vendor);
    $merchant = Vendor::factory()->create();
    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $vendor->id, 'plaid_ins_id' => 'ins_'.uniqid()]);
    $account = BankAccount::create(['vendor_id' => $vendor->id, 'bank_id' => $bank->id, 'account_number' => '1234', 'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'checking']);
    $check = \App\Models\Check::create([
        'check_type' => 'Check', 'check_number' => '101', 'date' => now()->format('Y-m-d'),
        'bank_account_id' => $account->id, 'amount' => 20, 'vendor_id' => $merchant->id,
        'belongs_to_vendor_id' => $vendor->id, 'created_by_user_id' => $admin->id,
    ]);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->postJson('/api/app/v1/expenses', [
        'amount' => '35.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'project_id' => $project->id, 'existing_check_id' => $check->id,
    ])->assertCreated();

    $expense = Expense::findOrFail($response->json('data.id'));
    $check->refresh();
    expect((int) $expense->check_id)->toBe($check->id)
        ->and((float) $check->amount)->toBe(35.0);
});

it('refuses an existing_check_id outside the tenant\'s own checks', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpensesTeam();
    $project = appExpensesProject($vendor);
    $merchant = Vendor::factory()->create();
    $otherVendor = Vendor::factory()->create();
    $otherBank = Bank::create(['name' => 'Other Bank', 'vendor_id' => $otherVendor->id, 'plaid_ins_id' => 'ins_'.uniqid()]);
    $otherAccount = BankAccount::create(['vendor_id' => $otherVendor->id, 'bank_id' => $otherBank->id, 'account_number' => '999', 'plaid_account_id' => 'acc_'.uniqid(), 'type' => 'checking']);
    $foreignCheck = \App\Models\Check::create([
        'check_type' => 'Check', 'check_number' => '555', 'date' => now()->format('Y-m-d'),
        'bank_account_id' => $otherAccount->id, 'amount' => 20, 'vendor_id' => $merchant->id,
        'belongs_to_vendor_id' => $otherVendor->id, 'created_by_user_id' => $admin->id,
    ]);

    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/expenses', [
        'amount' => '35.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id,
        'project_id' => $project->id, 'existing_check_id' => $foreignCheck->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['existing_check_id']);
});

it('lists the form-options for Paid By, Reimbursement and Attach to Existing Check', function () {
    ['vendor' => $vendor, 'admin' => $admin, 'member' => $member] = appExpensesTeam();
    // Vendor::business_name is title-cased by a setter on the model.
    $subVendor = Vendor::factory()->create(['business_type' => 'Sub', 'business_name' => 'ABC Drywall']);
    // Reimbursement vendors go through the plain, VendorScope-scoped Vendor
    // query (same as ExpenseCreate::reimbursment_vendors()) — only a vendor
    // already linked to the hub is a candidate, same set "Belongs To
    // Vendor" offers.
    $vendor->vendors()->attach($subVendor->id);
    $unreimbursable = Vendor::factory()->create(['business_type' => 'Retail', 'business_name' => 'Not Reimbursable Inc']);
    $vendor->vendors()->attach($unreimbursable->id);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson('/api/app/v1/expenses/form-options')->assertOk();

    expect(collect($response->json('data.employees'))->pluck('id'))->toContain($admin->id, $member->id)
        ->and(collect($response->json('data.reimbursement_vendors'))->pluck('name'))->toContain($subVendor->business_name)
        ->and(collect($response->json('data.reimbursement_vendors'))->pluck('name'))->not->toContain($unreimbursable->business_name);
});

it('exposes is_material_order and the raw reimbursement value on the detail payload', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpensesTeam();
    $expense = appExpensesExpense($vendor, ['reimbursment' => 'Client']);
    ExpenseReceipts::forceCreate(['expense_id' => $expense->id, 'receipt_filename' => 'r.jpg', 'receipt_items' => [], 'is_material_order' => true]);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson('/api/app/v1/expenses/'.$expense->id)->assertOk();

    expect($response->json('data.is_material_order'))->toBeTrue()
        // belongs_to_vendor is only ever surfaced when it differs from the
        // viewer's own vendor — which, thanks to ExpenseScope, is never true
        // for an expense the viewer can actually see (same reasoning as the
        // web's own dead-looking @elseif branch on expenses/show.blade.php).
        ->and($response->json('data.belongs_to_vendor'))->toBeNull()
        ->and($response->json('data.reimbursement_raw'))->toBe('Client');
});

// --- quick-receipt -----------------------------------------------------------

it('needs a token for the quick-receipt capture endpoint', function () {
    $this->postJson('/api/app/v1/expenses/quick-receipt', [])->assertUnauthorized();
});

it('only an admin can use the quick-receipt capture', function () {
    ['member' => $member] = appExpensesTeam();
    Sanctum::actingAs($member, ['app']);

    $this->post('/api/app/v1/expenses/quick-receipt', [
        'file' => UploadedFile::fake()->create('receipt.pdf', 10),
    ])->assertForbidden();
});

it('OCRs a receipt photo into a brand-new expense, like ExpenseCreate::uploadReceipt()', function () {
    Storage::fake('files');
    ['vendor' => $vendor, 'admin' => $admin] = appExpensesTeam();
    Sanctum::actingAs($admin, ['app']);

    $this->partialMock(\App\Http\Controllers\ReceiptController::class, function ($mock) {
        $mock->shouldReceive('extractReceipt')->andReturn([
            'fields' => ['total' => 19.99, 'transaction_date' => '2026-09-01', 'invoice_number' => 'INV-9'],
        ]);
    });
    $this->partialMock(\App\Http\Controllers\CompanyEmailController::class, function ($mock) {
        $mock->shouldReceive('saveExpenseReceipt')->andReturnNull();
    });

    $response = $this->post('/api/app/v1/expenses/quick-receipt', [
        'file' => UploadedFile::fake()->create('receipt.jpg', 10, 'image/jpeg'),
    ])->assertCreated();

    $expense = Expense::findOrFail($response->json('data.id'));
    expect((float) $expense->amount)->toBe(19.99)
        ->and((string) $expense->invoice)->toBe('INV-9')
        ->and((int) $expense->belongs_to_vendor_id)->toBe($vendor->id)
        ->and((int) $expense->created_by_user_id)->toBe($admin->id)
        ->and($response->json('data.project'))->toBeNull();
});

it('refuses a quick-receipt capture attributed to an unrelated vendor', function () {
    Storage::fake('files');
    ['admin' => $admin] = appExpensesTeam();
    $unrelatedVendor = Vendor::factory()->create();
    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/expenses/quick-receipt', [
        'file' => UploadedFile::fake()->create('receipt.jpg', 10, 'image/jpeg'),
        'is_material_order' => true,
        'belongs_to_vendor_id' => $unrelatedVendor->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['belongs_to_vendor_id']);

    expect(Expense::withoutGlobalScopes()->where('belongs_to_vendor_id', $unrelatedVendor->id)->count())->toBe(0);
});

it('surfaces an OCR failure as a 422 on the file field', function () {
    Storage::fake('files');
    ['admin' => $admin] = appExpensesTeam();
    Sanctum::actingAs($admin, ['app']);

    $this->partialMock(\App\Http\Controllers\ReceiptController::class, function ($mock) {
        $mock->shouldReceive('extractReceipt')->andReturn(['error' => true]);
    });

    $this->postJson('/api/app/v1/expenses/quick-receipt', [
        'file' => UploadedFile::fake()->create('receipt.jpg', 10, 'image/jpeg'),
    ])->assertStatus(422)->assertJsonValidationErrors(['file']);
});

// --- update ----------------------------------------------------------------

it('only an admin can edit an expense', function () {
    ['vendor' => $vendor, 'member' => $member] = appExpensesTeam();
    $expense = appExpensesExpense($vendor, ['paid_by' => $member->id]);
    $merchant = Vendor::factory()->create();

    Sanctum::actingAs($member, ['app']);

    $this->postJson('/api/app/v1/expenses/'.$expense->id, [
        'amount' => '50.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $merchant->id, 'project_id' => null,
    ])->assertForbidden();
});

it('edits an expense\'s fields', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpensesTeam();
    $project = appExpensesProject($vendor);
    $expense = appExpensesExpense($vendor, ['project_id' => $project->id, 'note' => 'Old note']);
    $newMerchant = Vendor::factory()->create(['business_name' => 'Lowes']);

    Sanctum::actingAs($admin, ['app']);

    $this->postJson('/api/app/v1/expenses/'.$expense->id, [
        'amount' => '200.00', 'date' => now()->format('Y-m-d'), 'vendor_id' => $newMerchant->id,
        'project_id' => $project->id, 'note' => 'Updated note',
    ])->assertOk()->assertJsonPath('data.note', 'Updated note');

    $expense->refresh();
    expect((float) $expense->amount)->toBe(200.0)
        ->and((int) $expense->vendor_id)->toBe($newMerchant->id)
        ->and($expense->note)->toBe('Updated note');
});

// --- destroy -----------------------------------------------------------------

it('lets a member delete their own expense when nothing has matched to it yet', function () {
    ['vendor' => $vendor, 'member' => $member] = appExpensesTeam();
    $expense = appExpensesExpense($vendor, ['paid_by' => $member->id]);

    Sanctum::actingAs($member, ['app']);

    $this->deleteJson('/api/app/v1/expenses/'.$expense->id)->assertOk();

    expect(Expense::withoutGlobalScopes()->whereKey($expense->id)->first()->trashed())->toBeTrue();
});

it('refuses a member deleting an expense with a matched transaction', function () {
    ['vendor' => $vendor, 'member' => $member] = appExpensesTeam();
    $expense = appExpensesExpense($vendor, ['paid_by' => $member->id]);
    appExpensesMatchedTransaction($vendor, $expense);

    Sanctum::actingAs($member, ['app']);

    $this->deleteJson('/api/app/v1/expenses/'.$expense->id)->assertForbidden();

    expect(Expense::withoutGlobalScopes()->whereKey($expense->id)->first()->trashed())->toBeFalse();
});

it('lets an admin delete an expense even with a matched transaction', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appExpensesTeam();
    $expense = appExpensesExpense($vendor);
    appExpensesMatchedTransaction($vendor, $expense);

    Sanctum::actingAs($admin, ['app']);

    $this->deleteJson('/api/app/v1/expenses/'.$expense->id)->assertOk();

    expect(Expense::withoutGlobalScopes()->whereKey($expense->id)->first()->trashed())->toBeTrue();
});

// --- vendor options ----------------------------------------------------------

it('lists vendor options for the expense form\'s vendor picker', function () {
    ['admin' => $admin] = appExpensesTeam();
    Vendor::factory()->create(['business_name' => 'Ace Hardware']);

    Sanctum::actingAs($admin, ['app']);

    $response = $this->getJson('/api/app/v1/expenses/vendor-options')->assertOk();

    expect($response->json('data'))->not->toBeEmpty()
        ->and($response->json('data.0'))->toHaveKeys(['id', 'name', 'legal', 'city', 'state', 'type']);
});
