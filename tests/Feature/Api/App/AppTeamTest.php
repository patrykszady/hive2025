<?php

use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
 * The Team tab in the Hive Contractors app (Patryk, 2026-11-14: "messages
 * and calls should be on the same link, as well as team, to match what we
 * have on the website"): the website's Team rows, and the Hive-only
 * conversations they open; the Messages list keeps them out, as the
 * website's does.
 */

beforeEach(function () {
    $this->gs = Vendor::withoutGlobalScopes()->find(1) ?? Vendor::query()->forceCreate(['id' => 1, 'business_name' => 'GS Construction']);
    $this->gs->forceFill(['options' => (object) ['short_name' => 'GS Construction']])->save();
    // The roster is exactly these (the seeded test DB has other vendor-1 users).
    $this->gs->users()->detach();
    $make = function (string $first, ?string $nick = null) {
        $user = User::factory()->create(['first_name' => $first, 'nickname' => $nick, 'last_name' => 'Szady', 'primary_vendor_id' => 1]);
        $this->gs->users()->attach($user->id, ['role_id' => 1, 'is_employed' => 1]);

        return $user;
    };
    $this->patryk = $make('Patryk');
    $this->greg = $make('Grzegorz', 'Greg');
    $this->cezary = $make('Cezary');
    Sanctum::actingAs($this->patryk, ['app']);
});

it('lists the whole team first, then each teammate, as the website\'s Team tab does', function () {
    $pair = SmsGroupThread::teamThreadFor([$this->patryk->id, $this->greg->id], 1);
    SmsMessage::query()->create(['thread_id' => $pair->id, 'direction' => 'inbound', 'text' => 'On my way to Carlisle', 'user_id' => $this->greg->id, 'status' => 'delivered']);

    $rows = $this->getJson('/api/app/v1/team')->assertOk()->json('data');

    expect(array_column($rows, 'title'))->toBe(['GS Construction Crew', 'Cezary', 'Greg'])
        ->and($rows[0])->toMatchArray(['key' => 'group', 'is_group' => true, 'user_id' => null])
        ->and($rows[2])->toMatchArray(['key' => 'user-'.$this->greg->id, 'user_id' => $this->greg->id, 'thread_id' => $pair->id, 'preview' => 'On my way to Carlisle'])
        // Cezary's conversation is made when it is first opened.
        ->and($rows[1]['thread_id'])->toBeNull();
});

it('opens a teammate\'s conversation, making it on first use, and the whole team\'s', function () {
    $first = $this->postJson('/api/app/v1/team/threads', ['user_id' => $this->cezary->id])->assertOk()->json('data');
    $again = $this->postJson('/api/app/v1/team/threads', ['user_id' => $this->cezary->id])->assertOk()->json('data');

    expect($first['title'])->toBe('Cezary')
        ->and($again['id'])->toBe($first['id'])
        ->and(SmsGroupThread::find($first['id'])->isTeam())->toBeTrue()
        ->and(collect(SmsGroupThread::find($first['id'])->memberIds())->sort()->values()->all())->toBe(collect([$this->patryk->id, $this->cezary->id])->sort()->values()->all());

    $group = $this->postJson('/api/app/v1/team/threads', [])->assertOk()->json('data');
    expect($group['title'])->toBe('GS Construction Crew')
        ->and(SmsGroupThread::find($group['id'])->isTeam())->toBeTrue();

    // The conversation opens in the app's usual thread screen.
    $this->getJson('/api/app/v1/threads/'.$first['id'])->assertOk();
});

it('opens no conversation with someone who is not on the team', function () {
    $outsider = User::factory()->create(['first_name' => 'Out', 'primary_vendor_id' => 1]);

    $this->postJson('/api/app/v1/team/threads', ['user_id' => $outsider->id])->assertForbidden();
    $this->postJson('/api/app/v1/team/threads', ['user_id' => 'abc'])->assertStatus(422)->assertJsonValidationErrors(['user_id']);
});

it('keeps team conversations off the Messages list, as on the website', function () {
    $pair = SmsGroupThread::teamThreadFor([$this->patryk->id, $this->greg->id], 1);
    $text = SmsGroupThread::query()->create(['from_number' => '+12247354200', 'participants' => ['+12245550001'], 'vendor_id' => 1, 'last_activity_at' => now()]);

    $ids = collect($this->getJson('/api/app/v1/threads')->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toContain($text->id)->not->toContain($pair->id);
});

/** Calling in Hive switched on, with a calling login for each of the team. */
function appTeam_calling(array $people): void
{
    config([
        'services.telnyx.api_key' => 'test-api-key',
        'services.telnyx.from' => '+12247354200',
        'services.telnyx.connection_id' => 'call-control-app',
        'services.telnyx.app_connection_id' => 'app-connection',
        'services.telnyx.public_url' => 'https://example.com',
    ]);
    foreach ($people as $person) {
        \App\Models\UserVoiceCredential::factory()->create(['user_id' => $person->id, 'sip_username' => 'gencred'.strtolower($person->first_name)]);
    }
    \Illuminate\Support\Facades\Bus::fake();
    \Illuminate\Support\Facades\Http::fake([
        'api.telnyx.com/v2/calls' => \Illuminate\Support\Facades\Http::response(['data' => ['call_control_id' => 'cc-'.uniqid(), 'call_session_id' => 'sess-1', 'call_leg_id' => 'leg-1']], 200),
        'api.telnyx.com/*' => \Illuminate\Support\Facades\Http::response(['data' => ['result' => 'ok']], 200),
    ]);
}

it('calls a teammate in Hive from the Team tab, ringing the caller\'s own app first to answer by itself', function () {
    appTeam_calling([$this->patryk, $this->greg, $this->cezary]);

    $placed = $this->postJson('/api/app/v1/team/calls', ['user_id' => $this->greg->id])->assertCreated()->json();

    $callLog = \App\Models\CallLog::findOrFail($placed['call_log_id']);
    expect($placed)->toMatchArray(['to' => 'hive-team:'.$this->greg->id, 'targets' => ['hive-team:'.$this->greg->id], 'ring' => 'app', 'display_name' => 'Greg'])
        ->and($callLog->metadata['type'])->toBe(\App\Services\Voice\InternalCalls::TYPE)
        ->and($callLog->metadata['target_user_ids'])->toBe([$this->greg->id]);
    \Illuminate\Support\Facades\Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/calls')
        && $request['to'] === 'sip:gencredpatryk@sip.telnyx.com'
        && collect($request['custom_headers'])->firstWhere('name', 'X-Hive-Outbound-Call')['value'] === (string) $callLog->id);

    // The app can end it, as any call it placed.
    $this->postJson("/api/app/v1/calls/{$callLog->id}/hangup", ['reason' => 'local-hangup'])->assertOk()->assertJsonPath('call_log_id', $callLog->id);
});

it('calls the whole crew in Hive from the Team tab', function () {
    appTeam_calling([$this->patryk, $this->greg, $this->cezary]);

    $placed = $this->postJson('/api/app/v1/team/calls', [])->assertCreated()->json();

    expect($placed)->toMatchArray(['to' => 'hive-team:crew', 'display_name' => 'GS Construction Crew'])
        ->and(collect(\App\Models\CallLog::findOrFail($placed['call_log_id'])->metadata['target_user_ids'])->sort()->values()->all())
        ->toBe(collect([$this->greg->id, $this->cezary->id])->sort()->values()->all());
});

it('calls nobody who is not on the team, and says why a call cannot be placed', function () {
    appTeam_calling([$this->patryk]);
    $outsider = User::factory()->create(['first_name' => 'Out', 'primary_vendor_id' => 1]);

    $this->postJson('/api/app/v1/team/calls', ['user_id' => $outsider->id])->assertForbidden();
    // Greg has no calling login yet.
    $this->postJson('/api/app/v1/team/calls', ['user_id' => $this->greg->id])->assertStatus(422)->assertJsonStructure(['message']);
    expect(\App\Models\CallLog::query()->where('user_id', $this->patryk->id)->where('status', '!=', \App\Models\CallLog::STATUS_FAILED)->count())->toBe(0);
});
