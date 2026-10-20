<?php

use App\Enums\LienWaiverStatus;
use App\Enums\LienWaiverType;
use App\Jobs\SendLienWaiverSigningRequestJob;
use App\Models\Client;
use App\Models\Expense;
use App\Models\LienWaiver;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * AppLeadsTest's appSalesTeamUser() (a differently-named twin here so both
 * test files can load in the same process). $role is the vendor_user
 * pivot's role_id (1 = Admin, 2 = Member) — LienWaiverPolicy's
 * viewAny/create/update/delete all gate on vendor_role === 'Admin' (plus,
 * for update/delete, being the ISSUING vendor — see LienWaiverPolicy).
 *
 * @return array{vendor: Vendor, user: User}
 */
function appSalesWaiverTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

function appSalesWaiverClient(Vendor $vendor, array $overrides = []): Client
{
    $client = Client::factory()->create($overrides);
    $client->vendors()->attach($vendor->id);

    return $client;
}

/** Mirrors AppPaymentsTest's appPaymentsProject() — created withoutEvents so no observer needs an authenticated user. */
function appSalesWaiverProject(Vendor $vendor, Client $client, array $overrides = []): Project
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

function appSalesWaiverRow(Vendor $contractor, Project $project, array $overrides = []): LienWaiver
{
    return LienWaiver::create(array_merge([
        'belongs_to_vendor_id' => $contractor->id,
        'vendor_id' => $contractor->id,
        'project_id' => $project->id,
        'type' => LienWaiverType::ConditionalProgress,
        'status' => LienWaiverStatus::Draft,
        'amount' => 1000,
        'exceptions_amount' => 0,
        'through_date' => now()->toDateString(),
        'jurisdiction' => 'US-IL',
        'created_by_user_id' => User::factory()->create(['primary_vendor_id' => $contractor->id])->id,
    ], $overrides));
}

it('needs a token to list lien waivers', function () {
    $this->getJson('/api/app/v1/lien-waivers')->assertUnauthorized();
});

it('refuses a Member listing lien waivers, holding the line the web nav intends', function () {
    ['user' => $member] = appSalesWaiverTeamUser(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->getJson('/api/app/v1/lien-waivers')->assertForbidden();
});

it('allows an Admin to list lien waivers, newest first, filtered by project and status', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesWaiverTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appSalesWaiverClient($vendor);
    $projectA = appSalesWaiverProject($vendor, $client, ['project_name' => 'Hall Bath']);
    $projectB = appSalesWaiverProject($vendor, $client, ['project_name' => 'Primary Bath']);

    $older = appSalesWaiverRow($vendor, $projectA, ['created_at' => now()->subDay()]);
    $newer = appSalesWaiverRow($vendor, $projectB, ['status' => LienWaiverStatus::Sent, 'created_at' => now()]);

    $response = $this->getJson('/api/app/v1/lien-waivers')->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$newer->id, $older->id]);

    $byProject = $this->getJson("/api/app/v1/lien-waivers?project_id={$projectA->id}")->assertOk();
    expect(collect($byProject->json('data'))->pluck('id')->all())->toBe([$older->id]);

    $byStatus = $this->getJson('/api/app/v1/lien-waivers?status=sent')->assertOk();
    expect(collect($byStatus->json('data'))->pluck('id')->all())->toBe([$newer->id])
        ->and($byStatus->json('data.0.status.label'))->toBe('Sent for Signature');
});

it('404s a lien waiver that does not exist', function () {
    ['user' => $admin] = appSalesWaiverTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $this->getJson('/api/app/v1/lien-waivers/999999')->assertNotFound();
});

it('refuses a Member viewing a lien waiver detail', function () {
    ['vendor' => $vendor, 'user' => $member] = appSalesWaiverTeamUser(role: 2);
    $client = appSalesWaiverClient($vendor);
    $project = appSalesWaiverProject($vendor, $client);
    $waiver = appSalesWaiverRow($vendor, $project);

    Sanctum::actingAs($member, ['app']);

    $this->getJson("/api/app/v1/lien-waivers/{$waiver->id}")->assertForbidden();
});

it('shows a lien waiver detail with its project/client and a signed PDF link', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesWaiverTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appSalesWaiverClient($vendor);
    $project = appSalesWaiverProject($vendor, $client);
    $waiver = appSalesWaiverRow($vendor, $project, ['amount' => 2500.50]);

    $response = $this->getJson("/api/app/v1/lien-waivers/{$waiver->id}")->assertOk();

    expect($response->json('data.id'))->toBe($waiver->id)
        ->and((float) $response->json('data.amount'))->toBe(2500.50)
        ->and($response->json('data.project.id'))->toBe($project->id)
        ->and($response->json('data.client.id'))->toBe($client->id)
        ->and($response->json('data.pdf_url'))->toContain('/lien-waivers/'.$waiver->id.'/download/signed');
});

it('lists eligible projects for a new waiver', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesWaiverTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appSalesWaiverClient($vendor);
    $project = appSalesWaiverProject($vendor, $client);

    $response = $this->getJson('/api/app/v1/lien-waivers/projects')->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toContain($project->id);
});

it("creates the contractor's own waiver, requiring the payer fields", function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesWaiverTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appSalesWaiverClient($vendor, ['address' => '55 Oak Ave']);
    $project = appSalesWaiverProject($vendor, $client);

    $this->postJson('/api/app/v1/lien-waivers', [
        'project_id' => $project->id,
        'type' => LienWaiverType::ConditionalProgress->value,
        'amount' => '2,500',
        'through_date' => now()->toDateString(),
    ])->assertStatus(422)->assertJsonValidationErrors(['payer_name']);

    $response = $this->postJson('/api/app/v1/lien-waivers', [
        'project_id' => $project->id,
        'type' => LienWaiverType::ConditionalProgress->value,
        'amount' => '2,500',
        'through_date' => now()->toDateString(),
        'payer_name' => $client->name,
        'payer_address' => '55 Oak Ave',
    ])->assertCreated();

    $waiver = LienWaiver::query()->findOrFail($response->json('data.id'));
    expect((float) $waiver->amount)->toBe(2500.0)
        ->and($waiver->vendor_id)->toBe($vendor->id)
        ->and($waiver->belongs_to_vendor_id)->toBe($vendor->id)
        ->and($waiver->jurisdiction)->toBe('US-IL');
});

it('creates a sub waiver without payer fields, only for a vendor with money on the project', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesWaiverTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appSalesWaiverClient($vendor);
    $project = appSalesWaiverProject($vendor, $client);
    $sub = Vendor::factory()->create(['business_name' => 'Acme Plumbing']);
    Expense::withoutEvents(fn () => Expense::create([
        'project_id' => $project->id,
        'vendor_id' => $sub->id,
        'belongs_to_vendor_id' => $vendor->id,
        'amount' => 400,
        'date' => now()->toDateString(),
        'created_by_user_id' => $admin->id,
    ]));

    $outsider = Vendor::factory()->create(['business_name' => 'Not On This Job']);

    $this->postJson('/api/app/v1/lien-waivers', [
        'project_id' => $project->id,
        'type' => LienWaiverType::ConditionalProgress->value,
        'claimant_vendor_id' => $outsider->id,
        'amount' => '400',
        'through_date' => now()->toDateString(),
    ])->assertStatus(422);

    $response = $this->postJson('/api/app/v1/lien-waivers', [
        'project_id' => $project->id,
        'type' => LienWaiverType::ConditionalProgress->value,
        'claimant_vendor_id' => $sub->id,
        'amount' => '400',
        'through_date' => now()->toDateString(),
    ])->assertCreated();

    $waiver = LienWaiver::query()->findOrFail($response->json('data.id'));
    expect($waiver->vendor_id)->toBe($sub->id)
        ->and($waiver->belongs_to_vendor_id)->toBe($vendor->id)
        ->and($response->json('data.is_sub_waiver'))->toBeTrue();
});

it('refuses a Member creating a lien waiver, like the web', function () {
    ['vendor' => $vendor, 'user' => $member] = appSalesWaiverTeamUser(role: 2);
    $client = appSalesWaiverClient($vendor);
    $project = appSalesWaiverProject($vendor, $client);

    Sanctum::actingAs($member, ['app']);

    $this->postJson('/api/app/v1/lien-waivers', [
        'project_id' => $project->id,
        'type' => LienWaiverType::ConditionalProgress->value,
        'amount' => '100',
        'through_date' => now()->toDateString(),
        'payer_name' => 'Someone',
    ])->assertForbidden();
});

it('queues a signing request on send, like Index::sendForSignature()', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesWaiverTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);
    Bus::fake();

    $client = appSalesWaiverClient($vendor);
    $project = appSalesWaiverProject($vendor, $client);
    $waiver = appSalesWaiverRow($vendor, $project);

    $this->postJson("/api/app/v1/lien-waivers/{$waiver->id}/send")->assertOk();

    Bus::assertDispatched(SendLienWaiverSigningRequestJob::class, fn ($job) => $job->lienWaiverId === $waiver->id);
});

it('refuses to (re)send a signed or cancelled waiver', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesWaiverTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);
    Bus::fake();

    $client = appSalesWaiverClient($vendor);
    $project = appSalesWaiverProject($vendor, $client);
    $signed = appSalesWaiverRow($vendor, $project, ['status' => LienWaiverStatus::Signed]);

    $this->postJson("/api/app/v1/lien-waivers/{$signed->id}/send")->assertStatus(422);
    Bus::assertNotDispatched(SendLienWaiverSigningRequestJob::class);
});

it('refuses a sub vendor (the recipient) from sending/cancelling a waiver issued to them', function () {
    ['vendor' => $gc] = appSalesWaiverTeamUser(role: 1);
    $sub = Vendor::factory()->create(['business_name' => 'Acme Plumbing']);
    $subAdmin = User::factory()->create(['primary_vendor_id' => $sub->id]);
    $sub->users()->attach($subAdmin->id, ['role_id' => 1]);

    $client = appSalesWaiverClient($gc);
    $project = appSalesWaiverProject($gc, $client);
    $waiver = appSalesWaiverRow($gc, $project, ['vendor_id' => $sub->id]);

    Sanctum::actingAs($subAdmin, ['app']);

    $this->postJson("/api/app/v1/lien-waivers/{$waiver->id}/send")->assertForbidden();
    $this->postJson("/api/app/v1/lien-waivers/{$waiver->id}/cancel")->assertForbidden();
});

it('cancels a draft/sent waiver but refuses to cancel a signed one', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesWaiverTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appSalesWaiverClient($vendor);
    $project = appSalesWaiverProject($vendor, $client);
    $waiver = appSalesWaiverRow($vendor, $project);

    $this->postJson("/api/app/v1/lien-waivers/{$waiver->id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status.value', LienWaiverStatus::Cancelled->value);

    $signed = appSalesWaiverRow($vendor, $project, ['status' => LienWaiverStatus::Signed]);
    $this->postJson("/api/app/v1/lien-waivers/{$signed->id}/cancel")->assertStatus(422);
});

it('deletes a draft/sent waiver but refuses to delete a signed one', function () {
    ['vendor' => $vendor, 'user' => $admin] = appSalesWaiverTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appSalesWaiverClient($vendor);
    $project = appSalesWaiverProject($vendor, $client);
    $waiver = appSalesWaiverRow($vendor, $project);

    $this->deleteJson("/api/app/v1/lien-waivers/{$waiver->id}")->assertOk();
    expect(LienWaiver::query()->find($waiver->id))->toBeNull();

    $signed = appSalesWaiverRow($vendor, $project, ['status' => LienWaiverStatus::Signed]);
    $this->deleteJson("/api/app/v1/lien-waivers/{$signed->id}")->assertStatus(422);
    expect(LienWaiver::query()->find($signed->id))->not->toBeNull();
});

it('refuses a Member sending, cancelling or deleting a lien waiver', function () {
    ['vendor' => $vendor, 'user' => $member] = appSalesWaiverTeamUser(role: 2);
    $client = appSalesWaiverClient($vendor);
    $project = appSalesWaiverProject($vendor, $client);
    $waiver = appSalesWaiverRow($vendor, $project);

    Sanctum::actingAs($member, ['app']);

    $this->postJson("/api/app/v1/lien-waivers/{$waiver->id}/send")->assertForbidden();
    $this->postJson("/api/app/v1/lien-waivers/{$waiver->id}/cancel")->assertForbidden();
    $this->deleteJson("/api/app/v1/lien-waivers/{$waiver->id}")->assertForbidden();
});
