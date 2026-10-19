<?php

use App\Models\Client;
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
 * AppAuthTest's appTeamUser(). $role 1 = Admin, 2 = Member
 * (App\Models\User::roleNames()). business_type 'Sub' matches the real
 * vendor 1 row and is what UserPolicy::create_team_member checks.
 *
 * @return array{vendor: Vendor, user: User}
 */
function appPeopleUsersTeam(int $role = 1, bool $employed = true): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction', 'business_type' => 'Sub']);

    if ($vendor->business_type !== 'Sub') {
        $vendor->forceFill(['business_type' => 'Sub'])->save();
    }

    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, [
        'role_id' => $role,
        'is_employed' => $employed ? 1 : 0,
        'start_date' => today()->format('Y-m-d'),
    ]);

    return compact('vendor', 'user');
}

it('needs a token to list the team', function () {
    $this->getJson('/api/app/v1/users')->assertUnauthorized();
});

it('lists only employed members, with role, and flags who can add one', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPeopleUsersTeam();
    ['user' => $member] = appPeopleUsersTeam(role: 2);
    ['user' => $notEmployed] = appPeopleUsersTeam(role: 2, employed: false);

    Sanctum::actingAs($admin, ['app']);
    $response = $this->getJson('/api/app/v1/users')->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($admin->id, $member->id)
        ->and($ids)->not->toContain($notEmployed->id)
        ->and($response->json('can_add_team_member'))->toBeTrue();

    $adminRow = collect($response->json('data'))->firstWhere('id', $admin->id);
    expect($adminRow['role'])->toBe('Admin')
        ->and($adminRow['is_employed'])->toBeTrue();

    Sanctum::actingAs($member, ['app']);
    $this->getJson('/api/app/v1/users')
        ->assertOk()
        ->assertJsonPath('can_add_team_member', false);
});

it('shows a team member\'s detail, with finance fields gated to who can edit them', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPeopleUsersTeam();
    ['user' => $member] = appPeopleUsersTeam(role: 2);

    Sanctum::actingAs($admin, ['app']);
    $response = $this->getJson('/api/app/v1/users/'.$member->id)->assertOk();

    $response->assertJsonPath('data.id', $member->id)
        ->assertJsonPath('data.role', 'Member')
        ->assertJsonPath('data.can_edit', true)
        ->assertJsonPath('data.can_view_finances', true)
        ->assertJsonStructure(['data' => ['start_date', 'hourly_rate', 'position', 'via_vendor', 'clients']]);

    // A member looking at someone else's detail sees it (same vendor), but
    // not the finance fields — UserDetails.blade.php's @can('update').
    Sanctum::actingAs($member, ['app']);
    $this->getJson('/api/app/v1/users/'.$admin->id)
        ->assertOk()
        ->assertJsonPath('data.can_edit', false)
        ->assertJsonPath('data.can_view_finances', false)
        ->assertJsonPath('data.start_date', null)
        ->assertJsonPath('data.hourly_rate', null);
});

it('403s a user outside this vendor/clients, and 404s a nonexistent one', function () {
    ['user' => $admin] = appPeopleUsersTeam();
    Sanctum::actingAs($admin, ['app']);

    $stranger = User::factory()->create();

    $this->getJson('/api/app/v1/users/'.$stranger->id)->assertForbidden();
    $this->getJson('/api/app/v1/users/999999999')->assertNotFound();
});

it('shows a client person\'s own detail to the admin of a client they are on', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPeopleUsersTeam();
    $client = Client::factory()->create();
    $client->vendors()->attach($vendor->id);
    $person = User::factory()->create(['first_name' => 'Carrie']);
    $client->users()->attach($person->id);

    Sanctum::actingAs($admin, ['app']);
    $this->getJson('/api/app/v1/users/'.$person->id)
        ->assertOk()
        ->assertJsonPath('data.id', $person->id)
        ->assertJsonPath('data.is_employed', false)
        ->assertJsonPath('data.role', null);
});

it('lets me see and edit my own profile regardless of role', function () {
    ['user' => $member] = appPeopleUsersTeam(role: 2);
    Sanctum::actingAs($member, ['app']);

    $this->getJson('/api/app/v1/users/me')
        ->assertOk()
        ->assertJsonPath('data.id', $member->id)
        ->assertJsonPath('data.can_edit', true);

    $this->putJson('/api/app/v1/users/'.$member->id, [
        'first_name' => 'Updated',
        'last_name' => $member->last_name,
        'preferred_language' => 'English',
        'email' => $member->email,
    ])->assertOk()->assertJsonPath('data.first_name', 'Updated');

    expect($member->refresh()->first_name)->toBe('Updated');
});

it('adds a new team member with a role and hourly rate, a member is refused', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPeopleUsersTeam();
    ['user' => $member] = appPeopleUsersTeam(role: 2);

    Sanctum::actingAs($member, ['app']);
    $this->postJson('/api/app/v1/users', [
        'cell_phone' => '2245551212',
        'first_name' => 'New',
        'last_name' => 'Hire',
        'email' => 'new-hire-'.uniqid().'@example.test',
        'role' => 2,
        'hourly_rate' => 25,
    ])->assertForbidden();

    Sanctum::actingAs($admin, ['app']);
    $email = 'new-hire-'.uniqid().'@example.test';
    $response = $this->postJson('/api/app/v1/users', [
        'cell_phone' => '2245551213',
        'first_name' => 'New',
        'last_name' => 'Hire',
        'email' => $email,
        'role' => 2,
        'hourly_rate' => 25,
    ])->assertCreated();

    $hire = User::where('email', $email)->firstOrFail();
    expect($vendor->users()->where('user_id', $hire->id)->wherePivot('is_employed', 1)->exists())->toBeTrue();
    expect((float) $hire->vendors()->where('vendors.id', $vendor->id)->first()->pivot->hourly_rate)->toBe(25.0);
    $response->assertJsonPath('data.role', 'Member');
});

it('422s adding a team member whose phone is already employed here', function () {
    ['vendor' => $vendor, 'user' => $admin] = appPeopleUsersTeam();
    ['user' => $existingMember] = appPeopleUsersTeam(role: 2);
    $existingMember->forceFill(['cell_phone' => '2245559090'])->save();

    Sanctum::actingAs($admin, ['app']);
    $this->postJson('/api/app/v1/users', [
        'cell_phone' => '2245559090',
        'role' => 2,
        'hourly_rate' => 20,
    ])->assertStatus(422)->assertJsonValidationErrors('cell_phone');
});

it('422s an email already used by someone else when editing a user', function () {
    ['user' => $admin] = appPeopleUsersTeam();
    ['user' => $member] = appPeopleUsersTeam(role: 2);
    $taken = User::factory()->create(['email' => 'taken-'.uniqid().'@example.test']);

    Sanctum::actingAs($admin, ['app']);
    $this->putJson('/api/app/v1/users/'.$member->id, [
        'first_name' => $member->first_name,
        'last_name' => $member->last_name,
        'preferred_language' => 'English',
        'email' => $taken->email,
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('never changes cell_phone when editing a user', function () {
    ['user' => $admin] = appPeopleUsersTeam();
    ['user' => $member] = appPeopleUsersTeam(role: 2);
    $originalPhone = $member->cell_phone;

    Sanctum::actingAs($admin, ['app']);
    $this->putJson('/api/app/v1/users/'.$member->id, [
        'first_name' => $member->first_name,
        'last_name' => $member->last_name,
        'preferred_language' => 'English',
        'email' => $member->email,
    ])->assertOk();

    expect($member->refresh()->cell_phone)->toBe($originalPhone);
});

it('refuses a member editing another member', function () {
    ['user' => $memberA] = appPeopleUsersTeam(role: 2);
    ['user' => $memberB] = appPeopleUsersTeam(role: 2);

    Sanctum::actingAs($memberA, ['app']);
    $this->putJson('/api/app/v1/users/'.$memberB->id, [
        'first_name' => 'Hacked',
        'last_name' => $memberB->last_name,
        'preferred_language' => 'English',
        'email' => $memberB->email,
    ])->assertForbidden();
});

it('gets and saves my own notification settings, always forcing realtime sms on', function () {
    ['user' => $user] = appPeopleUsersTeam();
    Sanctum::actingAs($user, ['app']);

    $this->getJson('/api/app/v1/users/me/notification-settings')
        ->assertOk()
        ->assertJsonPath('data.realtime_email', true)
        ->assertJsonPath('data.morning_email', false);

    $this->putJson('/api/app/v1/users/me/notification-settings', [
        'realtime_email' => false,
        'morning_email' => true,
        'morning_sms' => true,
        'evening_email' => false,
        'evening_sms' => false,
        'realtime_start' => '08:00',
        'realtime_end' => '17:00',
    ])->assertOk()
        ->assertJsonPath('data.morning_email', true)
        ->assertJsonPath('data.realtime_start', '08:00');

    expect($user->notificationSetting()->first()->realtime_sms)->toBeTrue();
});

it('422s notification settings with an end time before the start time', function () {
    ['user' => $user] = appPeopleUsersTeam();
    Sanctum::actingAs($user, ['app']);

    $this->putJson('/api/app/v1/users/me/notification-settings', [
        'realtime_email' => true,
        'morning_email' => false,
        'morning_sms' => false,
        'evening_email' => false,
        'evening_sms' => false,
        'realtime_start' => '18:00',
        'realtime_end' => '07:00',
    ])->assertStatus(422)->assertJsonValidationErrors('realtime_end');
});
