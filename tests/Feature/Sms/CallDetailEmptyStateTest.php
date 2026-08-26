<?php

use App\Livewire\Sms\CallDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeCallDetailUser(): User
{
    return User::query()->create([
        'first_name' => 'Call',
        'last_name' => 'Detail',
        'email' => 'call-detail-' . Str::random(8) . '@example.test',
        'cell_phone' => '7' . random_int(100000000, 999999999),
        'password' => bcrypt('password'),
        'remember_token' => Str::random(10),
    ]);
}

it('offers a way back to the list from the empty state, since mobile hides the list', function () {
    Livewire::actingAs(makeCallDetailUser())
        ->test(CallDetail::class)
        ->assertSee('Select a call to see details')
        ->assertSee('Back to calls')
        // Nothing was selected, so there is no selection to release.
        ->assertDontSee('x-init="$store.sms.clearCallSelection()"', false);
});

it('releases the selection when the selected call cannot be resolved', function () {
    Livewire::actingAs(makeCallDetailUser())
        ->test(CallDetail::class)
        ->set('callId', 999999)
        ->assertSee('That call is no longer available.')
        ->assertDontSee('Select a call to see details')
        // Frees the mobile list, which hides itself while a call is selected.
        ->assertSee('x-init="$store.sms.clearCallSelection()"', false)
        ->assertSee('Back to calls');
});
