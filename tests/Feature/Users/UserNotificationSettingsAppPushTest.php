<?php

use App\Livewire\Users\UserNotificationSettings;
use App\Models\NotificationSetting;
use App\Models\PushToken;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * The users page's Notification Settings card manages the Hive Contractors
 * app next to Email, SMS and Browser (Patryk, 2026-11-08: "can we also
 * allow from the users page where we allow other notifications?"): an App
 * switch in each section writes the same column on every phone, and a
 * "Hive Contractors App" section lists the phones with one switch each.
 */

/** A GS Construction user; role 1 is Admin, 2 is Member (User::vendorRole). */
function appPushSettingsUser(int $roleId = 1): User
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1]);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => $roleId, 'is_employed' => 1]);

    return $user;
}

it('lists the phones signed in to the app, newest first, with each one\'s own switch', function () {
    $user = appPushSettingsUser();
    PushToken::factory()->create(['user_id' => $user->id, 'device_name' => 'Old iPhone', 'enabled' => false, 'last_seen_at' => now()->subDays(3)]);
    PushToken::factory()->create(['user_id' => $user->id, 'device_name' => "Patryk's iPhone", 'last_seen_at' => now()]);

    Livewire::actingAs($user)
        ->test(UserNotificationSettings::class, ['user' => $user])
        ->assertSee("Patryk's iPhone")
        ->assertSee('Old iPhone')
        ->assertSee('Hive Contractors App')
        ->assertSet('appDevices.0.label', "Patryk's iPhone")
        ->assertSet('appDevices.0.enabled', true)
        ->assertSet('appDevices.1.label', 'Old iPhone')
        ->assertSet('appDevices.1.enabled', false)
        ->assertDontSee('No phone yet');
});

it('reads each App switch from the phones: on when any phone has it on', function () {
    $user = appPushSettingsUser();
    PushToken::factory()->create(['user_id' => $user->id, 'morning_enabled' => false, 'evening_enabled' => false, 'sms_inbound_enabled' => true]);
    PushToken::factory()->create(['user_id' => $user->id, 'morning_enabled' => true, 'evening_enabled' => false, 'sms_inbound_enabled' => false]);

    Livewire::actingAs($user)
        ->test(UserNotificationSettings::class, ['user' => $user])
        ->assertSet('app_realtime', true)
        ->assertSet('app_morning', true)
        ->assertSet('app_evening', false)
        ->assertSet('app_text_activity', true);
});

it('an App switch writes that column on every phone and nothing on notification_settings', function () {
    $user = appPushSettingsUser();
    $a = PushToken::factory()->create(['user_id' => $user->id]);
    $b = PushToken::factory()->create(['user_id' => $user->id]);

    $component = Livewire::actingAs($user)->test(UserNotificationSettings::class, ['user' => $user]);
    $settingsBefore = NotificationSetting::where('user_id', $user->id)->first();
    $this->travel(10)->minutes();

    $component->set('app_morning', false)
        ->assertHasNoErrors()
        ->assertSet('app_morning', false);

    // notification_settings is save()'s row; an App switch must not touch it.
    $settingsAfter = NotificationSetting::where('user_id', $user->id)->first();
    expect($a->fresh()->morning_enabled)->toBeFalse()
        ->and($b->fresh()->morning_enabled)->toBeFalse()
        ->and($a->fresh()->realtime_enabled)->toBeTrue()
        ->and($settingsAfter?->updated_at?->toIso8601String())->toBe($settingsBefore?->updated_at?->toIso8601String());

    $component->set('app_text_activity', false)->set('app_evening', false)->set('app_realtime', false);

    expect($a->fresh()->only(PushToken::PREFERENCES))->toBe([
        'realtime_enabled' => false, 'morning_enabled' => false, 'evening_enabled' => false, 'sms_inbound_enabled' => false,
    ])->and($b->fresh()->sms_inbound_enabled)->toBeFalse();

    $component->set('app_morning', true);

    expect($a->fresh()->morning_enabled)->toBeTrue()
        ->and($b->fresh()->morning_enabled)->toBeTrue();
});

it('a phone\'s own switch writes only that row, leaving its preferences and the other phone alone', function () {
    $user = appPushSettingsUser();
    $newest = PushToken::factory()->create(['user_id' => $user->id, 'last_seen_at' => now()]);
    $older = PushToken::factory()->create(['user_id' => $user->id, 'last_seen_at' => now()->subDay()]);

    Livewire::actingAs($user)
        ->test(UserNotificationSettings::class, ['user' => $user])
        ->set('appDevices.1.enabled', false)
        ->assertHasNoErrors()
        ->assertSet('appDevices.1.enabled', false)
        ->assertSet('appDevices.0.enabled', true);

    expect($older->fresh()->enabled)->toBeFalse()
        ->and($older->fresh()->realtime_enabled)->toBeTrue()
        ->and($newest->fresh()->enabled)->toBeTrue();
});

it('never writes another user\'s phones', function () {
    $user = appPushSettingsUser();
    $other = appPushSettingsUser();
    PushToken::factory()->create(['user_id' => $user->id]);
    $theirs = PushToken::factory()->create(['user_id' => $other->id]);

    Livewire::actingAs($user)
        ->test(UserNotificationSettings::class, ['user' => $user])
        ->set('app_realtime', false);

    expect($theirs->fresh()->realtime_enabled)->toBeTrue();
});

it('shows the app\'s text activity switch to an Admin and not to a Member', function () {
    $admin = appPushSettingsUser(1);
    PushToken::factory()->create(['user_id' => $admin->id]);
    $member = appPushSettingsUser(2);
    PushToken::factory()->create(['user_id' => $member->id]);

    Livewire::actingAs($admin)
        ->test(UserNotificationSettings::class, ['user' => $admin])
        ->assertSeeHtml('wire:model.live="app_text_activity"');

    Livewire::actingAs($member)
        ->test(UserNotificationSettings::class, ['user' => $member])
        ->assertDontSeeHtml('wire:model.live="app_text_activity"');
});

it('with no phone yet, says so and leaves the App switches off and disabled', function () {
    $user = appPushSettingsUser();

    Livewire::actingAs($user)
        ->test(UserNotificationSettings::class, ['user' => $user])
        ->assertSee('No phone yet')
        ->assertSet('appDevices', [])
        ->assertSet('app_realtime', false)
        ->assertSet('app_morning', false)
        ->assertSeeHtml('disabled');
});

it('still saves the email and SMS switches to notification_settings exactly as before', function () {
    $user = appPushSettingsUser();
    PushToken::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test(UserNotificationSettings::class, ['user' => $user])
        ->set('morning_email', true)
        ->assertHasNoErrors();

    expect(NotificationSetting::where('user_id', $user->id)->value('morning_email'))->toBeTruthy();
});
