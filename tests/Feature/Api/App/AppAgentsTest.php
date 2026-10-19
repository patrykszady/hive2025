<?php

use App\Models\Agent;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDoc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
    // Workers-comp docs queue the state lookup — see AgentsIndexGroupingTest.
    Queue::fake();
});

/**
 * @return array{vendor: Vendor, user: User}
 *
 * The seeded Pest DB already carries ~500 users, including id 1 (Patryk —
 * see test-db-has-seeded-users.md), so AgentController's `auth()->id() ===
 * 1` check is tested against that real row rather than inserting a
 * conflicting one.
 */
function appAgentsTeamUser(int $userId): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    if ($userId === 1) {
        $user = User::query()->findOrFail(1);
        $user->forceFill(['primary_vendor_id' => $vendor->id])->save();
    } else {
        $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    }
    $vendor->users()->syncWithoutDetaching([$user->id => ['role_id' => 1]]);

    return compact('vendor', 'user');
}

it('needs a token to list agents', function () {
    $this->getJson('/api/app/v1/agents')->assertUnauthorized();
});

it('refuses anyone but user id 1, even an Admin, like the web', function () {
    ['user' => $admin] = appAgentsTeamUser(userId: 50);
    Sanctum::actingAs($admin, ['app']);

    $this->getJson('/api/app/v1/agents')->assertForbidden();
});

it('groups agents by business name for user id 1, same as AgentsIndex', function () {
    ['user' => $patryk] = appAgentsTeamUser(userId: 1);
    Sanctum::actingAs($patryk, ['app']);

    Agent::query()->create(['business_name' => 'Biz Broker Inc', 'name' => 'Biz Broker Inc', 'email' => 'cert@biz1040.com', 'address' => '3357 N Harlem Ave']);
    Agent::query()->create(['business_name' => 'Biz Broker, Inc.', 'name' => 'Biz Broker, Inc.', 'email' => 'certificates@biz1040.com', 'address' => '3357 N Harlem Ave']);

    $response = $this->getJson('/api/app/v1/agents')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and(collect($response->json('data.0.agents'))->pluck('email')->sort()->values()->all())
        ->toBe(['cert@biz1040.com', 'certificates@biz1040.com']);
});

it('lists vendor docs for the chosen agent ids', function () {
    ['vendor' => $vendor, 'user' => $patryk] = appAgentsTeamUser(userId: 1);
    Sanctum::actingAs($patryk, ['app']);

    $agent = Agent::query()->create(['business_name' => 'Biz Broker Inc', 'name' => 'One', 'email' => 'one@example.test']);
    $doc = VendorDoc::withoutGlobalScopes()->forceCreate([
        'type' => 'workers', 'vendor_id' => $vendor->id, 'belongs_to_vendor_id' => $vendor->id,
        'agent_id' => $agent->id, 'number' => 'W-1',
        'effective_date' => now()->subMonth()->toDateString(), 'expiration_date' => now()->addMonth()->toDateString(),
        'doc_filename' => 'a.pdf',
    ]);

    $response = $this->getJson("/api/app/v1/agents/vendor-docs?agent_ids[]={$agent->id}")->assertOk();

    expect(collect($response->json('data'))->pluck('number')->all())->toBe([$doc->number]);
});
