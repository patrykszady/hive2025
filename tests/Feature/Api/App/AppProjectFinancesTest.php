<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as the
 * other Api/App tests. $role is the vendor_user pivot's role_id (1 = Admin,
 * 2 = Member) — ProjectPolicy::viewFinancials is Admin-only for a team
 * member (client-browsing users are a separate, non-app path).
 *
 * @return array{vendor: Vendor, user: User}
 */
function appJobsFinancesTeamUser(int $role = 1): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

function appJobsFinancesProject(Vendor $vendor): Project
{
    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);

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
    ProjectStatus::forceCreate([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'status_code' => 6,
        'start_date' => now(),
    ]);

    return $project;
}

it('needs a token to view project finances', function () {
    $this->getJson('/api/app/v1/projects/1/finances')->assertUnauthorized();
});

it('shows an Admin the same finance fields as the web Finances card, including reimbursments', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsFinancesTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $project = appJobsFinancesProject($vendor);

    $this->getJson("/api/app/v1/projects/{$project->id}/finances")
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['estimate', 'change_orders', 'total_bid', 'reimbursments', 'total_project', 'expenses', 'timesheets', 'total_cost', 'payments', 'balance', 'profit'],
        ]);
});

it('blocks a Member from viewing project finances, like ProjectPolicy::viewFinancials', function () {
    ['vendor' => $vendor, 'user' => $member] = appJobsFinancesTeamUser(2);
    Sanctum::actingAs($member, ['app']);

    $project = appJobsFinancesProject($vendor);

    $this->getJson("/api/app/v1/projects/{$project->id}/finances")->assertForbidden();
});

// --- Reimbursements PDF (ProjectFinances::print_reimbursements()'s app equivalent) ---

it('needs a token for a reimbursements PDF url', function () {
    $this->getJson('/api/app/v1/projects/1/finances/reimbursements-pdf-url')->assertUnauthorized();
});

it('hands an Admin a signed, time-limited reimbursements PDF url', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsFinancesTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $project = appJobsFinancesProject($vendor);

    $response = $this->getJson("/api/app/v1/projects/{$project->id}/finances/reimbursements-pdf-url")
        ->assertOk()
        ->assertJsonStructure(['data' => ['url', 'expires_at']]);

    $url = $response->json('data.url');
    expect($url)->toContain('/projects/'.$project->id.'/finances/reimbursements/signed')
        ->and($url)->toContain('signature=');
});

it('blocks a Member from minting a reimbursements PDF url', function () {
    ['vendor' => $vendor, 'user' => $member] = appJobsFinancesTeamUser(2);
    Sanctum::actingAs($member, ['app']);

    $project = appJobsFinancesProject($vendor);

    $this->getJson("/api/app/v1/projects/{$project->id}/finances/reimbursements-pdf-url")->assertForbidden();
});
