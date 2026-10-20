<?php

use App\Livewire\Leads\LeadCreate;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * LeadCreate::edit() (the Details tab's "Update" button) used to add a new
 * LeadStatus row on every submit, even when $this->lead_status hadn't
 * changed — the status select at the footer already saves live via
 * updated()'s Lead::setStatus() call (change-only; see Lead::setStatus()),
 * so edit()'s own unconditional statuses()->create() was pure duplication.
 * App\Http\Controllers\Api\App\V1\LeadController::update()'s docblock
 * confirms this is a known web-only flaw deliberately not mirrored by the
 * app ("kept as its own call so the app never writes a duplicate status
 * row the way edit()'s combined save can"). Fixed by routing edit() through
 * the same guarded Lead::setStatus() helper.
 */
it('does not add a duplicate status row when Update is submitted without a status change', function () {
    $fx = makeConsultFixture();

    $component = Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead']->id);

    expect($fx['lead']->fresh()->statuses()->count())->toBe(1);

    // Address-only edit: lead_status is already 'New' (editLead() pre-fills
    // it from last_status), so nothing about the status actually changed.
    $component
        ->set('city', 'Palatine')
        ->call('edit');

    expect($fx['lead']->fresh()->statuses()->count())->toBe(1)
        ->and($fx['lead']->fresh()->last_status->title)->toBe('New');
});

it('adds exactly one status row when the footer status select changes it, and edit() adds no second row', function () {
    $fx = makeConsultFixture();

    $component = Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead']->id);

    // The footer status <flux:select> saves live via updated() -> setStatus().
    $component->set('lead_status', 'Lost');

    expect(Lead::find($fx['lead']->id)->statuses()->count())->toBe(2)
        ->and(Lead::find($fx['lead']->id)->last_status->title)->toBe('Lost');

    // Clicking Update afterwards (lead_status is still 'Lost', unchanged)
    // must not write a second "Lost" row on top of the one setStatus() just wrote.
    $component->call('edit');

    expect(Lead::find($fx['lead']->id)->statuses()->count())->toBe(2)
        ->and(Lead::find($fx['lead']->id)->last_status->title)->toBe('Lost');
});

it('still records a real status change made through edit() directly', function () {
    $fx = makeConsultFixture();

    $component = Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead']->id);

    // Force a status mismatch without going through the live-saving select,
    // to prove edit() itself still writes a real change rather than never
    // writing at all.
    $component->set('lead_status', 'Not a Fit')
        ->call('edit');

    expect(Lead::find($fx['lead']->id)->statuses()->count())->toBe(2)
        ->and(Lead::find($fx['lead']->id)->last_status->title)->toBe('Not a Fit');
});
