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
    // Asserts the Flux events, not a boolean. The component used to hold a
    // `showAddParticipantModal` flag, but Flux never read it — the button
    // set it and no modal ever appeared. openAddParticipantModal() now calls
    // $this->modal('add-participant')->show(), which dispatches modal-show,
    // so that dispatch IS the behaviour worth pinning; asserting the old flag
    // was asserting the bug.
    Livewire::test(CallStatusBadge::class)
        ->set('activeCallId', 123)
        ->set('selectedParticipantId', 7)
        ->call('openAddParticipantModal')
        ->assertDispatched('modal-show')
        // Opening clears any stale selection from a previous invite.
        ->assertSet('selectedParticipantId', null)
        ->call('closeAddParticipantModal')
        ->assertDispatched('modal-close')
        ->assertSet('selectedParticipantId', null);
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
