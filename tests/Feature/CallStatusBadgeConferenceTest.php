<?php

use App\Livewire\Sms\CallStatusBadge;
use App\Models\CallLog;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The "In a call" badge (and its "Add Participant" button) must light up for
 * the admin who ANSWERED an inbound simulring — not only for the person who
 * placed an outbound call. On inbound, CallLog.user_id is the external caller,
 * so the old owner-only lookup left the answering admin with no badge and no
 * way to pull in a teammate.
 */
uses(RefreshDatabase::class);

function badgeUser(string $first = 'Admin'): User
{
    return User::query()->create([
        'first_name' => $first,
        'last_name' => 'User',
        'email' => 'badge-'.Str::random(8).'@example.test',
        'cell_phone' => '224'.random_int(1000000, 9999999),
        'password' => bcrypt('secret'),
        'remember_token' => Str::random(10),
    ]);
}

it('lights the badge for an admin who joined an inbound conference', function () {
    $admin = badgeUser('Patryk');

    CallLog::factory()->create([
        'direction' => 'incoming',
        'status' => CallLog::STATUS_TRANSFERRED,
        'user_id' => null, // inbound: not owned by the admin
        'ended_at' => null,
        'metadata' => [
            'conference_id' => 'conf-x',
            'joined_admin_ids' => [$admin->id],
        ],
    ]);

    Livewire::actingAs($admin)
        ->test(CallStatusBadge::class)
        ->assertSet('activeCallId', fn ($id) => $id !== null)
        ->assertSet('activeCallStatus', CallLog::STATUS_TRANSFERRED);
});

it('does not light the badge for an admin who is NOT on the call', function () {
    $onCall = badgeUser('Patryk');
    $bystander = badgeUser('Greg');

    CallLog::factory()->create([
        'direction' => 'incoming',
        'status' => CallLog::STATUS_TRANSFERRED,
        'user_id' => null,
        'ended_at' => null,
        'metadata' => ['conference_id' => 'conf-y', 'joined_admin_ids' => [$onCall->id]],
    ]);

    Livewire::actingAs($bystander)
        ->test(CallStatusBadge::class)
        ->assertSet('activeCallId', null);
});

it('offers the configured GS call_recipients as participants to add', function () {
    $admin = badgeUser('Patryk');
    $teammate = badgeUser('Greg');

    // Vendor 1 is the canonical GS roster the component's availableParticipants
    // and the phone-9 shortcut both read (Vendor::find(1)). Pin the id.
    // options is NOT in Vendor::$fillable, so set it explicitly, not via create().
    $vendor = Vendor::query()->updateOrCreate(
        ['id' => 1],
        ['business_name' => 'GS Construction', 'business_type' => 'GC'],
    );
    $vendor->options = ['call_recipients' => [$admin->id, $teammate->id]];
    $vendor->save();

    CallLog::factory()->create([
        'direction' => 'incoming',
        'status' => CallLog::STATUS_TRANSFERRED,
        'user_id' => null,
        'ended_at' => null,
        'metadata' => ['conference_id' => 'conf-z', 'joined_admin_ids' => [$admin->id]],
    ]);

    $component = Livewire::actingAs($admin)->test(CallStatusBadge::class);

    $ids = collect($component->instance()->availableParticipants)->pluck('id');

    // The teammate is offered; the admin themselves is excluded.
    expect($ids)->toContain($teammate->id)
        ->and($ids)->not->toContain($admin->id);
});
