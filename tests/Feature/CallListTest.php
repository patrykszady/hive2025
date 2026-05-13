<?php

use App\Livewire\Sms\CallList;
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
