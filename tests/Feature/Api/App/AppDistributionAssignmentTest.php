<?php

use App\Models\Client;
use App\Models\Distribution;
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

/** @return array{vendor: Vendor, admin: User, member: User} */
function appDistAssignTeam(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);

    $admin = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($admin->id, ['role_id' => 1]);

    $member = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($member->id, ['role_id' => 2]);

    return compact('vendor', 'admin', 'member');
}

/** A completed ($status 7) project owned by $vendor, with $profit of profit (via its finances' bid total minus expenses). */
function appDistAssignProject(Vendor $vendor, array $overrides = []): Project
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

    ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'status_code' => 7,
        'start_date' => now()->subDays(10)->format('Y-m-d'),
    ]);

    return $project;
}

function appDistAssignDistribution(Vendor $vendor, User $user, string $name = 'Patryk'): Distribution
{
    return Distribution::create(['vendor_id' => $vendor->id, 'user_id' => $user->id, 'name' => $name]);
}

it('needs a token to list assignable projects', function () {
    $this->getJson('/api/app/v1/distributions/assignable-projects')->assertUnauthorized();
});

it('refuses a Member, like the web (DistributionPolicy::viewAny is Admin-only)', function () {
    ['member' => $member] = appDistAssignTeam();
    Sanctum::actingAs($member, ['app']);

    $this->getJson('/api/app/v1/distributions/assignable-projects')->assertForbidden();
    $this->getJson('/api/app/v1/distributions/assignment-options')->assertForbidden();
});

it('lists completed projects without a split under "Without", and with one under "With"', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appDistAssignTeam();
    Sanctum::actingAs($admin, ['app']);

    $distribution = appDistAssignDistribution($vendor, $admin);
    $withSplit = appDistAssignProject($vendor, ['project_name' => 'Has A Split']);
    $withSplit->distributions()->attach($distribution->id, ['percent' => 100, 'amount' => 0]);
    $withoutSplit = appDistAssignProject($vendor, ['project_name' => 'No Split Yet']);

    $without = $this->getJson('/api/app/v1/distributions/assignable-projects?type=Without')->assertOk();
    $with = $this->getJson('/api/app/v1/distributions/assignable-projects?type=With')->assertOk();

    expect(collect($without->json('data'))->pluck('id')->all())->toBe([$withoutSplit->id])
        ->and(collect($with->json('data'))->pluck('id')->all())->toBe([$withSplit->id]);
});

it('prefills a project\'s existing split, like the web\'s addDis()', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appDistAssignTeam();
    Sanctum::actingAs($admin, ['app']);

    $distribution = appDistAssignDistribution($vendor, $admin);
    $project = appDistAssignProject($vendor);
    $project->distributions()->attach($distribution->id, ['percent' => 60, 'amount' => 123.45]);

    $response = $this->getJson("/api/app/v1/distributions/projects/{$project->id}/assignment")->assertOk();

    $row = collect($response->json('data.rows'))->firstWhere('id', $distribution->id);
    expect($row['percent'])->toBe(60)
        ->and((float) $row['amount'])->toBe(123.45)
        ->and($response->json('data.project.id'))->toBe($project->id);
});

it('assigns a project\'s profit split, computing each distribution\'s amount from the project\'s profit', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appDistAssignTeam();
    Sanctum::actingAs($admin, ['app']);

    $a = appDistAssignDistribution($vendor, $admin, 'Patryk');
    $b = appDistAssignDistribution($vendor, $admin, 'Greg');
    $project = appDistAssignProject($vendor);

    $response = $this->putJson("/api/app/v1/distributions/projects/{$project->id}/assignment", [
        'percents' => [
            ['id' => $a->id, 'percent' => 60],
            ['id' => $b->id, 'percent' => 40],
        ],
    ])->assertOk();

    $profit = (float) data_get($project->fresh()->finances ?? [], 'profit', 0);
    $pivotA = $project->distributions()->whereKey($a->id)->first()->pivot;
    $pivotB = $project->distributions()->whereKey($b->id)->first()->pivot;

    expect((int) $pivotA->percent)->toBe(60)
        ->and((float) $pivotA->amount)->toBe(round($profit * 0.6, 2))
        ->and((int) $pivotB->percent)->toBe(40)
        ->and((float) $pivotB->amount)->toBe(round($profit * 0.4, 2))
        ->and($response->json('data.id'))->toBe($project->id);
});

it('refuses a split that does not sum to 100%, like the web', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appDistAssignTeam();
    Sanctum::actingAs($admin, ['app']);

    $a = appDistAssignDistribution($vendor, $admin);
    $project = appDistAssignProject($vendor);

    $this->putJson("/api/app/v1/distributions/projects/{$project->id}/assignment", [
        'percents' => [['id' => $a->id, 'percent' => 50]],
    ])->assertStatus(422)->assertJsonValidationErrors('percents');

    expect($project->distributions()->count())->toBe(0);
});

it('assigns every completed project without a split in one bulk call', function () {
    ['vendor' => $vendor, 'admin' => $admin] = appDistAssignTeam();
    Sanctum::actingAs($admin, ['app']);

    $distribution = appDistAssignDistribution($vendor, $admin);
    $already = appDistAssignProject($vendor, ['project_name' => 'Already Split']);
    $already->distributions()->attach($distribution->id, ['percent' => 100, 'amount' => 0]);
    $one = appDistAssignProject($vendor, ['project_name' => 'One']);
    $two = appDistAssignProject($vendor, ['project_name' => 'Two']);

    $response = $this->postJson('/api/app/v1/distributions/assignment/bulk', [
        'percents' => [['id' => $distribution->id, 'percent' => 100]],
    ])->assertOk();

    expect($response->json('data.assigned'))->toBe(2)
        ->and($one->distributions()->count())->toBe(1)
        ->and($two->distributions()->count())->toBe(1)
        // Already-split projects are left alone — storeBulk() only ever
        // targets projects whereDoesntHave('distributions').
        ->and((int) $already->distributions()->first()->pivot->percent)->toBe(100);
});
