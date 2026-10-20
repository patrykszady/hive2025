<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    // No real Meilisearch round trips from fixture setup — same reasoning
    // as AppProjectsTest.
    config(['scout.driver' => null]);
});

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * AppAuthTest's appTeamUser(). $role is the vendor_user pivot's role_id
 * (1 = Admin, 2 = Member) — PaymentPolicy gates everything on it.
 *
 * @return array{vendor: Vendor, user: User}
 */
function appPaymentsTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

/** A client linked to $vendor through client_vendor (ClientScope). */
function appPaymentsClient(Vendor $vendor, array $overrides = []): Client
{
    $client = Client::factory()->create($overrides);
    $client->vendors()->attach($vendor->id);

    return $client;
}

/** A project owned by $vendor, for $client, linked through project_vendor (ProjectScope). */
function appPaymentsProject(Vendor $vendor, Client $client, array $overrides = []): Project
{
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

function appPaymentsStatus(Project $project, Vendor $vendor, int $code): ProjectStatus
{
    return ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'status_code' => $code,
        'start_date' => now()->format('Y-m-d'),
    ]);
}

function appPaymentsRow(Vendor $vendor, Project $project, array $overrides = []): Payment
{
    return Payment::create(array_merge([
        'project_id' => $project->id,
        'amount' => 500,
        'date' => now()->format('Y-m-d'),
        'reference' => 'CHK 100',
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => User::factory()->create(['primary_vendor_id' => $vendor->id])->id,
    ], $overrides));
}

it('needs a token to list payments', function () {
    $this->getJson('/api/app/v1/payments')->assertUnauthorized();
});

it('refuses a Member viewing the payments list, like the web', function () {
    ['user' => $member] = appPaymentsTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->getJson('/api/app/v1/payments')->assertForbidden();
});

it('allows an Admin to list payments, newest date first, with a filtered total', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPaymentsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appPaymentsClient($vendor);
    $project = appPaymentsProject($vendor, $client);

    $older = appPaymentsRow($vendor, $project, ['amount' => 300, 'date' => '2026-09-01', 'reference' => 'A']);
    $newer = appPaymentsRow($vendor, $project, ['amount' => 700, 'date' => '2026-10-01', 'reference' => 'B']);

    $response = $this->getJson('/api/app/v1/payments')->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$newer->id, $older->id])
        ->and((float) $response->json('total_amount'))->toBe(1000.0)
        ->and($response->json('data.0.status.label'))->toBe('Missing')
        ->and($response->json('data.0.project.id'))->toBe($project->id)
        ->and($response->json('data.0.client.id'))->toBe($client->id);
});

it('filters the payments list by transaction status', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPaymentsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appPaymentsClient($vendor);
    $project = appPaymentsProject($vendor, $client);

    $bank = Bank::create(['name' => 'Test Bank', 'vendor_id' => $vendor->id, 'plaid_ins_id' => 'ins_test_'.uniqid()]);
    $bankAccount = BankAccount::create([
        'bank_id' => $bank->id, 'vendor_id' => $vendor->id, 'type' => 'Checking',
        'account_number' => 1, 'plaid_account_id' => 'acc_test_'.uniqid(),
    ]);
    $transaction = Transaction::create(['amount' => 500, 'transaction_date' => now()->format('Y-m-d'), 'bank_account_id' => $bankAccount->id]);
    $complete = appPaymentsRow($vendor, $project, ['transaction_id' => $transaction->id, 'reference' => 'DONE']);
    $missing = appPaymentsRow($vendor, $project, ['reference' => 'PENDING']);

    $completeResponse = $this->getJson('/api/app/v1/payments?status=complete')->assertOk();
    expect(collect($completeResponse->json('data'))->pluck('id')->all())->toBe([$complete->id]);

    $missingResponse = $this->getJson('/api/app/v1/payments?status=missing')->assertOk();
    expect(collect($missingResponse->json('data'))->pluck('id')->all())->toBe([$missing->id]);
});

it('sorts the payments list by date ascending when asked, descending by default', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPaymentsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appPaymentsClient($vendor);
    $project = appPaymentsProject($vendor, $client);
    $older = appPaymentsRow($vendor, $project, ['date' => '2026-09-01']);
    $newer = appPaymentsRow($vendor, $project, ['date' => '2026-10-01']);

    expect(collect($this->getJson('/api/app/v1/payments')->json('data'))->pluck('id')->all())->toBe([$newer->id, $older->id])
        ->and(collect($this->getJson('/api/app/v1/payments?direction=asc')->json('data'))->pluck('id')->all())->toBe([$older->id, $newer->id]);
});

it('lists the Project/Client filter options, the same cached lists PaymentsIndex::mount() builds for the web', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPaymentsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appPaymentsClient($vendor, ['business_name' => 'Acme Co']);
    $project = appPaymentsProject($vendor, $client, ['project_name' => 'Kitchen Remodel']);
    appPaymentsRow($vendor, $project);

    // A project/client with no payment at all must not show up as a filter option.
    $otherClient = appPaymentsClient($vendor, ['business_name' => 'No Payments LLC']);
    appPaymentsProject($vendor, $otherClient, ['project_name' => 'Unpaid Job']);

    $response = $this->getJson('/api/app/v1/payments/filter-options')->assertOk();

    expect(collect($response->json('data.projects'))->pluck('name'))->toContain('Kitchen Remodel')->not->toContain('Unpaid Job')
        ->and(collect($response->json('data.clients'))->pluck('name'))->toContain('Acme Co')->not->toContain('No Payments LLC');
});

it('refuses a Member requesting filter options, like the web\'s Admin-only Payments page', function () {
    ['user' => $member] = appPaymentsTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->getJson('/api/app/v1/payments/filter-options')->assertForbidden();
});

it('404s a payment that does not exist', function () {
    ['user' => $admin] = appPaymentsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $this->getJson('/api/app/v1/payments/999999')->assertNotFound();
});

it('refuses a Member viewing a single payment\'s detail, like the web', function () {
    ['vendor' => $vendor, 'user' => $member] = appPaymentsTeamUser(role: 2);
    $client = appPaymentsClient($vendor);
    $project = appPaymentsProject($vendor, $client);
    $payment = appPaymentsRow($vendor, $project);

    Sanctum::actingAs($member, ['app']);

    $this->getJson("/api/app/v1/payments/{$payment->id}")->assertForbidden();
});

it('shows a payment\'s detail with its grouped siblings', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPaymentsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appPaymentsClient($vendor);
    $hallBath = appPaymentsProject($vendor, $client, ['project_name' => 'Hall Bath']);
    $primaryBath = appPaymentsProject($vendor, $client, ['project_name' => 'Primary Bath']);

    $parent = appPaymentsRow($vendor, $hallBath, ['amount' => 400, 'reference' => 'GRP']);
    $child = appPaymentsRow($vendor, $primaryBath, ['amount' => 600, 'reference' => 'GRP', 'parent_client_payment_id' => $parent->id]);

    $response = $this->getJson("/api/app/v1/payments/{$parent->id}")->assertOk();

    expect($response->json('data.id'))->toBe($parent->id)
        ->and(collect($response->json('data.grouped_payments'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$parent->id, $child->id])->sort()->values()->all())
        ->and(collect($response->json('data.grouped_payments'))->pluck('reference')->unique()->all())
        ->toBe(['GRP']);
});

it('shows the matched transaction\'s bank and account, like the web\'s Payment Transaction card', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPaymentsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appPaymentsClient($vendor);
    $project = appPaymentsProject($vendor, $client);

    $bank = Bank::create(['name' => 'Citibank', 'vendor_id' => $vendor->id, 'plaid_ins_id' => 'ins_'.uniqid()]);
    $bankAccount = BankAccount::create([
        'bank_id' => $bank->id, 'vendor_id' => $vendor->id, 'type' => 'Checking',
        'account_number' => 4903, 'plaid_account_id' => 'acc_'.uniqid(),
    ]);
    $transaction = Transaction::create([
        'amount' => 13000, 'transaction_date' => now()->format('Y-m-d'), 'bank_account_id' => $bankAccount->id,
    ]);
    $payment = appPaymentsRow($vendor, $project, ['transaction_id' => $transaction->id]);

    $response = $this->getJson("/api/app/v1/payments/{$payment->id}")->assertOk();

    expect($response->json('data.transaction.bank'))->toBe('Citibank')
        ->and($response->json('data.transaction.account'))->toBe('4903');
});

it('prefers the transaction\'s own owner over the bank account number for the Payment Transaction "Account" field', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPaymentsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appPaymentsClient($vendor);
    $project = appPaymentsProject($vendor, $client);

    $bank = Bank::create(['name' => 'Capital One', 'vendor_id' => $vendor->id, 'plaid_ins_id' => 'ins_'.uniqid()]);
    $bankAccount = BankAccount::create([
        'bank_id' => $bank->id, 'vendor_id' => $vendor->id, 'type' => 'Checking',
        'account_number' => 1111, 'plaid_account_id' => 'acc_'.uniqid(),
    ]);
    $transaction = Transaction::create([
        'amount' => 50, 'transaction_date' => now()->format('Y-m-d'), 'bank_account_id' => $bankAccount->id, 'owner' => 1820,
    ]);
    $payment = appPaymentsRow($vendor, $project, ['transaction_id' => $transaction->id]);

    $response = $this->getJson("/api/app/v1/payments/{$payment->id}")->assertOk();

    expect($response->json('data.transaction.account'))->toBe('1820');
});

it('lists eligible projects for a client: Active always, Completed only with a balance', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPaymentsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appPaymentsClient($vendor);
    $active = appPaymentsProject($vendor, $client, ['project_name' => 'Active Job']);
    appPaymentsStatus($active, $vendor, 6);

    $completedPaidOff = appPaymentsProject($vendor, $client, ['project_name' => 'Paid Off Job']);
    appPaymentsStatus($completedPaidOff, $vendor, 7);

    $response = $this->getJson("/api/app/v1/payments/eligible-projects?client_id={$client->id}")->assertOk();
    $ids = collect($response->json('data'))->pluck('id');

    expect($ids->all())->toBe([$active->id])
        ->and($ids)->not->toContain($completedPaidOff->id);
});

it('sorts the project a payment was opened from first and flags it, like the web\'s "This project" badge', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPaymentsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appPaymentsClient($vendor);
    $first = appPaymentsProject($vendor, $client, ['project_name' => 'First Job']);
    appPaymentsStatus($first, $vendor, 6);
    $openedFrom = appPaymentsProject($vendor, $client, ['project_name' => 'Opened From Job']);
    appPaymentsStatus($openedFrom, $vendor, 6);

    $response = $this->getJson("/api/app/v1/payments/eligible-projects?client_id={$client->id}&opened_from_project_id={$openedFrom->id}")
        ->assertOk();

    $data = collect($response->json('data'));

    expect($data->pluck('id')->first())->toBe($openedFrom->id)
        ->and($data->firstWhere('id', $openedFrom->id)['is_current_project'])->toBeTrue()
        ->and($data->firstWhere('id', $first->id)['is_current_project'])->toBeFalse();
});

it('refuses a Member requesting eligible projects (part of the create flow), like the web', function () {
    ['vendor' => $vendor, 'user' => $member] = appPaymentsTeamUser(role: 2);
    $client = appPaymentsClient($vendor);

    Sanctum::actingAs($member, ['app']);

    $this->getJson("/api/app/v1/payments/eligible-projects?client_id={$client->id}")->assertForbidden();
});

it('creates a payment, dropping a project row left blank', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPaymentsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appPaymentsClient($vendor);
    $hallBath = appPaymentsProject($vendor, $client, ['project_name' => 'Hall Bath']);
    $primaryBath = appPaymentsProject($vendor, $client, ['project_name' => 'Primary Bath']);

    $response = $this->postJson('/api/app/v1/payments', [
        'date' => now()->format('Y-m-d'),
        'reference' => '2062',
        'projects' => [
            ['id' => $hallBath->id, 'amount' => null],
            ['id' => $primaryBath->id, 'amount' => '1500'],
        ],
    ])->assertCreated();

    expect(Payment::query()->where('reference', '2062')->count())->toBe(1);
    $payment = Payment::query()->where('reference', '2062')->sole();
    expect($payment->project_id)->toBe($primaryBath->id)
        ->and((float) $payment->amount)->toBe(1500.0)
        ->and($response->json('data.project.id'))->toBe($primaryBath->id);
});

it('rejects a payment whose projects sum to $0.00, like the web', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPaymentsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appPaymentsClient($vendor);
    $project = appPaymentsProject($vendor, $client);

    $this->postJson('/api/app/v1/payments', [
        'date' => now()->format('Y-m-d'),
        'reference' => '2061',
        'projects' => [
            ['id' => $project->id, 'amount' => null],
        ],
    ])->assertStatus(422);

    expect(Payment::query()->where('reference', '2061')->exists())->toBeFalse();
});

it('rejects an explicit 0.00 amount on a project row, like the web', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPaymentsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appPaymentsClient($vendor);
    $project = appPaymentsProject($vendor, $client);

    $this->postJson('/api/app/v1/payments', [
        'date' => now()->format('Y-m-d'),
        'reference' => 'ZERO',
        'projects' => [
            ['id' => $project->id, 'amount' => '0'],
        ],
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['projects.0.amount']);
});

it('refuses a Member creating a payment, like the web', function () {
    ['vendor' => $vendor, 'user' => $member] = appPaymentsTeamUser(role: 2);
    $client = appPaymentsClient($vendor);
    $project = appPaymentsProject($vendor, $client);

    Sanctum::actingAs($member, ['app']);

    $this->postJson('/api/app/v1/payments', [
        'date' => now()->format('Y-m-d'),
        'reference' => 'NOPE',
        'projects' => [
            ['id' => $project->id, 'amount' => '100'],
        ],
    ])->assertForbidden();

    expect(Payment::query()->where('reference', 'NOPE')->exists())->toBeFalse();
});

it('silently drops a project id outside the tenant, never recording against it', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPaymentsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appPaymentsClient($vendor);
    $ownProject = appPaymentsProject($vendor, $client);

    $otherVendor = Vendor::factory()->create();
    $otherClient = Client::factory()->create();
    $otherClient->vendors()->attach($otherVendor->id);
    $foreignProject = appPaymentsProject($otherVendor, $otherClient);

    $this->postJson('/api/app/v1/payments', [
        'date' => now()->format('Y-m-d'),
        'reference' => 'MIXED',
        'projects' => [
            ['id' => $ownProject->id, 'amount' => '250'],
            ['id' => $foreignProject->id, 'amount' => '999'],
        ],
    ])->assertCreated();

    expect(Payment::query()->where('reference', 'MIXED')->count())->toBe(1)
        ->and(Payment::withoutGlobalScopes()->where('project_id', $foreignProject->id)->where('reference', 'MIXED')->exists())->toBeFalse();
});

// PaymentCreate::editPayment()/update()/remove() have no app equivalent
// until PaymentController::update()/destroy() — added alongside
// PaymentRecorder::update() (extracted from PaymentForm::update() so both
// share the exact same grouping/collapsing logic).
it('updates a single-project payment\'s amount and reference', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPaymentsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appPaymentsClient($vendor);
    $project = appPaymentsProject($vendor, $client);
    $payment = appPaymentsRow($vendor, $project, ['amount' => 500, 'reference' => 'OLD']);

    $response = $this->putJson("/api/app/v1/payments/{$payment->id}", [
        'date' => now()->format('Y-m-d'),
        'reference' => 'NEW',
        'projects' => [
            ['id' => $project->id, 'amount' => '750'],
        ],
    ])->assertOk();

    $payment->refresh();
    expect((float) $payment->amount)->toBe(750.0)
        ->and($payment->reference)->toBe('NEW')
        ->and((float) $response->json('data.amount'))->toBe(750.0);
});

it('splits a single-project payment across a second project, grouping the new row under it', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPaymentsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appPaymentsClient($vendor);
    $hallBath = appPaymentsProject($vendor, $client, ['project_name' => 'Hall Bath']);
    $primaryBath = appPaymentsProject($vendor, $client, ['project_name' => 'Primary Bath']);
    $payment = appPaymentsRow($vendor, $hallBath, ['amount' => 400, 'reference' => 'GRP']);

    $this->putJson("/api/app/v1/payments/{$payment->id}", [
        'date' => now()->format('Y-m-d'),
        'reference' => 'GRP',
        'projects' => [
            ['id' => $hallBath->id, 'amount' => '400'],
            ['id' => $primaryBath->id, 'amount' => '600'],
        ],
    ])->assertOk();

    $children = Payment::query()->where('parent_client_payment_id', $payment->id)->get();
    expect($children)->toHaveCount(1)
        ->and((float) $children->first()->amount)->toBe(600.0)
        ->and($children->first()->project_id)->toBe($primaryBath->id);
});

// NOTE: this pins down the web's actual (and seemingly unintended) behavior
// rather than "correct" behavior — PaymentForm::update()'s single-project
// branch only fills/saves $this->payment; unlike its multi-project branch,
// it never runs the "delete payments no longer selected" cleanup. Reducing
// a grouped payment back to one project does NOT delete the other row, so
// it survives, still grouped under the now-renumbered root. Flagged in the
// parity report rather than silently changed, since this is pre-existing
// web behavior this task was not asked to fix and it moves real accounting
// data.
it('reduces a grouped payment to one project in place, without deleting the other row (matches the web)', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPaymentsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appPaymentsClient($vendor);
    $hallBath = appPaymentsProject($vendor, $client, ['project_name' => 'Hall Bath']);
    $primaryBath = appPaymentsProject($vendor, $client, ['project_name' => 'Primary Bath']);
    $parent = appPaymentsRow($vendor, $hallBath, ['amount' => 400, 'reference' => 'GRP']);
    $child = appPaymentsRow($vendor, $primaryBath, ['amount' => 600, 'reference' => 'GRP', 'parent_client_payment_id' => $parent->id]);

    $this->putJson("/api/app/v1/payments/{$parent->id}", [
        'date' => now()->format('Y-m-d'),
        'reference' => 'GRP',
        'projects' => [
            ['id' => $hallBath->id, 'amount' => '1000'],
            ['id' => $primaryBath->id, 'amount' => null],
        ],
    ])->assertOk();

    expect(Payment::query()->whereKey($child->id)->exists())->toBeTrue()
        ->and((float) $child->refresh()->amount)->toBe(600.0)
        ->and((float) $parent->refresh()->amount)->toBe(1000.0)
        ->and($parent->project_id)->toBe($hallBath->id)
        ->and($parent->parent_client_payment_id)->toBeNull();
});

it('rejects an update whose projects sum to $0.00, like the web', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPaymentsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appPaymentsClient($vendor);
    $project = appPaymentsProject($vendor, $client);
    $payment = appPaymentsRow($vendor, $project, ['amount' => 500]);

    $this->putJson("/api/app/v1/payments/{$payment->id}", [
        'date' => now()->format('Y-m-d'),
        'reference' => 'ZERO',
        'projects' => [
            ['id' => $project->id, 'amount' => null],
        ],
    ])->assertStatus(422);

    expect((float) $payment->refresh()->amount)->toBe(500.0);
});

it('refuses a Member updating a payment, like the web', function () {
    ['vendor' => $vendor, 'user' => $member] = appPaymentsTeamUser(role: 2);
    $client = appPaymentsClient($vendor);
    $project = appPaymentsProject($vendor, $client);
    $payment = appPaymentsRow($vendor, $project);

    Sanctum::actingAs($member, ['app']);

    $this->putJson("/api/app/v1/payments/{$payment->id}", [
        'date' => now()->format('Y-m-d'),
        'reference' => 'NOPE',
        'projects' => [
            ['id' => $project->id, 'amount' => '100'],
        ],
    ])->assertForbidden();
});

it('deletes exactly the one payment row an admin opened, leaving its siblings', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPaymentsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appPaymentsClient($vendor);
    $hallBath = appPaymentsProject($vendor, $client, ['project_name' => 'Hall Bath']);
    $primaryBath = appPaymentsProject($vendor, $client, ['project_name' => 'Primary Bath']);
    $parent = appPaymentsRow($vendor, $hallBath, ['amount' => 400, 'reference' => 'GRP']);
    $child = appPaymentsRow($vendor, $primaryBath, ['amount' => 600, 'reference' => 'GRP', 'parent_client_payment_id' => $parent->id]);

    $this->deleteJson("/api/app/v1/payments/{$parent->id}")->assertOk();

    expect(Payment::query()->whereKey($parent->id)->exists())->toBeFalse()
        ->and(Payment::query()->whereKey($child->id)->exists())->toBeTrue();
});

it('refuses a Member deleting a payment, like the web', function () {
    ['vendor' => $vendor, 'user' => $member] = appPaymentsTeamUser(role: 2);
    $client = appPaymentsClient($vendor);
    $project = appPaymentsProject($vendor, $client);
    $payment = appPaymentsRow($vendor, $project);

    Sanctum::actingAs($member, ['app']);

    $this->deleteJson("/api/app/v1/payments/{$payment->id}")->assertForbidden();
});

it('404s updating and deleting a payment that does not exist', function () {
    ['user' => $admin] = appPaymentsTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $this->putJson('/api/app/v1/payments/999999', [
        'date' => now()->format('Y-m-d'),
        'reference' => 'X',
        'projects' => [['id' => 1, 'amount' => '100']],
    ])->assertNotFound();

    $this->deleteJson('/api/app/v1/payments/999999')->assertNotFound();
});
