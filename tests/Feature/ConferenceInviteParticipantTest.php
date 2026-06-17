<?php

use App\Livewire\Sms\CallStatusBadge;
use Livewire\Livewire;

it('invite participant button displays when call is active with available participants', function () {
    Livewire::test(CallStatusBadge::class)
        ->set('activeCallId', 123)
        ->set('activeCallStatus', 'transferred')
        ->assertSeeHtml('Add Participant');
});

it('invite participant modal can be opened and closed', function () {
    Livewire::test(CallStatusBadge::class)
        ->set('activeCallId', 123)
        ->call('openAddParticipantModal')
        ->assertSet('showAddParticipantModal', true)
        ->call('closeAddParticipantModal')
        ->assertSet('showAddParticipantModal', false);
});

it('invite participant modal is hidden when no call is active', function () {
    Livewire::test(CallStatusBadge::class)
        ->set('activeCallId', null)
        ->assertDontSeeHtml('Add Participant');
});

it('displays contact name when available instead of phone number', function () {
    Livewire::test(CallStatusBadge::class)
        ->set('activeCallId', 123)
        ->set('activeCallStatus', 'transferred')
        ->set('otherPartyDisplay', 'John Smith')
        ->assertSeeHtml('John Smith');
});

it('displays CNAM when contact name not available', function () {
    Livewire::test(CallStatusBadge::class)
        ->set('activeCallId', 123)
        ->set('activeCallStatus', 'transferred')
        ->set('otherPartyDisplay', 'CNAM Display')
        ->assertSeeHtml('CNAM Display');
});

it('displays phone number when no name or CNAM available', function () {
    Livewire::test(CallStatusBadge::class)
        ->set('activeCallId', 123)
        ->set('activeCallStatus', 'transferred')
        ->set('otherPartyDisplay', '(212) 555-1234')
        ->assertSeeHtml('(212) 555-1234');
});
