<?php

use App\Models\CallLog;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// =========================================================================
// Vendors and subs ring their own order (Patryk, 2026-10-16: Greg first,
// then Patryk), set under Options; everyone else rings Call Recipients.
// =========================================================================

beforeEach(function () {
    config([
        'scout.driver' => null,
        'services.telnyx.api_key' => 'test-api-key',
        'services.telnyx.from' => '+12247354200',
        'services.telnyx.connection_id' => 'test-connection-id',
        'services.telnyx.public_url' => 'https://example.com',
    ]);
    Http::fake(['api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok', 'call_control_id' => 'admin-cc-id-1']], 200)]);
    $this->travelTo(\Carbon\Carbon::parse('2026-09-23 10:00:00', 'America/Chicago'));

    $this->company = Vendor::withoutGlobalScopes()->find(1) ?? Vendor::query()->forceCreate(['id' => 1, 'business_name' => 'GS Construction']);
    $this->patryk = User::factory()->create(['first_name' => 'Patryk', 'cell_phone' => '2249993880']);
    $this->greg = User::factory()->create(['first_name' => 'Greg', 'cell_phone' => '2249991111']);
    $this->company->forceFill(['timezone' => 'America/Chicago', 'options' => (object) [
        'short_name' => 'GS',
        'call_recipients' => [$this->patryk->id, $this->greg->id],
        'vendor_call_recipients' => [$this->greg->id, $this->patryk->id],
        'voicemail_enabled' => true,
    ]])->save();
});

/** Starts the ring cascade for a call from $from and returns the number rung first. */
function vendorRingOrder_firstRung(string $from): ?string
{
    $callLog = CallLog::factory()->create([
        'call_control_id' => 'incoming-cc-'.uniqid(),
        'status' => CallLog::STATUS_ANSWERED,
        'from_number' => $from,
        'caller_name' => 'Caller',
    ]);

    test()->postJson('/webhooks/telnyx/voice', ['data' => ['event_type' => 'call.answered', 'record_type' => 'event', 'payload' => [
        'call_control_id' => $callLog->call_control_id,
        'client_state' => base64_encode(json_encode([
            'action' => 'welcome_or_ring',
            'call_log_id' => $callLog->id,
            'original_caller' => $from,
        ])),
    ]]])->assertSuccessful();

    foreach (Http::recorded() as [$request]) {
        if (str_ends_with($request->url(), '/v2/calls') && $request->method() === 'POST') {
            return $request->data()['to'] ?? null;
        }
    }

    return null;
}

it('rings the vendor order when a sub calls from their business phone', function () {
    $sub = Vendor::withoutSyncingToSearch(fn () => Vendor::factory()->create(['business_name' => 'Smart Tech Electric', 'business_phone' => '(847) 212-3894']));
    $this->company->vendors()->attach($sub->id);

    expect(vendorRingOrder_firstRung('+18472123894'))->toBe('+1'.$this->greg->cell_phone);
});

it("rings the vendor order when someone on a sub's team calls", function () {
    $sub = Vendor::withoutSyncingToSearch(fn () => Vendor::factory()->create(['business_name' => 'Las Hardwoods']));
    $this->company->vendors()->attach($sub->id);
    $installer = User::factory()->create(['first_name' => 'Installer', 'cell_phone' => '8475550199']);
    $sub->users()->attach($installer->id, ['role_id' => 2]);

    expect(vendorRingOrder_firstRung('+18475550199'))->toBe('+1'.$this->greg->cell_phone);
});

it('rings Call Recipients for anyone else, and for a vendor while no vendor order is set', function () {
    expect(vendorRingOrder_firstRung('+12245550123'))->toBe('+1'.$this->patryk->cell_phone);

    $sub = Vendor::withoutSyncingToSearch(fn () => Vendor::factory()->create(['business_name' => 'Smart Tech Electric', 'business_phone' => '8472123894']));
    $this->company->vendors()->attach($sub->id);
    $this->company->forceFill(['options' => (object) array_merge((array) $this->company->options, ['vendor_call_recipients' => []])])->save();
    Http::fake(['api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok', 'call_control_id' => 'admin-cc-id-2']], 200)]);

    expect(vendorRingOrder_firstRung('+18472123894'))->toBe('+1'.$this->patryk->cell_phone);
});

it("never takes another company's vendor for one of ours", function () {
    $otherCompany = Vendor::withoutSyncingToSearch(fn () => Vendor::factory()->create(['business_name' => 'Other GC']));
    $theirSub = Vendor::withoutSyncingToSearch(fn () => Vendor::factory()->create(['business_name' => 'Their Sub', 'business_phone' => '8472123894']));
    $otherCompany->vendors()->attach($theirSub->id);

    expect(vendorRingOrder_firstRung('+18472123894'))->toBe('+1'.$this->patryk->cell_phone);
});

it('rings everyone at once when a vendor calls and Options says so', function () {
    $this->company->forceFill(['options' => (object) array_merge((array) $this->company->options, ['vendor_call_ring_all' => true])])->save();
    $sub = Vendor::withoutSyncingToSearch(fn () => Vendor::factory()->create(['business_name' => 'Smart Tech Electric', 'business_phone' => '8472123894']));
    $this->company->vendors()->attach($sub->id);

    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::fake([
        'api.telnyx.com/v2/calls' => Http::sequence()
            ->push(['data' => ['call_control_id' => 'greg-leg']])
            ->push(['data' => ['call_control_id' => 'patryk-leg']]),
        'api.telnyx.com/*' => Http::response(['data' => ['result' => 'ok']], 200),
    ]);

    vendorRingOrder_firstRung('+18472123894');

    $dialed = collect(Http::recorded())
        ->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/v2/calls') && $pair[0]->method() === 'POST')
        ->map(fn ($pair) => $pair[0]->data()['to'] ?? null)
        ->values()
        ->all();
    expect($dialed)->toBe(['+1'.$this->greg->cell_phone, '+1'.$this->patryk->cell_phone]);

    $callLog = CallLog::query()->latest('id')->first();
    expect($callLog->metadata['admin_call_control_ids'] ?? [])->toBe(['greg-leg', 'patryk-leg'])
        ->and($callLog->metadata['cascade_pending_ids'] ?? null)->toBe([]);

    // Greg's phone gives up while Patryk's still rings: no voicemail yet.
    $this->postJson('/webhooks/telnyx/voice', ['data' => ['event_type' => 'call.hangup', 'record_type' => 'event', 'payload' => [
        'call_control_id' => 'greg-leg',
        'hangup_cause' => 'timeout',
        'client_state' => base64_encode(json_encode([
            'action' => 'admin_ring',
            'call_log_id' => $callLog->id,
            'incoming_call_control_id' => $callLog->call_control_id,
            'admin_user_id' => $this->greg->id,
        ])),
    ]]])->assertSuccessful();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'actions/gather_using_speak'));
    expect($callLog->fresh()->metadata['admin_call_control_ids'] ?? [])->toBe(['patryk-leg']);
});
