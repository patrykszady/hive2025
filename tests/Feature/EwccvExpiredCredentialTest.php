<?php

use App\Jobs\RunPendingEwccvLookups;
use App\Support\Ewccv\BridgedSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// =========================================================================
// A credential past the expiry EWCCV writes into it is refused at the
// hand-off (2026-10-14: the page's stale copy arrived 26 minutes after it
// expired, and the run it started failed on its first search).
// =========================================================================

function ewccvJwt(?int $expiresAt): string
{
    $part = fn (array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

    return $part(['alg' => 'HS256', 'typ' => 'JWT']).'.'.$part(array_filter(['sub' => 'ewccv', 'exp' => $expiresAt]) + ['pad' => str_repeat('p', 40)]).'.'.str_repeat('s', 43);
}

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    Cache::forget('ewccv:lookup-dispatched');
    config(['services.ewccv.bridge_token' => 'bridge-secret', 'services.ewccv.belongs_to_vendor_id' => 1]);
});

it('reads the expiry EWCCV writes into its credential', function () {
    $this->travelTo('2026-10-14 23:19:47');

    expect(BridgedSession::expiresAt(ewccvJwt(1792018386))?->toIso8601String())->toBe('2026-10-14T22:53:06+00:00')
        ->and(BridgedSession::expiresAt(ewccvJwt(null)))->toBeNull()
        ->and(BridgedSession::expiresAt(str_repeat('a', 120)))->toBeNull();
});

it('refuses a credential that has expired or is a minute from it, and takes a live one', function () {
    $this->travelTo('2026-10-14 23:19:47');
    $send = fn (string $token) => $this->withHeader('Authorization', 'Bearer bridge-secret')
        ->postJson('/api/ewccv/session', ['accessToken' => $token, 'reused' => true]);

    $send(ewccvJwt(now()->subMinutes(26)->timestamp))
        ->assertStatus(409)
        ->assertJson(['ok' => false, 'expired' => true])
        ->assertJsonPath('error', fn ($error) => str_contains($error, 'expired at 5:53 pm Central'));
    $send(ewccvJwt(now()->addSeconds(30)->timestamp))->assertStatus(409);

    expect(BridgedSession::raw())->toBeNull();
    Queue::assertNotPushed(RunPendingEwccvLookups::class);

    $live = ewccvJwt(now()->addMinutes(20)->timestamp);
    $send($live)->assertOk();
    expect(BridgedSession::raw()['access_token'])->toBe($live);
});
