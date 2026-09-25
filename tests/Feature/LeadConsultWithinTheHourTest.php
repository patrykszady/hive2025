<?php

use App\Livewire\Leads\LeadCreate;
use App\Livewire\Leads\PickTimes;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Sets (or clears) the office override directly on the lead's lead_data,
 * bypassing the modal — for tests that only care what PickTimes does with it.
 */
function setConsultNoticeHours(Lead $lead, ?int $hours): Lead
{
    $data = $lead->lead_data;

    if ($hours === null) {
        unset($data['consult_notice_hours']);
    } else {
        $data['consult_notice_hours'] = $hours;
    }

    $lead->lead_data = $data;
    $lead->save();

    return Lead::withoutGlobalScopes()->find($lead->id);
}

it('loosens the notice to one hour when the office flags the lead', function () {
    $fx = makeConsultFixture();
    $tz = PickTimes::timezone();
    Carbon::setTestNow(Carbon::parse('2026-07-28 09:00', $tz));

    $lead = setConsultNoticeHours($fx['lead'], 1);

    expect($lead->consultNoticeHours())->toBe(1)
        ->and($lead->allowsConsultWithinTheHour())->toBeTrue()
        ->and(PickTimes::minLeadHours($lead))->toBe(1)
        ->and(PickTimes::earliestStart($lead)->equalTo(Carbon::now($tz)->addHour()))->toBeTrue()
        // A weekday morning ask for "within the hour" can still be booked
        // later that same day — no need to wait three days out.
        ->and(PickTimes::firstBookableDate($lead))->toBe('2026-07-28');

    Carbon::setTestNow();
});

it('accepts a window at least an hour out today and rejects one sooner', function () {
    $fx = makeConsultFixture();
    $tz = PickTimes::timezone();
    Carbon::setTestNow(Carbon::parse('2026-07-28 09:00', $tz));

    $lead = setConsultNoticeHours($fx['lead'], 1);
    $today = '2026-07-28';

    // 9-11 AM starts at 09:00 — before the 10:00 cutoff (now + 1h) — refused.
    $rejected = Livewire::test(PickTimes::class, ['lead' => $lead->id])
        ->set('date', $today)
        ->call('toggleWindow', '9-11 AM')
        ->get('times');

    expect($rejected)->toBe([]);

    // 11-1 PM starts at 11:00 — clears the 10:00 cutoff — accepted.
    $accepted = Livewire::test(PickTimes::class, ['lead' => $lead->id])
        ->set('date', $today)
        ->call('toggleWindow', '11-1 PM')
        ->get('times');

    expect($accepted)->toBe([['date' => $today, 'time' => '11-1 PM']]);

    Carbon::setTestNow();
});

it('leaves the standard three-day notice alone when the flag is off', function () {
    $fx = makeConsultFixture();
    $tz = PickTimes::timezone();
    Carbon::setTestNow(Carbon::parse('2026-07-28 09:00', $tz));

    $lead = Lead::withoutGlobalScopes()->find($fx['lead']->id);

    expect($lead->consultNoticeHours())->toBe(72)
        ->and($lead->allowsConsultWithinTheHour())->toBeFalse()
        ->and(PickTimes::minLeadHours($lead))->toBe(72);

    Carbon::setTestNow();
});

it('keeps a rescheduling lead at zero notice even when the flag is set', function () {
    $fx = makeConsultFixture();
    $tz = PickTimes::timezone();
    Carbon::setTestNow(Carbon::parse('2026-07-28 09:00', $tz));

    $lead = setConsultNoticeHours($fx['lead'], 1);
    $data = $lead->lead_data;
    $data['availability_rescheduled_at'] = now()->toDateTimeString();
    $lead->lead_data = $data;
    $lead->save();
    $lead = Lead::withoutGlobalScopes()->find($lead->id);

    expect($lead->hasRescheduled())->toBeTrue()
        ->and(PickTimes::minLeadHours($lead))->toBe(0);

    Carbon::setTestNow();
});

it('loads the toggle from the lead and persists it when flipped in the modal', function () {
    $fx = makeConsultFixture();

    $composer = consultComposer($fx)->assertSet('consultWithinTheHour', false);

    $composer->set('consultWithinTheHour', true);

    expect(Lead::withoutGlobalScopes()->find($fx['lead']->id)->lead_data['consult_notice_hours'] ?? null)
        ->toBe(1);

    $composer->set('consultWithinTheHour', false);

    expect(Lead::withoutGlobalScopes()->find($fx['lead']->id)->lead_data['consult_notice_hours'] ?? null)
        ->toBeNull();
});

it('loads an existing flag as on when editing the lead', function () {
    $fx = makeConsultFixture();
    setConsultNoticeHours($fx['lead'], 1);

    Livewire::actingAs($fx['admin'])
        ->test(LeadCreate::class)
        ->call('editLead', $fx['lead']->id)
        ->assertSet('consultWithinTheHour', true);
});
