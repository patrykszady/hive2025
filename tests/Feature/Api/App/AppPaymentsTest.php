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
        ->toBe(collect([$parent->id, $child->id])->sort()->values()->all());
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
