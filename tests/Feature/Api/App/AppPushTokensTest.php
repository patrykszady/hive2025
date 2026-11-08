<?php

use App\Models\PushToken;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * every other app test (see AppAuthTest::appTeamUser()).
 */
function appPushTokensTeamUser(): User
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1]);

    return User::factory()->create(['primary_vendor_id' => $vendor->id]);
}

it('needs a token for every push-tokens endpoint', function () {
    $this->postJson('/api/app/v1/push-tokens', ['token' => 'x', 'platform' => 'ios'])->assertUnauthorized();
    $this->deleteJson('/api/app/v1/push-tokens', ['token' => 'x'])->assertUnauthorized();
});

it('registers a new push token for the authenticated user', function () {
    $user = appPushTokensTeamUser();
    Sanctum::actingAs($user, ['app']);

    $this->postJson('/api/app/v1/push-tokens', [
        'token' => 'ExponentPushToken[abc123]',
        'platform' => 'ios',
        'device_name' => "Patryk's iPhone",
    ])->assertCreated()->assertExactJson(['registered' => true]);

    expect(PushToken::count())->toBe(1);
    $row = PushToken::first();
    expect($row->user_id)->toBe($user->id)
        ->and($row->token)->toBe('ExponentPushToken[abc123]')
        ->and($row->platform)->toBe('ios')
        ->and($row->device_name)->toBe("Patryk's iPhone");
});

it('re-registering the same token updates the row in place rather than duplicating it', function () {
    $user = appPushTokensTeamUser();
    Sanctum::actingAs($user, ['app']);

    $this->postJson('/api/app/v1/push-tokens', [
        'token' => 'ExponentPushToken[abc123]',
        'platform' => 'ios',
        'device_name' => 'Old Name',
    ])->assertCreated();

    $this->postJson('/api/app/v1/push-tokens', [
        'token' => 'ExponentPushToken[abc123]',
        'platform' => 'ios',
        'device_name' => 'New Name',
    ])->assertCreated();

    expect(PushToken::count())->toBe(1);
    expect(PushToken::first()->device_name)->toBe('New Name');
});

it('reassigns a token already registered to a different user, logging the handoff', function () {
    $owner = appPushTokensTeamUser();
    $newUser = User::factory()->create(['primary_vendor_id' => $owner->primary_vendor_id]);
    $staleRow = PushToken::factory()->create([
        'user_id' => $owner->id,
        'token' => 'ExponentPushToken[shared-device]',
    ]);

    Log::spy();
    Sanctum::actingAs($newUser, ['app']);

    $this->postJson('/api/app/v1/push-tokens', [
        'token' => 'ExponentPushToken[shared-device]',
        'platform' => 'ios',
    ])->assertCreated();

    expect(PushToken::count())->toBe(1);
    expect(PushToken::first())
        ->user_id->toBe($newUser->id)
        ->id->not->toBe($staleRow->id);

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => $message === 'Push token reassigned from one user to another'
            && $context['token'] === 'ExponentPushToken[shared-device]'
            && $context['from_user_id'] === $owner->id
            && $context['to_user_id'] === $newUser->id,
    );
});

it('rejects an unknown platform value', function () {
    $user = appPushTokensTeamUser();
    Sanctum::actingAs($user, ['app']);

    $this->postJson('/api/app/v1/push-tokens', [
        'token' => 'ExponentPushToken[abc123]',
        'platform' => 'windows-phone',
    ])->assertStatus(422)->assertJsonValidationErrors(['platform']);
});

it('stamps the current Sanctum access token id on registration', function () {
    $user = appPushTokensTeamUser();
    $token = $user->createToken('Device A', ['app']);

    $this->withToken($token->plainTextToken)->postJson('/api/app/v1/push-tokens', [
        'token' => 'ExponentPushToken[abc123]',
        'platform' => 'ios',
    ])->assertCreated();

    expect(PushToken::first()->personal_access_token_id)->toBe($token->accessToken->id);
});

it('deletes the caller\'s own token', function () {
    $user = appPushTokensTeamUser();
    $row = PushToken::factory()->create(['user_id' => $user->id, 'token' => 'ExponentPushToken[mine]']);
    Sanctum::actingAs($user, ['app']);

    $this->deleteJson('/api/app/v1/push-tokens', ['token' => 'ExponentPushToken[mine]'])
        ->assertOk()->assertExactJson(['unregistered' => true]);

    expect(PushToken::find($row->id))->toBeNull();
});

it('refuses to delete another user\'s token', function () {
    $owner = appPushTokensTeamUser();
    $intruder = User::factory()->create(['primary_vendor_id' => $owner->primary_vendor_id]);
    $row = PushToken::factory()->create(['user_id' => $owner->id, 'token' => 'ExponentPushToken[owner]']);

    Sanctum::actingAs($intruder, ['app']);

    $this->deleteJson('/api/app/v1/push-tokens', ['token' => 'ExponentPushToken[owner]'])->assertOk();

    // 200 either way (no row was ever this caller's to begin with), but the
    // owner's row must survive untouched.
    expect(PushToken::find($row->id))->not->toBeNull();
});

it('logout deletes only that device\'s push token, leaving other devices registered', function () {
    $user = appPushTokensTeamUser();
    $deviceA = $user->createToken('Device A', ['app']);
    $deviceB = $user->createToken('Device B', ['app']);

    $this->withToken($deviceA->plainTextToken)->postJson('/api/app/v1/push-tokens', [
        'token' => 'ExponentPushToken[device-a]',
        'platform' => 'ios',
    ])->assertCreated();

    // Sanctum's "sanctum" guard memoizes the resolved user (and its
    // access token) for the lifetime of the shared, within-test
    // Application container — same reasoning as AppAuthTest's logout test.
    auth()->forgetGuards();

    $this->withToken($deviceB->plainTextToken)->postJson('/api/app/v1/push-tokens', [
        'token' => 'ExponentPushToken[device-b]',
        'platform' => 'ios',
    ])->assertCreated();

    expect(PushToken::count())->toBe(2);

    auth()->forgetGuards();

    $this->withToken($deviceA->plainTextToken)->postJson('/api/app/v1/logout')->assertOk();

    expect(PushToken::count())->toBe(1);
    expect(PushToken::first()->token)->toBe('ExponentPushToken[device-b]');
});

it('a first phone starts with text activity on for an Admin and off for a Member, the other switches on', function () {
    $admin = appPushTokensTeamUser();
    Vendor::find(1)->users()->attach($admin->id, ['role_id' => 1, 'is_employed' => 1]);
    $member = User::factory()->create(['primary_vendor_id' => 1]);
    Vendor::find(1)->users()->attach($member->id, ['role_id' => 2, 'is_employed' => 1]);

    Sanctum::actingAs($admin, ['app']);
    $this->postJson('/api/app/v1/push-tokens', ['token' => 'ExponentPushToken[admin]', 'platform' => 'ios'])->assertCreated();

    auth()->forgetGuards();
    Sanctum::actingAs($member, ['app']);
    $this->postJson('/api/app/v1/push-tokens', ['token' => 'ExponentPushToken[member]', 'platform' => 'ios'])->assertCreated();

    $adminRow = PushToken::where('token', 'ExponentPushToken[admin]')->first();
    $memberRow = PushToken::where('token', 'ExponentPushToken[member]')->first();

    expect($adminRow->enabled)->toBeTrue()
        ->and($adminRow->only(PushToken::PREFERENCES))->toBe([
            'realtime_enabled' => true, 'morning_enabled' => true, 'evening_enabled' => true, 'sms_inbound_enabled' => true,
        ])
        ->and($memberRow->only(PushToken::PREFERENCES))->toBe([
            'realtime_enabled' => true, 'morning_enabled' => true, 'evening_enabled' => true, 'sms_inbound_enabled' => false,
        ]);
});

it('a reinstall inherits the switches set for the user\'s other phone, and re-registering never resets them', function () {
    $user = appPushTokensTeamUser();
    PushToken::factory()->create([
        'user_id' => $user->id,
        'token' => 'ExponentPushToken[first]',
        'enabled' => false,
        'morning_enabled' => false,
        'sms_inbound_enabled' => false,
        'last_seen_at' => now()->subDay(),
    ]);
    Sanctum::actingAs($user, ['app']);

    // A reinstall rotated the Expo token: new row, the first phone's switches.
    $this->postJson('/api/app/v1/push-tokens', ['token' => 'ExponentPushToken[second]', 'platform' => 'ios'])->assertCreated();

    $second = PushToken::where('token', 'ExponentPushToken[second]')->first();
    expect($second->enabled)->toBeTrue()
        ->and($second->morning_enabled)->toBeFalse()
        ->and($second->sms_inbound_enabled)->toBeFalse()
        ->and($second->realtime_enabled)->toBeTrue();

    // The users page switches realtime off on this phone; the next app
    // launch re-registers the same token and must leave that alone.
    $second->update(['realtime_enabled' => false, 'enabled' => false]);

    $this->postJson('/api/app/v1/push-tokens', ['token' => 'ExponentPushToken[second]', 'platform' => 'ios', 'device_name' => 'Renamed'])->assertCreated();

    $second->refresh();
    expect($second->device_name)->toBe('Renamed')
        ->and($second->realtime_enabled)->toBeFalse()
        ->and($second->enabled)->toBeFalse()
        ->and(PushToken::count())->toBe(2);
});

it('a token handed to a new user starts from that user\'s own switches, not the previous owner\'s', function () {
    $owner = appPushTokensTeamUser();
    $newUser = User::factory()->create(['primary_vendor_id' => $owner->primary_vendor_id]);
    PushToken::factory()->create(['user_id' => $owner->id, 'token' => 'ExponentPushToken[shared]', 'morning_enabled' => false]);
    PushToken::factory()->create(['user_id' => $newUser->id, 'token' => 'ExponentPushToken[theirs]', 'evening_enabled' => false]);

    Sanctum::actingAs($newUser, ['app']);
    $this->postJson('/api/app/v1/push-tokens', ['token' => 'ExponentPushToken[shared]', 'platform' => 'ios'])->assertCreated();

    $row = PushToken::where('token', 'ExponentPushToken[shared]')->first();
    expect($row->user_id)->toBe($newUser->id)
        ->and($row->morning_enabled)->toBeTrue()
        ->and($row->evening_enabled)->toBeFalse();
});
