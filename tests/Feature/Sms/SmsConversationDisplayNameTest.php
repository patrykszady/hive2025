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
