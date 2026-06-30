<?php

use App\Livewire\Sms\CallList;
use App\Models\Client;
use App\Models\BlockedCaller;
use App\Models\CallLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeCallListUser(): User
{
    return User::query()->create([
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'call-list-' . Str::random(8) . '@example.test',
        'cell_phone' => '7' . random_int(100000000, 999999999),
        'password' => bcrypt('password'),
        'remember_token' => Str::random(10),
    ]);
}

it('has loadMore method that increments limit by 25', function () {
    $component = new CallList();
    expect($component->limit)->toBe(25);

    $component->loadMore();
    expect($component->limit)->toBe(50);

    $component->loadMore();
    expect($component->limit)->toBe(75);
});

it('resets limit when call filter changes', function () {
    $component = new CallList();
    $component->limit = 50;

    $component->callFilter = 'missed';
    $component->updatedCallFilter();

    expect($component->limit)->toBe(25);
    expect($component->callFilter)->toBe('missed');
    expect($component->selectedCallId)->toBeNull();
});

it('normalizes invalid call filter to all', function () {
    $component = new CallList();
    $component->callFilter = 'invalid';
    $component->updatedCallFilter();

    expect($component->callFilter)->toBe('all');
});

it('accepts valid filter values', function (string $filter) {
    $component = new CallList();
    $component->callFilter = $filter;
    $component->updatedCallFilter();

    expect($component->callFilter)->toBe($filter);
})->with(['all', 'missed', 'voicemail']);

it('resets to all when calls tab is opened', function () {
    $component = new CallList();
    $component->callFilter = 'missed';
    $component->limit = 100;
    $component->selectedCallId = 999;

    $component->callsTabOpened();

    // selectedCallId is preserved so URL hydration (?callId=…) survives
    // tab switches.
    expect($component->callFilter)->toBe('all')
        ->and($component->limit)->toBe(25)
        ->and($component->selectedCallId)->toBe(999);
});

it('does not use WithPagination trait', function () {
    $traits = class_uses_recursive(CallList::class);

    expect($traits)->not->toContain('Livewire\WithPagination');
});

it('marks an incoming call as spam and adds to blocked callers', function () {
    $user = makeCallListUser();
    $spamNumber = '+12242028716';

    $call = CallLog::factory()->create([
        'direction' => 'incoming',
        'from_number' => $spamNumber,
        'to_number' => '+12249993880',
        'status' => CallLog::STATUS_COMPLETED,
    ]);

    Livewire::actingAs($user)
        ->test(CallList::class)
        ->call('markAsSpam', $call->id);

    expect(BlockedCaller::where('phone_number', $spamNumber)->exists())->toBeTrue();

    $blocked = BlockedCaller::where('phone_number', $spamNumber)->first();
    expect($blocked->reason)->toBe('Manually marked as spam')
        ->and($blocked->blocked_by_user_id)->toBe($user->id)
        ->and($blocked->auto_blocked)->toBeFalse();

    expect($call->fresh()->status)->toBe(CallLog::STATUS_BLOCKED);
});

it('marks an outgoing call as spam using the to_number', function () {
    $user = makeCallListUser();
    $spamNumber = '+15551234567';

    $call = CallLog::factory()->create([
        'direction' => 'outgoing',
        'from_number' => '+12249993880',
        'to_number' => $spamNumber,
        'status' => CallLog::STATUS_COMPLETED,
    ]);

    Livewire::actingAs($user)
        ->test(CallList::class)
        ->call('markAsSpam', $call->id);

    expect(BlockedCaller::where('phone_number', $spamNumber)->exists())->toBeTrue();
});

it('updates all calls from the spam number to blocked status', function () {
    $user = makeCallListUser();
    $spamNumber = '+12242028716';

    $calls = CallLog::factory()->count(3)->create([
        'direction' => 'incoming',
        'from_number' => $spamNumber,
        'to_number' => '+12249993880',
        'status' => CallLog::STATUS_COMPLETED,
    ]);

    Livewire::actingAs($user)
        ->test(CallList::class)
        ->call('markAsSpam', $calls->first()->id);

    foreach ($calls as $call) {
        expect($call->fresh()->status)->toBe(CallLog::STATUS_BLOCKED);
    }
});

it('does not duplicate blocked caller when marking same number twice', function () {
    $user = makeCallListUser();
    $spamNumber = '+12242028716';

    BlockedCaller::create([
        'phone_number' => $spamNumber,
        'reason' => 'Previously blocked',
        'blocked_by_user_id' => $user->id,
        'auto_blocked' => false,
    ]);

    $call = CallLog::factory()->create([
        'direction' => 'incoming',
        'from_number' => $spamNumber,
        'status' => CallLog::STATUS_COMPLETED,
    ]);

    Livewire::actingAs($user)
        ->test(CallList::class)
        ->call('markAsSpam', $call->id);

    expect(BlockedCaller::where('phone_number', $spamNumber)->count())->toBe(1);
});

it('unblocks a blocked number', function () {
    $user = makeCallListUser();
    $blockedNumber = '+17086697081';

    BlockedCaller::create([
        'phone_number' => $blockedNumber,
        'reason' => 'Manually marked as spam',
        'blocked_by_user_id' => $user->id,
        'auto_blocked' => false,
    ]);

    Livewire::actingAs($user)
        ->test(CallList::class)
        ->call('unblockNumber', $blockedNumber);

    expect(BlockedCaller::where('phone_number', $blockedNumber)->exists())->toBeFalse();
});

it('shows only calls related to the current client user phone number', function () {
    $clientUser = User::query()->create([
        'first_name' => 'Mark',
        'last_name' => 'Brodson',
        'email' => 'mark.calls.' . Str::random(6) . '@example.test',
        'cell_phone' => '3128230569',
        'primary_vendor_id' => null,
    ]);

    $client = Client::factory()->create([
        'business_name' => 'Brodson Family',
    ]);
    $client->users()->attach($clientUser->id);

    CallLog::factory()->create([
        'direction' => 'incoming',
        'from_number' => '+13128230569',
        'to_number' => '+12249993880',
        'caller_name' => 'Mark Personal Call',
        'status' => CallLog::STATUS_COMPLETED,
    ]);

    CallLog::factory()->create([
        'direction' => 'incoming',
        'from_number' => '+18475551212',
        'to_number' => '+12249993880',
        'caller_name' => 'Other Contractor Call',
        'status' => CallLog::STATUS_COMPLETED,
    ]);

    Livewire::actingAs($clientUser)
        ->test(CallList::class)
        ->assertSee('Mark Brodson')
        ->assertDontSee('Other Contractor Call');
});

it('includes transcript text and phone digit variants in the call searchable array', function () {
    $call = CallLog::factory()->create([
        'direction' => 'incoming',
        'from_number' => '+13125559876',
        'to_number' => '+12249993880',
        'caller_name' => 'Jane Doe',
        'notes' => 'follow up about the deck',
        'status' => CallLog::STATUS_COMPLETED,
    ]);

    \App\Models\CallTranscript::create([
        'call_log_id' => $call->id,
        'text' => 'We discussed the bathroom remodel timeline.',
        'summary' => 'Bathroom remodel scheduling call.',
        'status' => \App\Models\CallTranscript::STATUS_READY,
    ]);

    $array = $call->fresh()->toSearchableArray();

    expect($array['caller_name'])->toBe('Jane Doe')
        ->and($array['notes'])->toBe('follow up about the deck')
        ->and($array['transcript_text'])->toContain('bathroom remodel timeline')
        ->and($array['transcript_text'])->toContain('Bathroom remodel scheduling')
        ->and($array['phone_digits'])->toContain('9876')
        ->and($array['phone_digits'])->toContain('3125559876');
});

it('resets the limit and selection when the search term changes', function () {
    $component = new CallList();
    $component->limit = 75;
    $component->selectedCallId = 42;

    $component->search = 'kitchen';
    $component->updatedSearch();

    expect($component->limit)->toBe(25)
        ->and($component->selectedCallId)->toBeNull();
});

it('filters the call list by search term', function () {
    config(['scout.driver' => 'collection']);

    $user = makeCallListUser();

    CallLog::factory()->create([
        'direction' => 'incoming',
        'from_number' => '+13125550001',
        'to_number' => '+12249993880',
        'caller_name' => 'Kitchen Remodel Caller',
        'status' => CallLog::STATUS_COMPLETED,
    ]);

    CallLog::factory()->create([
        'direction' => 'incoming',
        'from_number' => '+13125550002',
        'to_number' => '+12249993880',
        'caller_name' => 'Basement Caller',
        'status' => CallLog::STATUS_COMPLETED,
    ]);

    Livewire::actingAs($user)
        ->test(CallList::class)
        ->set('search', 'Kitchen')
        ->assertSee('Kitchen Remodel Caller')
        ->assertDontSee('Basement Caller');
});

it('prefers known contact name over stale caller_name in call list display', function () {
    $user = makeCallListUser();

    User::query()->create([
        'first_name' => 'Andrea',
        'last_name' => 'Wood',
        'email' => 'andrea.call-list@example.test',
        'cell_phone' => '7086697081',
    ]);

    CallLog::factory()->create([
        'direction' => 'incoming',
        'from_number' => '+17086697081',
        'to_number' => '+12249993880',
        'caller_name' => 'WOOD ANDREA',
        'status' => CallLog::STATUS_COMPLETED,
    ]);

    Livewire::actingAs($user)
        ->test(CallList::class)
        ->assertSee('Andrea Wood')
        ->assertDontSee('WOOD ANDREA');
});
