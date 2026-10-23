<?php

use App\Livewire\Sms\SmsConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('uses nickname as first name when resolving phone display names', function (): void {
    $viewer = User::query()->create([
        'first_name' => 'Patryk',
        'last_name' => 'Szady',
        'email' => 'patryk.viewer@example.com',
        'cell_phone' => '12245550001',
    ]);
    Auth::login($viewer);

    User::query()->create([
        'first_name' => 'Grzegorz',
        'last_name' => 'Szady',
        'nickname' => 'Gresiek',
        'email' => 'gresiek.phone-map@example.com',
        'cell_phone' => '12245550003',
    ]);

    $display = Livewire::test(SmsConversation::class)
        ->instance()
        ->resolvePhoneDisplay('+12245550003');

    expect($display)->toBe('Gresiek Szady');
});

it('names call contacts by nickname, never the legal first name', function (): void {
    // Thread 287 (2026-10-23): Bradley "Brad" Bates showed as Bradley on the call.
    $viewer = User::find(1);
    Auth::login($viewer);

    $client = \App\Models\Client::factory()->create();
    $brad = User::query()->create([
        'first_name' => 'Bradley',
        'last_name' => 'Bates',
        'nickname' => 'Brad',
        'email' => 'brad.bates@example.com',
        'cell_phone' => '2245556287',
    ]);
    $client->users()->attach($brad->id);

    $thread = \App\Models\SmsGroupThread::query()->create([
        'from_number' => '+12247354200',
        'participants' => ['+12245556287'],
        'client_id' => $client->id,
        'vendor_id' => $viewer->vendor?->id,
        'last_activity_at' => now(),
    ]);
    \App\Models\SmsThreadParticipant::query()->create(['thread_id' => $thread->id, 'phone_number' => '+12245556287', 'opted_in_at' => now()]);

    $contacts = (new \App\Support\Sms\ConversationPresenter(\App\Support\Sms\ConversationPresenter::loadThreadForDisplay($thread->id), $viewer))->callableContacts();

    expect(collect($contacts)->pluck('name')->all())->toBe(['Brad'])
        // The call list and call detail screens' phone → name lookup.
        ->and(Livewire::test(\App\Livewire\Sms\CallList::class)->instance()->resolvePhoneDisplay('+12245556287'))->toBe('Brad Bates');

    $calls = Livewire::test(\App\Livewire\Sms\CallList::class)->instance();
    $calls->warmPhoneContacts(['+12245556287']);
    expect($calls->resolvePhoneDisplay('+12245556287'))->toBe('Brad Bates');
});
