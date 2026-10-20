<?php

use App\Models\Bid;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateSection;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * AppPaymentsTest's appPaymentsTeamUser(). $role is the vendor_user pivot's
 * role_id (1 = Admin, 2 = Member) — BidPolicy::create is Admin-only
 * (and blocks a 1099 vendor).
 *
 * @return array{vendor: Vendor, user: User}
 */
function appJobsBidsTeamUser(int $role = 1, string $businessType = 'GC'): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction', 'business_type' => $businessType]);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $role]);

    return compact('vendor', 'user');
}

function appJobsBidsProject(Vendor $vendor): Project
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

    return $project;
}

it('needs a token to view or write bids', function () {
    $this->getJson('/api/app/v1/projects/1/vendors/1/bids')->assertUnauthorized();
    $this->postJson('/api/app/v1/projects/1/vendors/1/bids')->assertUnauthorized();
});

it('offers a default Original Bid row when nothing has been bid yet, like BidCreate::addBids()', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsBidsTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $project = appJobsBidsProject($vendor);

    $this->getJson("/api/app/v1/projects/{$project->id}/vendors/{$vendor->id}/bids")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Original Bid')
        ->assertJsonPath('data.0.amount', null);
});

it('lets an Admin add an original bid and a change order', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsBidsTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $project = appJobsBidsProject($vendor);

    $this->postJson("/api/app/v1/projects/{$project->id}/vendors/{$vendor->id}/bids", [
        'bids' => [['amount' => 15000, 'type' => 1]],
    ])->assertCreated()->assertJsonPath('data.0.amount', 15000);

    $original = Bid::withoutGlobalScopes()->where('project_id', $project->id)->where('type', 1)->first();
    expect((float) $original->amount)->toBe(15000.0);

    // A change order updates the same bid by id, same resolve-through-this-
    // project's-own-bids-for-this-vendor guard as BidForm::store().
    $this->postJson("/api/app/v1/projects/{$project->id}/vendors/{$vendor->id}/bids", [
        'bids' => [
            ['id' => $original->id, 'amount' => 15500],
            ['amount' => 2000, 'type' => 2],
        ],
    ])->assertCreated();

    expect($original->fresh()->amount)->toEqual(15500)
        ->and(Bid::withoutGlobalScopes()->where('project_id', $project->id)->count())->toBe(2);
});

it('lists existing bids as Original Bid then Change Order 1, like BidCreate::addBids()', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsBidsTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $project = appJobsBidsProject($vendor);

    $this->postJson("/api/app/v1/projects/{$project->id}/vendors/{$vendor->id}/bids", [
        'bids' => [['amount' => 15000, 'type' => 1], ['amount' => 2000, 'type' => 2]],
    ])->assertCreated();

    $this->getJson("/api/app/v1/projects/{$project->id}/vendors/{$vendor->id}/bids")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Original Bid')
        ->assertJsonPath('data.0.amount', 15000)
        ->assertJsonPath('data.0.project_id', $project->id)
        ->assertJsonPath('data.0.vendor_id', $vendor->id)
        ->assertJsonPath('data.1.name', 'Change Order 1')
        ->assertJsonPath('data.1.amount', 2000);
});

it('blocks a Member and a 1099 vendor from writing bids, like BidPolicy::create', function () {
    ['vendor' => $vendor, 'user' => $member] = appJobsBidsTeamUser(2);
    Sanctum::actingAs($member, ['app']);

    $project = appJobsBidsProject($vendor);

    $this->postJson("/api/app/v1/projects/{$project->id}/vendors/{$vendor->id}/bids", [
        'bids' => [['amount' => 100, 'type' => 1]],
    ])->assertForbidden();

    $oneOhNineNineVendor = Vendor::factory()->create(['business_type' => '1099']);
    $admin1099 = User::factory()->create(['primary_vendor_id' => $oneOhNineNineVendor->id]);
    $oneOhNineNineVendor->users()->attach($admin1099->id, ['role_id' => 1]);
    Sanctum::actingAs($admin1099, ['app']);

    $project2 = appJobsBidsProject($oneOhNineNineVendor);

    $this->postJson("/api/app/v1/projects/{$project2->id}/vendors/{$oneOhNineNineVendor->id}/bids", [
        'bids' => [['amount' => 100, 'type' => 1]],
    ])->assertForbidden();

    expect(Bid::withoutGlobalScopes()->count())->toBe(0);
});

it('cannot update another tenant\'s bid by forging its id', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsBidsTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $otherVendor = Vendor::factory()->create();
    $otherProject = appJobsBidsProject($otherVendor);
    $otherBid = Bid::withoutGlobalScopes()->create([
        'project_id' => $otherProject->id, 'vendor_id' => $otherVendor->id, 'amount' => 999, 'type' => 1,
    ]);

    $project = appJobsBidsProject($vendor);

    $this->postJson("/api/app/v1/projects/{$project->id}/vendors/{$vendor->id}/bids", [
        'bids' => [['id' => $otherBid->id, 'amount' => 1]],
    ])->assertStatus(404);

    expect($otherBid->fresh()->amount)->toEqual(999);
});

// --- Remove a bid/change order (App\Livewire\Bids\BidCreate::removeChangeOrder()) ---

it('needs a token to remove a bid', function () {
    $this->deleteJson('/api/app/v1/projects/1/vendors/1/bids/1')->assertUnauthorized();
});

it('lets an Admin remove a change order', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsBidsTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $project = appJobsBidsProject($vendor);
    $original = Bid::withoutGlobalScopes()->create(['project_id' => $project->id, 'vendor_id' => $vendor->id, 'amount' => 15000, 'type' => 1]);
    $changeOrder = Bid::withoutGlobalScopes()->create(['project_id' => $project->id, 'vendor_id' => $vendor->id, 'amount' => 2000, 'type' => 2]);

    $this->deleteJson("/api/app/v1/projects/{$project->id}/vendors/{$vendor->id}/bids/{$changeOrder->id}")
        ->assertOk()
        ->assertJsonPath('data.deleted', true);

    expect(Bid::withoutGlobalScopes()->whereKey($changeOrder->id)->exists())->toBeFalse()
        ->and(Bid::withoutGlobalScopes()->whereKey($original->id)->exists())->toBeTrue();
});

it('cannot remove the Original Bid, like the web\'s "Remove" button hidden on the first row', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsBidsTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $project = appJobsBidsProject($vendor);
    $original = Bid::withoutGlobalScopes()->create(['project_id' => $project->id, 'vendor_id' => $vendor->id, 'amount' => 15000, 'type' => 1]);

    $this->deleteJson("/api/app/v1/projects/{$project->id}/vendors/{$vendor->id}/bids/{$original->id}")
        ->assertStatus(422);

    expect(Bid::withoutGlobalScopes()->whereKey($original->id)->exists())->toBeTrue();
});

it('cannot remove a bid an estimate section already references', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsBidsTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $project = appJobsBidsProject($vendor);
    $changeOrder = Bid::withoutGlobalScopes()->create(['project_id' => $project->id, 'vendor_id' => $vendor->id, 'amount' => 2000, 'type' => 2]);
    $estimate = Estimate::create(['project_id' => $project->id, 'belongs_to_vendor_id' => $vendor->id]);
    EstimateSection::create(['estimate_id' => $estimate->id, 'order' => 1, 'name' => 'Section', 'total' => 2000, 'bid_id' => $changeOrder->id]);

    $this->deleteJson("/api/app/v1/projects/{$project->id}/vendors/{$vendor->id}/bids/{$changeOrder->id}")
        ->assertStatus(422);

    expect(Bid::withoutGlobalScopes()->whereKey($changeOrder->id)->exists())->toBeTrue();
});

it('blocks a Member from removing a bid, like BidPolicy::delete', function () {
    ['vendor' => $vendor, 'user' => $member] = appJobsBidsTeamUser(2);
    Sanctum::actingAs($member, ['app']);

    $project = appJobsBidsProject($vendor);
    $changeOrder = Bid::withoutGlobalScopes()->create(['project_id' => $project->id, 'vendor_id' => $vendor->id, 'amount' => 2000, 'type' => 2]);

    $this->deleteJson("/api/app/v1/projects/{$project->id}/vendors/{$vendor->id}/bids/{$changeOrder->id}")->assertForbidden();

    expect(Bid::withoutGlobalScopes()->whereKey($changeOrder->id)->exists())->toBeTrue();
});

it('cannot remove another tenant\'s bid by forging its id', function () {
    ['vendor' => $vendor, 'user' => $user] = appJobsBidsTeamUser(1);
    Sanctum::actingAs($user, ['app']);

    $otherVendor = Vendor::factory()->create();
    $otherProject = appJobsBidsProject($otherVendor);
    $otherChangeOrder = Bid::withoutGlobalScopes()->create(['project_id' => $otherProject->id, 'vendor_id' => $otherVendor->id, 'amount' => 2000, 'type' => 2]);

    $project = appJobsBidsProject($vendor);

    $this->deleteJson("/api/app/v1/projects/{$project->id}/vendors/{$vendor->id}/bids/{$otherChangeOrder->id}")
        ->assertStatus(404);

    expect(Bid::withoutGlobalScopes()->whereKey($otherChangeOrder->id)->exists())->toBeTrue();
});
