<?php

use App\Models\CallLog;
use App\Models\Client;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// =========================================================================
// The app's version of the web's "Add Participant" button on the global
// "In a call" badge (App\Livewire\Sms\CallStatusBadge): search the team, the
// people tied to the call, anyone else the company knows, or a typed number,
// then dial one of them into the live conference — through the same
// App\Services\Calls\ConferenceParticipantSearch / ConferenceInviter the web
// component now delegates to. Telnyx is fully faked — this never places or
// modifies a real call.
// =========================================================================

beforeEach(function () {
    config([
        'scout.driver' => null,
        'services.telnyx.api_key' => 'test-api-key',
        'services.telnyx.from' => '+12247354200',
        'services.telnyx.connection_id' => 'test-connection-id',
    ]);
    Http::fake(['api.telnyx.com/*' => Http::response(['data' => ['call_control_id' => 'invite-leg']], 200)]);

    $this->company = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $this->patryk = User::factory()->create(['first_name' => 'Patryk', 'last_name' => 'Szady', 'cell_phone' => '2249993880', 'primary_vendor_id' => 1]);
    $this->greg = User::factory()->create(['first_name' => 'Greg', 'last_name' => 'Szady', 'cell_phone' => '8478737217', 'primary_vendor_id' => 1]);
    $this->company->users()->attach([$this->patryk->id => ['role_id' => 1, 'is_employed' => 1], $this->greg->id => ['role_id' => 1, 'is_employed' => 1]]);
    $this->company->forceFill(['options' => (object) ['call_recipients' => [$this->patryk->id, $this->greg->id]]])->save();

    Sanctum::actingAs($this->patryk, ['app']);

    $this->call = CallLog::factory()->create([
        'vendor_id' => 1,
        'direction' => 'incoming',
        'status' => CallLog::STATUS_TRANSFERRED,
        'from_number' => '+17733164597',
        'ended_at' => null,
        'metadata' => ['conference_id' => 'conf-live', 'conference_name' => 'call_x', 'joined_admin_ids' => [$this->patryk->id]],
    ]);
});

it('searches the team and widens to anyone the company knows, or a typed number, like the web badge', function () {
    $client = Client::factory()->create();
    $client->vendors()->attach(1);
    $homeowner = User::factory()->create(['first_name' => 'Zyx', 'last_name' => 'Homeowner', 'cell_phone' => '8475550111']);
    $client->users()->attach($homeowner->id);

    $sub = Vendor::withoutSyncingToSearch(fn () => Vendor::factory()->create(['business_name' => 'Zyx Electric', 'business_phone' => '8475550122']));
    $this->company->vendors()->attach($sub->id);

    $otherCompany = Vendor::withoutSyncingToSearch(fn () => Vendor::factory()->create(['business_name' => 'Other GC']));
    $strangersClient = Client::factory()->create();
    $strangersClient->vendors()->attach($otherCompany->id);
    $stranger = User::factory()->create(['first_name' => 'Zyx', 'last_name' => 'Stranger', 'cell_phone' => '8475550133']);
    $strangersClient->users()->attach($stranger->id);

    $empty = $this->getJson("/api/app/v1/calls/{$this->call->id}/participants/search")->assertOk();
    expect(collect($empty->json('data'))->pluck('key')->all())->toBe(["user:{$this->greg->id}"]);

    $results = collect($this->getJson("/api/app/v1/calls/{$this->call->id}/participants/search?query=zyx")->assertOk()->json('data'));
    expect($results->pluck('name')->all())->toContain('Zyx Homeowner', 'Zyx Electric')
        ->and($results->pluck('name')->all())->not->toContain('Zyx Stranger');

    $typed = collect($this->getJson("/api/app/v1/calls/{$this->call->id}/participants/search?query=".urlencode('(847) 555-0199'))->assertOk()->json('data'));
    expect($typed->pluck('key')->all())->toContain('number:+18475550199');
});

it('dials a typed number into the live call with voicemail detection, like the web badge', function () {
    $this->postJson("/api/app/v1/calls/{$this->call->id}/participants", ['key' => 'number:+18475550199'])
        ->assertCreated()
        ->assertJsonPath('invited', true)
        ->assertJsonPath('participant.phone', '+18475550199');

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/v2/calls')) {
            return false;
        }
        $state = json_decode(base64_decode($request->data()['client_state'] ?? ''), true);

        return ($request->data()['to'] ?? null) === '+18475550199'
            && ($request->data()['answering_machine_detection'] ?? null) === 'premium'
            && ($state['action'] ?? null) === 'conference_invite'
            && ($state['invited_by_user_id'] ?? null) === $this->patryk->id;
    });
});

it('dials a team member in by their search result key', function () {
    $this->postJson("/api/app/v1/calls/{$this->call->id}/participants", ['key' => "user:{$this->greg->id}"])
        ->assertCreated()
        ->assertJsonPath('participant.kind', 'Team')
        ->assertJsonPath('participant.phone', $this->greg->cell_phone);
});

it('rejects a key for someone outside the team/call-tied/known scope', function () {
    $stranger = User::factory()->create(['cell_phone' => '7735550199']);

    $this->postJson("/api/app/v1/calls/{$this->call->id}/participants", ['key' => "user:{$stranger->id}"])
        ->assertUnprocessable();

    Http::assertNothingSent();
});

it('refuses to add anyone once the call has no live conference', function () {
    $noConference = CallLog::factory()->create([
        'vendor_id' => 1,
        'user_id' => $this->patryk->id,
        'status' => CallLog::STATUS_COMPLETED,
        'metadata' => [],
    ]);

    $this->postJson("/api/app/v1/calls/{$noConference->id}/participants", ['key' => 'number:+18475550199'])
        ->assertUnprocessable();

    Http::assertNothingSent();
});

it('keeps another company\'s call, and someone not on the call, out of reach', function () {
    $theirs = CallLog::factory()->create(['vendor_id' => Vendor::factory()->create()->id]);
    $this->getJson("/api/app/v1/calls/{$theirs->id}/participants/search")->assertForbidden();
    $this->postJson("/api/app/v1/calls/{$theirs->id}/participants", ['key' => 'number:+18475550199'])->assertForbidden();

    $this->getJson('/api/app/v1/calls/999999/participants/search')->assertNotFound();

    // Visible to the company (shared vendor_id), but Patryk never joined this
    // one's conference — only Greg did.
    $notOnIt = CallLog::factory()->create([
        'vendor_id' => 1,
        'status' => CallLog::STATUS_TRANSFERRED,
        'metadata' => ['conference_id' => 'conf-other', 'joined_admin_ids' => [$this->greg->id]],
    ]);
    $this->getJson("/api/app/v1/calls/{$notOnIt->id}/participants/search")->assertForbidden();
    $this->postJson("/api/app/v1/calls/{$notOnIt->id}/participants", ['key' => 'number:+18475550199'])->assertForbidden();

    Http::assertNothingSent();
});
