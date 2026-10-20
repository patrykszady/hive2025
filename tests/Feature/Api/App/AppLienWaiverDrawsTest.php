<?php

use App\Enums\LienWaiverStatus;
use App\Jobs\SendDrawPackageJob;
use App\Jobs\SendLienWaiverSigningRequestJob;
use App\Models\Bid;
use App\Models\Client;
use App\Models\Expense;
use App\Models\LienWaiver;
use App\Models\Project;
use App\Models\SwornStatement;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
    Storage::fake('files');
    Mail::fake();
    Bus::fake([SendLienWaiverSigningRequestJob::class, SendDrawPackageJob::class]);
});

/**
 * GS Construction is vendor id 1 — same reasoning as AppLienWaiversTest's
 * appSalesWaiverTeamUser(), a differently-named twin so both test files can
 * load in the same process.
 *
 * @return array{vendor: Vendor, user: User}
 */
function appDrawTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

function appDrawClient(Vendor $vendor, array $overrides = []): Client
{
    $client = Client::factory()->create($overrides);
    $client->vendors()->attach($vendor->id);

    return $client;
}

/** Signed-contract project (a type-1 bid), same shape as the web's swornStatementFixtures(). */
function appDrawProject(Vendor $vendor, Client $client, array $overrides = []): Project
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
    Bid::withoutGlobalScopes()->create(['project_id' => $project->id, 'vendor_id' => $vendor->id, 'amount' => 100000, 'type' => 1]);

    return $project;
}

/** A sub with a work type set and a recorded expense on the project — buildRows() offers them on the draw. */
function appDrawSubVendor(Vendor $gc, Project $project, float $paid = 5000): Vendor
{
    $sub = Vendor::query()->create([
        'business_name' => 'PMG Carpentry Inc',
        'business_type' => 'Sub',
        'business_email' => 'pmg@example.test',
        'address' => '1 Trade Ln', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);
    $carpentry = WorkType::query()->create(['belongs_to_vendor_id' => $gc->id, 'name' => 'Carpentry']);
    $sub->forceFill(['work_type_id' => $carpentry->id])->save();

    Expense::withoutGlobalScopes()->create([
        'project_id' => $project->id, 'vendor_id' => $sub->id, 'belongs_to_vendor_id' => $gc->id,
        'amount' => $paid, 'date' => now()->toDateString(),
        'created_by_user_id' => User::factory()->create(['primary_vendor_id' => $gc->id])->id,
    ]);

    return $sub;
}

it('needs a token to list draws', function () {
    $this->getJson('/api/app/v1/lien-waivers/draws')->assertUnauthorized();
});

it('refuses a Member listing, starting or creating draws', function () {
    ['vendor' => $vendor, 'user' => $member] = appDrawTeamUser(role: 2);
    $client = appDrawClient($vendor);
    $project = appDrawProject($vendor, $client);

    Sanctum::actingAs($member, ['app']);

    $this->getJson('/api/app/v1/lien-waivers/draws')->assertForbidden();
    $this->getJson("/api/app/v1/lien-waivers/draws/new?project_id={$project->id}")->assertForbidden();
    $this->postJson('/api/app/v1/lien-waivers/draws', [
        'project_id' => $project->id,
        'this_payment' => '1000',
        'rows' => [['vendor_id' => 1]],
    ])->assertForbidden();
});

it('lists an admin\'s draws across projects, newest first, with waiver counts and signed PDF links', function () {
    ['vendor' => $vendor, 'user' => $admin] = appDrawTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appDrawClient($vendor);
    $projectA = appDrawProject($vendor, $client, ['project_name' => 'Hall Bath']);
    $projectB = appDrawProject($vendor, $client, ['project_name' => 'Primary Bath']);

    // created_at isn't mass-assignable (SwornStatement::$fillable) — set it
    // after create() so the two rows sort unambiguously by created_at desc.
    $older = SwornStatement::create(['project_id' => $projectA->id, 'belongs_to_vendor_id' => $vendor->id, 'this_payment' => 1000]);
    $older->forceFill(['created_at' => now()->subDay()])->save();
    $newer = SwornStatement::create(['project_id' => $projectB->id, 'belongs_to_vendor_id' => $vendor->id, 'this_payment' => 2000]);

    $response = $this->getJson('/api/app/v1/lien-waivers/draws')->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$newer->id, $older->id])
        ->and((float) $response->json('data.0.this_payment'))->toBe(2000.0)
        ->and($response->json('data.0.pdf_url'))->toContain('signature=')
        ->and($response->json('data.0.package_pdf_url'))->toContain('signature=');

    $byProject = $this->getJson("/api/app/v1/lien-waivers/draws?project_id={$projectA->id}")->assertOk();
    expect(collect($byProject->json('data'))->pluck('id')->all())->toBe([$older->id]);
});

it('returns new-draw rows prefilled from the project\'s money, scoped to that project', function () {
    ['vendor' => $vendor, 'user' => $admin] = appDrawTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appDrawClient($vendor);
    $project = appDrawProject($vendor, $client);
    $sub = appDrawSubVendor($vendor, $project, 5000);

    $response = $this->getJson("/api/app/v1/lien-waivers/draws/new?project_id={$project->id}")->assertOk();

    expect($response->json('data.has_signed_contract'))->toBeTrue();
    $rows = collect($response->json('data.rows'));
    expect($rows->pluck('vendor_id'))->toContain($sub->id)
        ->and((float) $rows->firstWhere('vendor_id', $sub->id)['paid'])->toBe(5000.0)
        ->and($response->json('data.kind_of_work_options'))->toContain('Carpentry');
});

it('creates a draw: the statement, the GC\'s own waiver, and a sub waiver, all stamped together', function () {
    ['vendor' => $vendor, 'user' => $admin] = appDrawTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appDrawClient($vendor);
    $project = appDrawProject($vendor, $client);
    $sub = appDrawSubVendor($vendor, $project, 5000);

    $response = $this->postJson('/api/app/v1/lien-waivers/draws', [
        'project_id' => $project->id,
        'this_payment' => '20,000',
        'rows' => [
            ['vendor_id' => $sub->id, 'include' => true, 'kind' => 'Carpentry', 'paid' => 5000, 'this_payment' => '1,000', 'contract' => ''],
        ],
    ])->assertCreated();

    $statementId = $response->json('data.statement.id');
    expect(SwornStatement::query()->where('id', $statementId)->where('this_payment', 20000)->exists())->toBeTrue();

    $gcWaiver = LienWaiver::withoutGlobalScopes()->where('sworn_statement_id', $statementId)->where('vendor_id', $vendor->id)->first();
    expect($gcWaiver)->not->toBeNull()->and((float) $gcWaiver->amount)->toBe(20000.0);

    $subWaiver = LienWaiver::withoutGlobalScopes()->where('sworn_statement_id', $statementId)->where('vendor_id', $sub->id)->first();
    expect($subWaiver)->not->toBeNull()->and((float) $subWaiver->amount)->toBe(6000.0) // paid 5000 + this draw's 1000
        ->and($response->json('data.sub_sent'))->toBe(1)
        ->and($response->json('data.statement.pdf_url'))->toContain('signature=');

    Bus::assertDispatched(SendLienWaiverSigningRequestJob::class);
});

it('drops a tampered row for a vendor with no money on the project (vendor whitelist)', function () {
    ['vendor' => $vendor, 'user' => $admin] = appDrawTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appDrawClient($vendor);
    $project = appDrawProject($vendor, $client);
    $sub = appDrawSubVendor($vendor, $project, 5000);
    $stranger = Vendor::factory()->create(['business_type' => 'Sub']);

    $this->postJson('/api/app/v1/lien-waivers/draws', [
        'project_id' => $project->id,
        'this_payment' => '6,000',
        'rows' => [
            ['vendor_id' => $sub->id, 'include' => true, 'kind' => 'Carpentry', 'paid' => 5000, 'this_payment' => '0'],
            ['vendor_id' => $stranger->id, 'include' => true, 'kind' => 'Carpentry', 'paid' => 0, 'this_payment' => '500', 'contract' => '1000'],
        ],
    ])->assertCreated();

    expect(LienWaiver::withoutGlobalScopes()->where('vendor_id', $stranger->id)->count())->toBe(0)
        ->and(Bid::withoutGlobalScopes()->where('vendor_id', $stranger->id)->count())->toBe(0)
        ->and(LienWaiver::withoutGlobalScopes()->where('vendor_id', $sub->id)->count())->toBe(1);
});

it('validates the draw amount and requires a kind of work for every included vendor', function () {
    ['vendor' => $vendor, 'user' => $admin] = appDrawTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appDrawClient($vendor);
    $project = appDrawProject($vendor, $client);
    $sub = appDrawSubVendor($vendor, $project, 5000);

    $response = $this->postJson('/api/app/v1/lien-waivers/draws', [
        'project_id' => $project->id,
        'this_payment' => '0',
        'rows' => [
            ['vendor_id' => $sub->id, 'include' => true, 'kind' => '', 'paid' => 5000, 'this_payment' => '1000'],
        ],
    ])->assertStatus(422);

    expect($response->json('errors'))->toHaveKeys(['this_payment', 'rows.0.kind']);
});

it('edits a draft draw: PUT revises the amount and re-sends the sub waiver', function () {
    ['vendor' => $vendor, 'user' => $admin] = appDrawTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appDrawClient($vendor);
    $project = appDrawProject($vendor, $client);
    $sub = appDrawSubVendor($vendor, $project, 5000);

    $created = $this->postJson('/api/app/v1/lien-waivers/draws', [
        'project_id' => $project->id,
        'this_payment' => '10,000',
        'rows' => [
            ['vendor_id' => $sub->id, 'include' => true, 'kind' => 'Carpentry', 'paid' => 5000, 'this_payment' => '1,000'],
        ],
    ])->assertCreated();
    $statementId = $created->json('data.statement.id');

    $edit = $this->getJson("/api/app/v1/lien-waivers/draws/{$statementId}/edit")->assertOk();
    $rows = collect($edit->json('data.rows'));
    expect($edit->json('data.this_payment'))->toBe('10000.00')
        ->and($rows->firstWhere('vendor_id', $sub->id)['this_payment'])->toBe('1000.00');

    $update = $this->putJson("/api/app/v1/lien-waivers/draws/{$statementId}", [
        'this_payment' => '15,000',
        'rows' => [
            ['vendor_id' => $sub->id, 'include' => true, 'kind' => 'Carpentry', 'paid' => 5000, 'this_payment' => '2,000'],
        ],
    ])->assertOk();

    expect($update->json('data.revised'))->toBeGreaterThan(0);
    $subWaiver = LienWaiver::withoutGlobalScopes()->where('sworn_statement_id', $statementId)->where('vendor_id', $sub->id)->first();
    expect((float) $subWaiver->amount)->toBe(7000.0); // 5000 paid + revised 2000
});

it('refuses to edit or update a draw whose GCSS already came back signed', function () {
    ['vendor' => $vendor, 'user' => $admin] = appDrawTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appDrawClient($vendor);
    $project = appDrawProject($vendor, $client);
    $statement = SwornStatement::create([
        'project_id' => $project->id, 'belongs_to_vendor_id' => $vendor->id,
        'this_payment' => 1000, 'status' => LienWaiverStatus::Signed,
    ]);

    $this->getJson("/api/app/v1/lien-waivers/draws/{$statement->id}/edit")
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'Draw 1 has a signed sworn statement and can no longer be edited.']);

    $this->putJson("/api/app/v1/lien-waivers/draws/{$statement->id}", ['this_payment' => '2000', 'rows' => [['vendor_id' => $vendor->id]]])
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'Draw 1 was signed while you were editing — no changes saved.']);
});

it('deletes a draw, soft-removing its statement and every waiver it created', function () {
    ['vendor' => $vendor, 'user' => $admin] = appDrawTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $client = appDrawClient($vendor);
    $project = appDrawProject($vendor, $client);
    $sub = appDrawSubVendor($vendor, $project, 5000);

    $created = $this->postJson('/api/app/v1/lien-waivers/draws', [
        'project_id' => $project->id,
        'this_payment' => '10,000',
        'rows' => [
            ['vendor_id' => $sub->id, 'include' => true, 'kind' => 'Carpentry', 'paid' => 5000, 'this_payment' => '1,000'],
        ],
    ])->assertCreated();
    $statementId = $created->json('data.statement.id');

    $this->deleteJson("/api/app/v1/lien-waivers/draws/{$statementId}")->assertOk();

    expect(SwornStatement::query()->find($statementId))->toBeNull()
        ->and(SwornStatement::withTrashed()->find($statementId))->not->toBeNull()
        ->and(LienWaiver::withoutGlobalScopes()->where('sworn_statement_id', $statementId)->whereNull('deleted_at')->count())->toBe(0);
});

it('404s a draw belonging to another vendor', function () {
    ['vendor' => $vendor, 'user' => $admin] = appDrawTeamUser(role: 1);
    Sanctum::actingAs($admin, ['app']);

    $otherVendor = Vendor::factory()->create();
    $otherClient = Client::factory()->create();
    $otherProject = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Someone Else\'s Job', 'client_id' => $otherClient->id,
        'belongs_to_vendor_id' => $otherVendor->id, 'address' => '9 Other St',
        'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]));
    $otherStatement = SwornStatement::create(['project_id' => $otherProject->id, 'belongs_to_vendor_id' => $otherVendor->id, 'this_payment' => 500]);

    $this->getJson("/api/app/v1/lien-waivers/draws/{$otherStatement->id}")->assertNotFound();
    $this->deleteJson("/api/app/v1/lien-waivers/draws/{$otherStatement->id}")->assertNotFound();
});
