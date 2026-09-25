<?php

use App\Livewire\Sms\CallDetail;
use App\Livewire\Sms\CallList;
use App\Models\BlockedCaller;
use App\Models\CallLog;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function sec1_call_company(string $label): array
{
    $vendor = Vendor::factory()->create(['business_name' => "Call Co {$label}"]);
    $vendor->forceFill(['registration' => ['registered' => true]])->save();

    $user = new User();
    $user->forceFill([
        'first_name' => $label,
        'last_name' => 'Admin',
        'email' => 'sec1-call-' . strtolower($label) . '-' . uniqid() . '@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return compact('vendor', 'user');
}

// ── CallLog::scopeVisibleToMessagesUser ──────────────────────────────────

it('does not let one company see another company\'s call log', function (): void {
    $mine = sec1_call_company('A');
    $theirs = sec1_call_company('B');

    $foreignCall = CallLog::factory()->create([
        'vendor_id' => $theirs['vendor']->id,
        'caller_name' => 'Their Secret Caller',
    ]);

    $visible = CallLog::query()->visibleToMessagesUser($mine['user'])->find($foreignCall->id);

    expect($visible)->toBeNull();

    // call() is a Computed prop reflecting the scoped lookup.
    $component = Livewire::actingAs($mine['user'])->test(CallDetail::class)
        ->call('selectCall', $foreignCall->id);
    expect($component->instance()->call())->toBeNull();
});

it('lets a company see its own call log', function (): void {
    $mine = sec1_call_company('A2');

    $ownCall = CallLog::factory()->create([
        'vendor_id' => $mine['vendor']->id,
        'caller_name' => 'My Caller',
    ]);

    $visible = CallLog::query()->visibleToMessagesUser($mine['user'])->find($ownCall->id);

    expect($visible)->not->toBeNull()
        ->and($visible->id)->toBe($ownCall->id);
});

it('does not list another company\'s calls in the call list', function (): void {
    $mine = sec1_call_company('A3');
    $theirs = sec1_call_company('B3');

    CallLog::factory()->create(['vendor_id' => $theirs['vendor']->id, 'caller_name' => 'Foreign Caller']);
    $ownCall = CallLog::factory()->create(['vendor_id' => $mine['vendor']->id, 'caller_name' => 'My Caller']);

    $component = Livewire::actingAs($mine['user'])->test(CallList::class);

    $ids = $component->instance()->calls()->pluck('call')->pluck('id')->all();

    expect($ids)->toContain($ownCall->id)
        ->and($ids)->not->toContain(CallLog::where('caller_name', 'Foreign Caller')->first()->id);
});

// ── HasCallActions: markAsSpam / unblockNumber scoped ────────────────────

it('refuses to mark another company\'s call as spam', function (): void {
    $mine = sec1_call_company('A4');
    $theirs = sec1_call_company('B4');

    $foreignCall = CallLog::factory()->create([
        'vendor_id' => $theirs['vendor']->id,
        'direction' => 'incoming',
        'from_number' => '+13125559999',
        'status' => CallLog::STATUS_COMPLETED,
    ]);

    Livewire::actingAs($mine['user'])
        ->test(CallList::class)
        ->call('markAsSpam', $foreignCall->id);

    expect(BlockedCaller::where('phone_number', '+13125559999')->exists())->toBeFalse()
        ->and($foreignCall->fresh()->status)->toBe(CallLog::STATUS_COMPLETED);
});

it('marks its own call as spam, scoped to its own company', function (): void {
    $mine = sec1_call_company('A5');

    $ownCall = CallLog::factory()->create([
        'vendor_id' => $mine['vendor']->id,
        'direction' => 'incoming',
        'from_number' => '+13125558888',
        'status' => CallLog::STATUS_COMPLETED,
    ]);

    Livewire::actingAs($mine['user'])
        ->test(CallList::class)
        ->call('markAsSpam', $ownCall->id);

    $blocked = BlockedCaller::where('phone_number', '+13125558888')->first();

    expect($blocked)->not->toBeNull()
        ->and($blocked->vendor_id)->toBe($mine['vendor']->id)
        ->and($ownCall->fresh()->status)->toBe(CallLog::STATUS_BLOCKED);
});

it('a spam block from one company does not block the number for another company\'s calls', function (): void {
    $mine = sec1_call_company('A6');
    $theirs = sec1_call_company('B6');

    $ownCall = CallLog::factory()->create([
        'vendor_id' => $mine['vendor']->id,
        'direction' => 'incoming',
        'from_number' => '+13125557777',
        'status' => CallLog::STATUS_COMPLETED,
    ]);
    $theirCallSameNumber = CallLog::factory()->create([
        'vendor_id' => $theirs['vendor']->id,
        'direction' => 'incoming',
        'from_number' => '+13125557777',
        'status' => CallLog::STATUS_COMPLETED,
    ]);

    Livewire::actingAs($mine['user'])
        ->test(CallList::class)
        ->call('markAsSpam', $ownCall->id);

    expect($ownCall->fresh()->status)->toBe(CallLog::STATUS_BLOCKED)
        ->and($theirCallSameNumber->fresh()->status)->toBe(CallLog::STATUS_COMPLETED);
});

it('refuses to unblock a number another company blocked', function (): void {
    $mine = sec1_call_company('A7');
    $theirs = sec1_call_company('B7');

    BlockedCaller::create([
        'phone_number' => '+13125556666',
        'reason' => 'Their block',
        'blocked_by_user_id' => $theirs['user']->id,
        'vendor_id' => $theirs['vendor']->id,
        'auto_blocked' => false,
    ]);

    Livewire::actingAs($mine['user'])
        ->test(CallList::class)
        ->call('unblockNumber', '+13125556666');

    expect(BlockedCaller::where('phone_number', '+13125556666')->where('vendor_id', $theirs['vendor']->id)->exists())->toBeTrue();
});

it('unblocks a number its own company blocked', function (): void {
    $mine = sec1_call_company('A8');

    BlockedCaller::create([
        'phone_number' => '+13125555555',
        'reason' => 'My block',
        'blocked_by_user_id' => $mine['user']->id,
        'vendor_id' => $mine['vendor']->id,
        'auto_blocked' => false,
    ]);

    Livewire::actingAs($mine['user'])
        ->test(CallList::class)
        ->call('unblockNumber', '+13125555555');

    expect(BlockedCaller::where('phone_number', '+13125555555')->where('vendor_id', $mine['vendor']->id)->exists())->toBeFalse();
});

// ── Call log ownership on create, and the demo seeder ────────────────────

it('refuses the demo-call seeder outside local development', function (): void {
    $mine = sec1_call_company('Demo');
    $before = CallLog::query()->count();

    Livewire::actingAs($mine['user'])
        ->test(CallList::class)
        ->call('generateDemoCalls')
        ->assertNotFound();

    expect(CallLog::query()->count())->toBe($before);
});

it('records the caller\'s company on a click-to-call log', function (): void {
    $mine = sec1_call_company('Dialer');

    config([
        'services.telnyx.api_key' => 'test-key',
        'services.telnyx.connection_id' => 'conn-1',
        'services.telnyx.from' => '+12245550000',
    ]);
    \Illuminate\Support\Facades\Http::fake([
        'api.telnyx.com/*' => \Illuminate\Support\Facades\Http::response(['data' => ['call_control_id' => 'cc-1']], 200),
    ]);

    Livewire::actingAs($mine['user'])
        ->test(CallList::class)
        ->call('callBack', '+12245551234');

    $log = CallLog::query()->where('to_number', 'like', '%2245551234')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->vendor_id)->toBe($mine['vendor']->id);
});
