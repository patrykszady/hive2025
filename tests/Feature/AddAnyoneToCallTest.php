<?php

use App\Livewire\Sms\CallStatusBadge;
use App\Models\CallLog;
use App\Models\Client;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// =========================================================================
// Add anyone to a live call (Patryk, 2026-10-16): the Add Participant search
// finds the team, the people tied to the call, anyone the company knows
// (clients, vendors and their people), or a typed number — never another
// company's people — and dials them in with voicemail detection.
// =========================================================================

beforeEach(function () {
    config([
        'scout.driver' => null,
        'services.telnyx.api_key' => 'test-api-key',
        'services.telnyx.from' => '+12247354200',
        'services.telnyx.connection_id' => 'test-connection-id',
    ]);
    Http::fake(['api.telnyx.com/*' => Http::response(['data' => ['call_control_id' => 'invite-leg']], 200)]);

    $this->company = Vendor::withoutGlobalScopes()->find(1) ?? Vendor::query()->forceCreate(['id' => 1, 'business_name' => 'GS Construction']);
    $this->patryk = User::factory()->create(['first_name' => 'Patryk', 'last_name' => 'Szady', 'cell_phone' => '2249993880', 'primary_vendor_id' => 1]);
    $this->greg = User::factory()->create(['first_name' => 'Greg', 'last_name' => 'Szady', 'cell_phone' => '8478737217', 'primary_vendor_id' => 1]);
    $this->company->users()->attach([$this->patryk->id => ['role_id' => 1, 'is_employed' => 1], $this->greg->id => ['role_id' => 1, 'is_employed' => 1]]);
    $this->company->forceFill(['options' => (object) ['call_recipients' => [$this->patryk->id, $this->greg->id]]])->save();

    $this->call = CallLog::factory()->create([
        'direction' => 'incoming',
        'status' => CallLog::STATUS_TRANSFERRED,
        'from_number' => '+17733164597',
        'ended_at' => null,
        'metadata' => ['conference_id' => 'conf-live', 'conference_name' => 'call_x', 'joined_admin_ids' => [$this->patryk->id]],
    ]);
});

it('finds anyone the company knows, or a typed number, and nobody from another company', function () {
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

    // Closed, the badge searches nothing (it renders on every page and refresh).
    $badge = Livewire::actingAs($this->patryk)->test(CallStatusBadge::class);
    expect($badge->instance()->participantSearchResults)->toBe([]);

    $badge->call('openAddParticipantModal');

    // With nothing typed: the team, never the person already on the call.
    expect(collect($badge->instance()->participantSearchResults)->pluck('key')->all())->toBe(["user:{$this->greg->id}"]);

    $results = collect($badge->set('participantSearch', 'zyx')->instance()->participantSearchResults);
    expect($results->pluck('name')->all())->toContain('Zyx Homeowner', 'Zyx Electric')
        ->and($results->pluck('name')->all())->not->toContain('Zyx Stranger');

    $typed = collect($badge->set('participantSearch', '(847) 555-0199')->instance()->participantSearchResults);
    expect($typed->pluck('key')->all())->toContain('number:+18475550199');
});

it('dials a typed number into the live call with voicemail detection', function () {
    Livewire::actingAs($this->patryk)
        ->test(CallStatusBadge::class)
        ->call('openAddParticipantModal')
        ->set('participantSearch', '847-555-0199')
        ->call('inviteParticipant', 'number:+18475550199');

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
