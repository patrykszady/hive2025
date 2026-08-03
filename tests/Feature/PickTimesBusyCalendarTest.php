<?php

use App\Livewire\Leads\PickTimes;
use App\Models\Client;
use App\Models\CompanyEmail;
use App\Models\Lead;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AdminCalendarBusy;
use App\Services\NylasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * A lead plus admin grants, with the given busy blocks stubbed at the
 * NylasService boundary — the exact payload shape Nylas returns.
 *
 * @param  array<int, array{0: Carbon, 1: Carbon}>  $busy
 */
function busyCalendarFixture(array $busy = []): Lead
{
    Cache::flush();

    config(['nylas.crew_leads.vendor_id' => null]); // set below

    $vendor = Vendor::factory()->create();
    config(['nylas.crew_leads.vendor_id' => $vendor->id]);

    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => 'patryk@gs.test', 'grant_id' => 'grant-patryk']);
    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => 'greg@gs.test', 'grant_id' => 'grant-greg']);

    $slots = collect($busy)->map(fn (array $pair) => [
        'start_time' => $pair[0]->timestamp,
        'end_time' => $pair[1]->timestamp,
        'status' => 'busy',
        'object' => 'time_slot',
    ])->all();

    $mock = Mockery::mock(NylasService::class);
    $mock->shouldReceive('getFreeBusy')
        ->andReturn([
            'status' => 200,
            'success' => true,
            'data' => ['request_id' => 'req-1', 'data' => [
                ['email' => 'patryk@gs.test', 'object' => 'free_busy', 'time_slots' => $slots],
                ['email' => 'greg@gs.test', 'object' => 'free_busy', 'time_slots' => []],
            ]],
        ]);
    app()->instance(NylasService::class, $mock);

    $creator = User::query()->create([
        'first_name' => 'Lead',
        'last_name' => 'Creator',
        'email' => 'busy.creator.'.uniqid().'@example.com',
        'cell_phone' => fake()->unique()->numerify('224999####'),
    ]);

    $lead = Lead::create([
        'date' => now(),
        'origin' => 'gs.construction',
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $creator->id,
        'lead_data' => ['name' => 'Busy Test', 'email' => 'busy@example.com', 'message' => 'Consult'],
    ]);
    $lead->statuses()->create(['title' => 'New', 'belongs_to_vendor_id' => $vendor->id]);

    return $lead;
}

/** First bookable weekday, as the picker computes it. */
function busyTestDay(Lead $lead): string
{
    return busySafeDay();
}

/**
 * A weekday on which EVERY window clears the notice rule.
 *
 * firstBookableDate() returns the first day with any bookable window, so its
 * earliest windows can still fall inside the notice period depending on the
 * time of day the suite runs (7-9 AM three days out is behind the 72h line
 * when it is 07:57 now). Stepping one weekday further makes the fixtures
 * independent of the clock.
 */
function busySafeDay(): string
{
    $day = Carbon::parse(PickTimes::firstBookableDate(), PickTimes::timezone())->addDay();

    while ($day->isWeekend()) {
        $day->addDay();
    }

    return $day->format('Y-m-d');
}

it('refuses a window that overlaps an admin calendar event', function () {
    $day = busySafeDay();
    $tz = PickTimes::timezone();

    // An event 2:30-3:00 PM on the first bookable day — inside the 2-4 PM
    // window only.
    $lead = busyCalendarFixture([[
        Carbon::parse($day.' 14:30', $tz),
        Carbon::parse($day.' 15:00', $tz),
    ]]);

    $component = Livewire::test(PickTimes::class, ['lead' => $lead->id])
        ->set('date', $day)
        ->call('toggleWindow', '1-3 PM');

    expect($component->get('times'))->toBe([])
        ->and($component->errors()->first('times'))->toContain('already booked');

    // Other windows on the same day stay selectable.
    $component->call('toggleWindow', '7-9 AM');
    expect($component->get('times'))->toHaveCount(1);

    // The page marks the clashing window busy — and Anytime with it, since
    // one booking that day breaks the whole-day promise.
    expect($component->instance()->busyWindows)->toBe(['Anytime', '1-3 PM']);
});

it('blocks Anytime whenever any window that day is taken', function () {
    $day = busySafeDay();
    $tz = PickTimes::timezone();

    // ONE event, inside 2-4 PM only.
    $lead = busyCalendarFixture([[
        Carbon::parse($day.' 14:30', $tz),
        Carbon::parse($day.' 15:00', $tz),
    ]]);

    $component = Livewire::test(PickTimes::class, ['lead' => $lead->id])
        ->set('date', $day)
        ->call('toggleWindow', 'Anytime');

    // Anytime promises the whole day — refused…
    expect($component->get('times'))->toBe([])
        ->and($component->errors()->first('times'))->toContain("Anytime isn't available");

    // …but the DAY stays on the calendar: three windows are still free.
    expect(PickTimes::unavailableDates($lead->fresh()))->not->toContain($day);

    $component->call('toggleWindow', '7-9 AM');
    expect($component->get('times'))->toHaveCount(1);
});

it('greys the whole day out only when every window is taken', function () {
    $day = busySafeDay();
    $tz = PickTimes::timezone();

    // Whole working day booked solid.
    $lead = busyCalendarFixture([[
        Carbon::parse($day.' 08:00', $tz),
        Carbon::parse($day.' 16:00', $tz),
    ]]);

    expect(PickTimes::unavailableDates($lead->fresh()))->toContain($day);
});

it('drops a slot that went busy between picking and submitting', function () {
    $lead = busyCalendarFixture([]);
    $tz = PickTimes::timezone();

    // Two clean days, three picks — submittable.
    $dayOne = busyTestDay($lead);
    $dayTwo = Carbon::parse($dayOne, $tz)->addDay();
    while ($dayTwo->isWeekend()) {
        $dayTwo->addDay();
    }
    $dayTwo = $dayTwo->format('Y-m-d');

    $component = Livewire::test(PickTimes::class, ['lead' => $lead->id])
        ->set('date', $dayOne)
        ->call('toggleWindow', '1-3 PM')
        ->call('toggleWindow', '9-11 AM')
        ->set('date', $dayTwo)
        ->call('toggleWindow', '7-9 AM');

    expect($component->instance()->canSubmit)->toBeTrue();

    // An event lands inside 1-3 PM on day one AFTER the picks were made.
    Cache::flush();
    $mock = Mockery::mock(NylasService::class);
    $mock->shouldReceive('getFreeBusy')->andReturn([
        'status' => 200, 'success' => true,
        'data' => ['request_id' => 'req-2', 'data' => [[
            'email' => 'patryk@gs.test', 'object' => 'free_busy',
            'time_slots' => [[
                'start_time' => Carbon::parse($dayOne.' 14:00', $tz)->timestamp,
                'end_time' => Carbon::parse($dayOne.' 14:30', $tz)->timestamp,
                'status' => 'busy', 'object' => 'time_slot',
            ]],
        ]]],
    ]);
    app()->instance(NylasService::class, $mock);

    $component->call('submit');

    expect($component->get('submitted'))->toBeFalse()
        ->and($component->errors()->first('times'))->toContain('just became unavailable')
        // The stale slot is gone; the clean picks remain.
        ->and(collect($component->get('times'))->pluck('time')->all())->toBe(['9-11 AM', '7-9 AM']);
});

it('treats calendars as free when the lookup fails', function () {
    $lead = busyCalendarFixture([]);
    $day = busyTestDay($lead);

    Cache::flush();
    $mock = Mockery::mock(NylasService::class);
    $mock->shouldReceive('getFreeBusy')->andReturn(['status' => 500, 'success' => false, 'error' => 'boom']);
    app()->instance(NylasService::class, $mock);

    $component = Livewire::test(PickTimes::class, ['lead' => $lead->id])
        ->set('date', $day)
        ->call('toggleWindow', '1-3 PM');

    expect($component->get('times'))->toHaveCount(1)
        ->and($component->instance()->busyWindows)->toBe([]);
});

it('makes no calendar call at all when no grants exist', function () {
    // The standard consult fixtures have grant_id '' — this is what keeps the
    // whole existing suite offline.
    $vendor = Vendor::factory()->create();
    config(['nylas.crew_leads.vendor_id' => $vendor->id]);
    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => 'x@gs.test', 'grant_id' => '']);

    $mock = Mockery::mock(NylasService::class);
    $mock->shouldNotReceive('getFreeBusy');
    app()->instance(NylasService::class, $mock);

    expect(app(AdminCalendarBusy::class)->windowIsBusy(now()->addDays(5)->format('Y-m-d'), '1-3 PM'))->toBeFalse();
});

it('withholds exact-time chips that collide with a calendar event', function () {
    $tz = PickTimes::timezone();
    $day = busySafeDay();

    // Event 2:00-2:30 PM inside the 1-3 PM window: of the chips
    // 1:00 / 1:30 / 2:00 / 2:30, only 2:00 overlaps and must vanish.
    $lead = busyCalendarFixture([[
        Carbon::parse($day.' 14:00', $tz),
        Carbon::parse($day.' 14:30', $tz),
    ]]);

    $data = $lead->lead_data;
    $data['availability'] = [['date' => $day, 'time' => '1-3 PM']];
    $lead->lead_data = $data;
    $lead->saveQuietly();

    $admin = User::query()->create([
        'first_name' => 'Patryk',
        'last_name' => 'Admin',
        'email' => 'busy.admin.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224888####'),
        'primary_vendor_id' => $lead->belongs_to_vendor_id,
    ]);
    Vendor::find($lead->belongs_to_vendor_id)->users()->attach($admin->id, ['role_id' => 1]);
    CompanyEmail::create(['vendor_id' => $lead->belongs_to_vendor_id, 'email' => $admin->email, 'grant_id' => '']);

    $component = Livewire::actingAs($admin)
        ->test(\App\Livewire\Leads\LeadCreate::class)
        ->call('editLead', $lead)
        ->call('insertAvailabilitySlot', 0);

    $values = array_column($component->instance()->exactTimeOptions, 'value');

    expect($values)->toBe(['13:00', '13:30', '14:30'])
        ->and($values)->not->toContain('14:00');
});
