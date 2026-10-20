<?php

use App\Models\AppNotification;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/**
 * GS Construction is vendor id 1 — pinned explicitly, same reasoning as
 * AppPlannerTest's appPlannerTeam().
 *
 * @return array{vendor: Vendor, user: User}
 */
function appNotificationsTeamUser(): array
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1]);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);

    return compact('vendor', 'user');
}

/** created_at isn't mass-assignable (not in AppNotification::$fillable), so it's forced on afterward — Eloquent won't overwrite an already-dirty created_at on insert. */
function appNotificationsRow(User $user, array $overrides = []): AppNotification
{
    $createdAt = $overrides['created_at'] ?? now();
    unset($overrides['created_at']);

    $notification = AppNotification::create(array_merge([
        'user_id' => $user->id,
        'type' => 'sms_received',
        'title' => 'New Text from Jane',
        'body' => 'Can you stop by Tuesday?',
        'action_url' => null,
    ], $overrides));

    $notification->forceFill(['created_at' => $createdAt])->save();

    return $notification;
}

it('needs a token for every notifications endpoint', function () {
    $this->getJson('/api/app/v1/notifications')->assertUnauthorized();
    $this->getJson('/api/app/v1/notifications/unread-count')->assertUnauthorized();
    $this->postJson('/api/app/v1/notifications/1/read')->assertUnauthorized();
    $this->postJson('/api/app/v1/notifications/read-all')->assertUnauthorized();
});

it('lists notifications newest first, with the unread count', function () {
    ['user' => $user] = appNotificationsTeamUser();
    Sanctum::actingAs($user, ['app']);

    $older = appNotificationsRow($user, ['title' => 'Older', 'created_at' => now()->subDay()]);
    $newer = appNotificationsRow($user, ['title' => 'Newer', 'created_at' => now()]);

    $response = $this->getJson('/api/app/v1/notifications')->assertOk();

    expect(collect($response->json('data'))->pluck('title')->all())->toBe(['Newer', 'Older'])
        ->and($response->json('unread_count'))->toBe(2);
});

it('collapses identical notifications on the same day, but not across different days', function () {
    ['user' => $user] = appNotificationsTeamUser();
    Sanctum::actingAs($user, ['app']);

    $today1 = appNotificationsRow($user, ['title' => 'Missed Call', 'type' => 'missed_call', 'body' => 'From (224) 555-0100', 'created_at' => now()]);
    $today2 = appNotificationsRow($user, ['title' => 'Missed Call', 'type' => 'missed_call', 'body' => 'From (224) 555-0100', 'created_at' => now()->subMinute()]);
    $yesterday = appNotificationsRow($user, ['title' => 'Missed Call', 'type' => 'missed_call', 'body' => 'From (224) 555-0100', 'created_at' => now()->subDay()]);

    $response = $this->getJson('/api/app/v1/notifications')->assertOk();
    $data = collect($response->json('data'));

    expect($data)->toHaveCount(2);

    $todayEntry = $data->firstWhere('date_label', 'Today');
    expect($todayEntry['count'])->toBe(2)
        ->and($todayEntry['ids'])->toEqualCanonicalizing([$today1->id, $today2->id])
        ->and($todayEntry['unread'])->toBeTrue();

    $yesterdayEntry = $data->firstWhere('date_label', 'Yesterday');
    expect($yesterdayEntry['count'])->toBe(1)
        ->and($yesterdayEntry['ids'])->toBe([$yesterday->id]);
});

it('marks a whole collapsed group read from one id, updating the unread count', function () {
    ['user' => $user] = appNotificationsTeamUser();
    Sanctum::actingAs($user, ['app']);

    $first = appNotificationsRow($user, ['title' => 'Voicemail', 'type' => 'voicemail_received', 'created_at' => now()]);
    $second = appNotificationsRow($user, ['title' => 'Voicemail', 'type' => 'voicemail_received', 'created_at' => now()->subMinute()]);
    $unrelated = appNotificationsRow($user, ['title' => 'Different', 'created_at' => now()]);

    $response = $this->postJson("/api/app/v1/notifications/{$first->id}/read")->assertOk();

    expect($response->json('data.marked'))->toBeTrue()
        ->and($response->json('data.unread_count'))->toBe(1);

    expect($first->refresh()->read_at)->not->toBeNull()
        ->and($second->refresh()->read_at)->not->toBeNull()
        ->and($unrelated->refresh()->read_at)->toBeNull();
});

it('marks every notification read with mark-all', function () {
    ['user' => $user] = appNotificationsTeamUser();
    Sanctum::actingAs($user, ['app']);

    appNotificationsRow($user);
    appNotificationsRow($user, ['title' => 'Second']);

    $response = $this->postJson('/api/app/v1/notifications/read-all')->assertOk();

    expect($response->json('data.unread_count'))->toBe(0)
        ->and(AppNotification::where('user_id', $user->id)->whereNull('read_at')->count())->toBe(0);
});

it('404s marking someone else\'s notification read, and never marks it', function () {
    ['user' => $user] = appNotificationsTeamUser();
    $other = User::factory()->create(['primary_vendor_id' => $user->primary_vendor_id]);
    $foreign = appNotificationsRow($other);

    Sanctum::actingAs($user, ['app']);

    $this->postJson("/api/app/v1/notifications/{$foreign->id}/read")->assertNotFound();

    expect($foreign->refresh()->read_at)->toBeNull();
});

it('classifies action_url into a deep link the app can navigate to', function () {
    ['user' => $user] = appNotificationsTeamUser();
    Sanctum::actingAs($user, ['app']);

    appNotificationsRow($user, ['title' => 'A', 'action_url' => '/messages?threadId=42&messageId=7']);
    appNotificationsRow($user, ['title' => 'B', 'action_url' => '/messages?activeTab=calls&callId=9']);
    appNotificationsRow($user, ['title' => 'C', 'action_url' => 'https://hive.contractors/projects/5']);
    appNotificationsRow($user, ['title' => 'D', 'action_url' => 'https://hive.contractors/planner/cards']);
    appNotificationsRow($user, ['title' => 'E', 'action_url' => 'https://hive.contractors/leads?lead=3']);
    appNotificationsRow($user, ['title' => 'F', 'action_url' => null]);

    $response = $this->getJson('/api/app/v1/notifications')->assertOk();
    $byTitle = collect($response->json('data'))->keyBy('title');

    expect($byTitle['A']['target'])->toBe(['type' => 'thread', 'id' => 42, 'path' => null, 'title' => null])
        ->and($byTitle['B']['target'])->toBe(['type' => 'call', 'id' => 9, 'path' => null, 'title' => null])
        ->and($byTitle['C']['target'])->toBe(['type' => 'project', 'id' => 5, 'path' => null, 'title' => null])
        ->and($byTitle['D']['target'])->toBe(['type' => 'planner', 'id' => null, 'path' => null, 'title' => null])
        ->and($byTitle['E']['target'])->toBe(['type' => 'web', 'id' => null, 'path' => '/leads?lead=3', 'title' => 'Leads'])
        ->and($byTitle['F']['target'])->toBe(['type' => 'none', 'id' => null, 'path' => null, 'title' => null]);
});

it('labels a notification\'s day as Today/Yesterday/a short date', function () {
    ['user' => $user] = appNotificationsTeamUser();
    Sanctum::actingAs($user, ['app']);

    Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'America/Chicago'));

    appNotificationsRow($user, ['title' => 'A', 'created_at' => now()]);
    appNotificationsRow($user, ['title' => 'B', 'created_at' => now()->subDay()]);
    appNotificationsRow($user, ['title' => 'C', 'created_at' => now()->subDays(5)]);

    $response = $this->getJson('/api/app/v1/notifications')->assertOk();
    $byTitle = collect($response->json('data'))->keyBy('title');

    expect($byTitle['A']['date_label'])->toBe('Today')
        ->and($byTitle['B']['date_label'])->toBe('Yesterday')
        ->and($byTitle['C']['date_label'])->toBe('Sat, Oct 10');

    Carbon::setTestNow();
});

it('keeps the web notification bell\'s own Livewire components in sync with the API', function () {
    ['user' => $user] = appNotificationsTeamUser();

    appNotificationsRow($user, ['title' => 'Parity Check']);

    $this->actingAs($user);
    Livewire::test(\App\Livewire\Notifications\NotificationSidebarBadge::class)
        ->assertViewHas('count', 1);

    Livewire::test(\App\Livewire\Notifications\NotificationIndex::class)
        ->assertSet('unreadCount', 1);

    Sanctum::actingAs($user, ['app']);
    $response = $this->getJson('/api/app/v1/notifications')->assertOk();

    expect($response->json('unread_count'))->toBe(1);
});

it('groups and labels by the Chicago day, so an evening notification is not split from its afternoon twin', function () {
    // 8 PM Chicago on Oct 19 is already Oct 20 in UTC.
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-20 01:00:00', 'UTC'));
    ['user' => $user] = appNotificationsTeamUser();
    Sanctum::actingAs($user, ['app']);

    $evening = appNotificationsRow($user, ['title' => 'Missed Call', 'type' => 'missed_call', 'body' => 'From (224) 555-0100', 'created_at' => now()]);
    $afternoon = appNotificationsRow($user, ['title' => 'Missed Call', 'type' => 'missed_call', 'body' => 'From (224) 555-0100', 'created_at' => now()->subHours(3)]);
    $yesterday = appNotificationsRow($user, ['title' => 'Missed Call', 'type' => 'missed_call', 'body' => 'From (224) 555-0100', 'created_at' => now()->subDay()]);

    $data = collect($this->getJson('/api/app/v1/notifications')->assertOk()->json('data'));

    expect($data)->toHaveCount(2);
    expect($data->firstWhere('date_label', 'Today')['ids'])->toEqualCanonicalizing([$evening->id, $afternoon->id]);
    expect($data->firstWhere('date_label', 'Yesterday')['ids'])->toBe([$yesterday->id]);

    $this->postJson("/api/app/v1/notifications/{$evening->id}/read")->assertOk();

    expect($afternoon->refresh()->read_at)->not->toBeNull()
        ->and($yesterday->refresh()->read_at)->toBeNull();
});

