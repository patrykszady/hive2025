<?php

use App\Livewire\Estimates\ProjectTimeline;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Estimates\ProjectDuration;
use App\Support\ConstructionHolidays;
use App\Support\FaithHolidays;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/../../Support/project-timeline-fixtures.php';

uses(RefreshDatabase::class);

// The 2026 calendar is ahead: days before today cannot be picked.
beforeEach(fn () => $this->travelTo('2026-01-01 09:00:00'));

/*
 * "Estimate your project duration" (2026-10-22): a year on one screen, a
 * click for the start or the cabinet delivery, a length preset for the
 * finish, and the holidays the project runs across. Then (same day) the
 * homeowner's faith adds theirs, and each holiday is a pill: green when the
 * homeowner can have us, red when we stay away and lose the working day.
 */

it('ends a project on the day before its length has passed', function (string $start, string $preset, string $finish) {
    expect(ProjectDuration::finish(CarbonImmutable::parse($start), $preset)->toDateString())->toBe($finish);
})->with([
    '4 weeks' => ['2026-10-26', '4w', '2026-11-22'],
    '8 weeks' => ['2026-10-26', '8w', '2026-12-20'],
    '3 months' => ['2026-11-02', '3m', '2027-02-01'],
    '12 months' => ['2026-01-01', '12m', '2026-12-31'],
]);

it('starts the contractor the prep weeks before the cabinets arrive', function () {
    expect(ProjectDuration::startBeforeCabinets(CarbonImmutable::parse('2026-12-03'), 3)->toDateString())->toBe('2026-11-12');
});

it('demos only Tuesday to Thursday, moving to the nearest one', function (string $clicked, string $demo) {
    expect(ProjectDuration::demoDay(CarbonImmutable::parse($clicked))->toDateString())->toBe($demo);

    Livewire::actingAs(User::find(1))
        ->test(ProjectTimeline::class, ['from' => '2026-01'])
        ->call('pickDate', $clicked)
        ->assertSet('start', $demo);
})->with([
    'Monday to Tuesday' => ['2026-10-26', '2026-10-27'],
    'Tuesday stays' => ['2026-10-27', '2026-10-27'],
    'Thursday stays' => ['2026-10-29', '2026-10-29'],
    'Friday to Thursday' => ['2026-10-30', '2026-10-29'],
    'Saturday to Thursday' => ['2026-10-31', '2026-10-29'],
    'Sunday to Tuesday' => ['2026-11-01', '2026-11-03'],
]);

it('keeps a placed cabinet week, and the weeks needed before it, while the start moves', function () {
    $page = Livewire::actingAs(User::find(1))
        ->test(ProjectTimeline::class, ['from' => '2026-01'])
        ->set('length', '8w')
        ->set('clickSets', 'cabinets')
        ->set('prepWeeks', 3)
        ->call('pickDate', '2026-12-03')
        ->assertSet('cabinets', '2026-11-30')
        ->assertSet('start', '2026-11-10')
        // The next click moves the start, not the delivery.
        ->assertSet('clickSets', 'start');

    // Weeks needed are what the job needs, not where the click lands.
    $page->call('pickDate', '2026-11-04')
        ->assertSet('cabinets', '2026-11-30')
        ->assertSet('start', '2026-11-04')
        ->assertSet('prepWeeks', 3)
        ->assertSee('4 weeks of work before (3 needed)')
        ->assertSee('Earlier than needed for the cabinets');

    $page->call('pickDate', '2026-10-12')
        ->assertSet('start', '2026-10-13')
        ->assertSee('7 weeks of work before (3 needed)');

    $page->call('pickDate', '2026-12-09')
        ->assertSet('cabinets', '2026-11-30')
        ->assertSee('The cabinets arrive before the project starts');
});

it('highlights the best time to start from the cabinet week and the weeks needed before it', function () {
    // Cabinets the week of Nov 30, 3 weeks needed: the best start is Tue Nov 10 – Thu Nov 12.
    $page = Livewire::actingAs(User::find(1))
        ->test(ProjectTimeline::class, ['from' => '2026-01'])
        ->set('length', '8w')
        ->set('clickSets', 'cabinets')
        ->set('prepWeeks', 3)
        ->call('pickDate', '2026-12-01')
        ->assertSet('start', '2026-11-10')
        ->assertSee('Best start: Tue Nov 10 – Thu Nov 12')
        ->assertSee('Right in the best time to start');

    $html = $page->html();
    expect(timelineCell($html, '2026-11-10')['tone'])->toBe('endpoint')
        ->and(timelineCell($html, '2026-11-11')['tone'])->toBe('best')
        ->and(timelineCell($html, '2026-11-12')['tone'])->toBe('best')
        // One blue project band either side of the delivery: no separate shade before it.
        ->and(timelineCell($html, '2026-11-13')['tone'])->toBe('project')
        ->and(timelineCell($html, '2026-11-27')['tone'])->toBe('holiday_off')
        ->and(timelineCell($html, '2026-11-16')['tone'])->toBe('project')
        ->and(timelineCell($html, '2026-12-08')['tone'])->toBe('project');

    // A later start keeps the best days on the calendar and says it is late.
    $page->call('pickDate', '2026-11-18')
        ->assertSee('Later than the best start: not enough weeks before the cabinets');
    $html = $page->html();
    expect(timelineCell($html, '2026-11-10')['tone'])->toBe('best')
        // Before the later start: the fade, not the project.
        ->and(timelineCell($html, '2026-11-16')['tone'])->toBe('day')
        ->and(timelineFade($html, '2026-11-17'))->toBe('0.89');
});

it('leaves holidays we stay away out of the best time to start', function () {
    // Cabinets the week of Dec 21, 4 weeks needed: Thanksgiving week, whose Thursday is off.
    Livewire::actingAs(User::find(1))
        ->test(ProjectTimeline::class, ['from' => '2026-01'])
        ->set('length', '8w')
        ->set('clickSets', 'cabinets')
        ->set('prepWeeks', 4)
        ->call('pickDate', '2026-12-22')
        ->assertSet('start', '2026-11-24')
        ->assertSee('Best start: Tue Nov 24 – Wed Nov 25');
});

it('knows the holidays a remodel works around, observed on a weekday when they fall on a weekend', function () {
    $holidays = collect(ConstructionHolidays::forYear(2026))->keyBy('name');

    expect($holidays['Thanksgiving']['date'])->toBe('2026-11-26')
        ->and($holidays['Day after Thanksgiving']['date'])->toBe('2026-11-27')
        ->and($holidays['Memorial Day']['date'])->toBe('2026-05-25')
        ->and($holidays['Labor Day']['date'])->toBe('2026-09-07')
        ->and($holidays['Martin Luther King Jr. Day']['date'])->toBe('2026-01-19')
        // July 4th 2026 is a Saturday: the crew takes Friday the 3rd.
        ->and($holidays['Independence Day']['date'])->toBe('2026-07-04')
        ->and($holidays['Independence Day (observed)']['date'])->toBe('2026-07-03')
        ->and($holidays['Independence Day']['kind'])->toBe(ConstructionHolidays::CREW_OFF)
        ->and($holidays['Veterans Day']['kind'])->toBe(ConstructionHolidays::OFFICES_CLOSED);
});

it('counts working days without weekends or the crew\'s holidays', function () {
    // Thanksgiving week 2026: Mon–Wed are worked, Thu and Fri are off.
    expect(ProjectDuration::workingDays(CarbonImmutable::parse('2026-11-23'), CarbonImmutable::parse('2026-11-29')))->toBe(3)
        // Veterans Day closes offices, not the crew.
        ->and(ProjectDuration::workingDays(CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-13')))->toBe(5);
});

it('is only for Patryk and Greg', function () {
    $someoneElse = User::factory()->create();

    Livewire::actingAs($someoneElse)->test(ProjectTimeline::class)->assertForbidden();
    expect(User::find(1)->can('estimate-project-duration'))->toBeTrue()
        ->and(User::find(2)->can('estimate-project-duration'))->toBeTrue()
        ->and($someoneElse->can('estimate-project-duration'))->toBeFalse();
});

it('shows the whole year inside a card, where the page column does not hide it', function () {
    $html = Livewire::actingAs(User::find(1))
        ->test(ProjectTimeline::class, ['from' => '2026-01'])
        ->html();

    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    expect($xpath->query('//*[@data-flux-card]//*[@data-timeline-from="2026-01"]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-timeline-from="2026-01"]//button[@data-date]')->length)->toBe(365);
});

it('sets the start, marks the finish and lists the holidays the project runs across', function () {
    Livewire::actingAs(User::find(1))
        ->test(ProjectTimeline::class, ['from' => '2026-01'])
        ->set('length', '8w')
        ->call('pickDate', '2026-10-27')
        ->assertSet('start', '2026-10-27')
        ->assertSeeInOrder(['Tue, Oct 27, 2026', 'Mon, Dec 21, 2026'])
        ->assertSee('Nov 26 · Thanksgiving')
        ->assertSee('Nov 11 · Veterans Day')
        // The grid still marks Christmas; the project's list stops at Dec 21.
        ->assertDontSee('Dec 25 · Christmas Day');
});

it('takes the cabinet delivery as a week and starts the prep weeks before its Monday', function () {
    // Thursday Dec 3 sits in the row Sun Nov 29 – Sat Dec 5; most of it is December.
    $page = Livewire::actingAs(User::find(1))
        ->test(ProjectTimeline::class, ['from' => '2026-01'])
        ->set('clickSets', 'cabinets')
        ->set('prepWeeks', 3)
        ->call('pickDate', '2026-12-03')
        ->assertSet('cabinets', '2026-11-30')
        // The Tuesday of the week three weeks before: GS never demos on a Monday.
        ->assertSet('start', '2026-11-10')
        ->assertSee('1st week of December')
        ->assertSee('Mon Nov 30 – Fri Dec 4');

    // A longer prep moves the start, not the delivery.
    $page->set('prepWeeks', 4)
        ->assertSet('cabinets', '2026-11-30')
        ->assertSet('start', '2026-11-03');

    expect(timelineCell($page->html(), '2026-12-04')['tone'])->toBe('cabinets')
        ->and(timelineCell($page->html(), '2026-12-05')['tone'])->not->toBe('cabinets');
});

it('warns when the cabinets arrive after the project would finish', function () {
    Livewire::actingAs(User::find(1))
        ->test(ProjectTimeline::class, ['from' => '2026-01'])
        ->set('length', '2w')
        ->set('clickSets', 'cabinets')
        ->call('pickDate', '2026-12-03')
        ->set('prepWeeks', 6)
        ->assertSee('The cabinets arrive after the finish date');
});

it('dates each faith\'s holidays', function (string $faith, string $name, string $date) {
    $holidays = collect(FaithHolidays::forYear((int) substr($date, 0, 4), [$faith]))->where('name', $name);

    expect($holidays->pluck('date')->all())->toContain($date);
})->with([
    'Good Friday' => ['christian', 'Good Friday', '2026-04-03'],
    'Easter' => ['christian', 'Easter', '2026-04-05'],
    'Orthodox Easter' => ['orthodox', 'Orthodox Easter', '2026-04-12'],
    'Orthodox Christmas' => ['orthodox', 'Orthodox Christmas', '2026-01-07'],
    'Passover' => ['jewish', 'Passover (day 1)', '2026-04-02'],
    'Rosh Hashanah' => ['jewish', 'Rosh Hashanah (day 1)', '2026-09-12'],
    'Yom Kippur' => ['jewish', 'Yom Kippur', '2026-09-21'],
    'Hanukkah' => ['jewish', 'Hanukkah begins', '2026-12-05'],
    'Eid al-Fitr' => ['muslim', 'Eid al-Fitr', '2026-03-20'],
    'Eid al-Adha' => ['muslim', 'Eid al-Adha', '2026-05-27'],
    // The Islamic year is shorter: 2033 holds Eid al-Fitr twice.
    'Eid al-Fitr in January 2033' => ['muslim', 'Eid al-Fitr', '2033-01-03'],
    'Eid al-Fitr in December 2033' => ['muslim', 'Eid al-Fitr', '2033-12-23'],
    'Diwali' => ['hindu', 'Diwali', '2026-11-08'],
]);

it('adds the homeowner\'s holidays and takes their holy days off the working days', function () {
    // Sep 1 – Sep 28 2026: 20 weekdays, less Labor Day.
    $page = Livewire::actingAs(User::find(1))
        ->test(ProjectTimeline::class, ['from' => '2026-01'])
        ->set('length', '4w')
        ->call('pickDate', '2026-09-01')
        ->assertSee('19 working days')
        ->assertSee('1 weekday off for holidays')
        ->assertDontSee('Yom Kippur');

    $page->set('faiths', ['jewish', 'not-a-faith'])
        ->assertSet('faiths', ['jewish'])
        ->assertSee('Mon Sep 21 · Yom Kippur')
        ->assertSeeHtml('data-holiday="2026-09-21:yom-kippur"')
        ->assertSee('18 working days')
        ->assertSee('2 weekdays off for holidays');
});

it('switches a holiday pill between the homeowner can have us and we stay away', function () {
    // Oct 27 – Dec 21 2026: 40 weekdays, less Thanksgiving and the Friday after.
    $page = Livewire::actingAs(User::find(1))
        ->test(ProjectTimeline::class, ['from' => '2026-01'])
        ->set('length', '8w')
        ->call('pickDate', '2026-10-27')
        ->assertSee('38 working days')
        ->assertSeeHtml('data-holiday="2026-11-26:thanksgiving" data-works="no"')
        ->assertSeeHtml('data-holiday="2026-11-11:veterans-day" data-works="yes"');

    $page->call('toggleHoliday', '2026-11-26:thanksgiving')
        ->assertSet('holidayChanges', ['2026-11-26:thanksgiving'])
        ->assertSeeHtml('data-holiday="2026-11-26:thanksgiving" data-works="yes"')
        ->assertSee('39 working days');

    // Veterans Day starts green (only offices close); this homeowner says no.
    $page->call('toggleHoliday', '2026-11-11:veterans-day')
        ->assertSeeHtml('data-holiday="2026-11-11:veterans-day" data-works="no"')
        ->assertSee('38 working days');

    $page->call('toggleHoliday', '2026-11-26:thanksgiving')
        ->assertSet('holidayChanges', ['2026-11-11:veterans-day'])
        ->assertSee('37 working days')
        ->call('toggleHoliday', 'not a key')
        ->assertSet('holidayChanges', ['2026-11-11:veterans-day']);
});

it('knows the Illinois holidays', function () {
    $holidays = collect(ConstructionHolidays::forYear(2026))->keyBy('name');

    expect($holidays["Lincoln's Birthday"]['date'])->toBe('2026-02-12')
        ->and($holidays['Casimir Pulaski Day']['date'])->toBe('2026-03-02')
        // The general election, even years only.
        ->and($holidays['Election Day']['date'])->toBe('2026-11-03')
        ->and(collect(ConstructionHolidays::forYear(2027))->pluck('name'))->not->toContain('Election Day');
});

it('marks GS Construction\'s limited weeks around Thanksgiving and the New Year', function () {
    // Without Easter and the Orthodox holidays, which have their own test.
    expect(ConstructionHolidays::limitedDays(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2027-01-31'), easterDays: false, orthodoxDays: false))->toBe([
        '2026-11-23', '2026-11-24', '2026-11-25', '2026-11-26', '2026-11-27', '2026-11-28', '2026-11-29',
        '2026-12-23', '2026-12-24', '2026-12-25', '2026-12-26', '2026-12-27', '2026-12-28', '2026-12-29', '2026-12-30', '2026-12-31',
        '2027-01-01',
    ])
        // Last year's run reaches into January.
        ->and(ConstructionHolidays::limitedDays(CarbonImmutable::parse('2027-01-01'), CarbonImmutable::parse('2027-01-05')))->toBe(['2027-01-01']);
});

it('thins the crew around Easter and on the Ukrainian Orthodox holidays', function () {
    $reasons = ConstructionHolidays::limitedDayReasons(CarbonImmutable::parse('2026-01-03'), CarbonImmutable::parse('2026-06-30'));

    expect($reasons)->toBe([
        '2026-01-06' => 'Orthodox Christmas Eve',
        '2026-01-07' => 'Orthodox Christmas',
        '2026-01-19' => 'Theophany',
        // Easter is Apr 5: the day before Good Friday, Good Friday, the day after.
        '2026-04-02' => 'Holy Thursday',
        '2026-04-03' => 'Good Friday',
        '2026-04-06' => 'Easter Monday',
        // Orthodox Easter is Apr 12; Trinity is seven weeks on.
        '2026-04-10' => 'Orthodox Good Friday',
        '2026-04-13' => 'Orthodox Easter Monday',
        '2026-06-01' => 'Trinity Monday',
    ]);

    $html = Livewire::actingAs(User::find(1))->test(ProjectTimeline::class, ['from' => '2026-01'])->html();

    expect(timelineCell($html, '2026-04-06')['limited'])->toBeTrue()
        ->and(str_contains($html, 'Limited day (thin crew): Easter Monday'))->toBeTrue();
});

it('bridges a lone weekday between the New Year run and a weekend', function () {
    // New Year's 2026 is a Thursday: nobody comes back for Friday Jan 2.
    expect(ConstructionHolidays::limitedDays(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-05')))->toBe(['2026-01-01', '2026-01-02'])
        // GS starts the run on Dec 23; in 2025 that is a Tuesday, so Monday Dec 22 goes with it.
        ->and(ConstructionHolidays::limitedDays(CarbonImmutable::parse('2025-12-19'), CarbonImmutable::parse('2025-12-24')))->toBe(['2025-12-22', '2025-12-23', '2025-12-24'])
        // Without the day before: Christmas Eve 2024 is a Tuesday, so Monday Dec 23 goes with it.
        ->and(ConstructionHolidays::limitedDays(CarbonImmutable::parse('2024-12-20'), CarbonImmutable::parse('2024-12-24'), dayBeforeChristmasEve: false))->toBe(['2024-12-23', '2024-12-24'])
        ->and(ConstructionHolidays::limitedDays(CarbonImmutable::parse('2026-12-20'), CarbonImmutable::parse('2026-12-24'), dayBeforeChristmasEve: false))->toBe(['2026-12-24']);

    expect(timelineCell(Livewire::actingAs(User::find(1))->test(ProjectTimeline::class, ['from' => '2026-01'])->html(), '2026-01-02'))
        ->toBe(['tone' => 'day', 'limited' => true]);
});

it('paints holidays as red tiles (faith indigo), solid when we stay away, and limited days with a dotted border', function () {
    $page = Livewire::actingAs(User::find(1))
        ->test(ProjectTimeline::class, ['from' => '2026-01'])
        ->set('faiths', ['jewish']);

    expect(timelineCell($page->html(), '2026-11-26'))->toBe(['tone' => 'holiday_off', 'limited' => true])
        ->and(timelineCell($page->html(), '2026-11-11'))->toBe(['tone' => 'holiday_on', 'limited' => false])
        ->and(timelineCell($page->html(), '2026-09-21'))->toBe(['tone' => 'faith_off', 'limited' => false])
        ->and(timelineCell($page->html(), '2026-11-24'))->toBe(['tone' => 'day', 'limited' => true])
        ->and(timelineCell($page->html(), '2026-11-17'))->toBe(['tone' => 'day', 'limited' => false]);

    $page->call('toggleHoliday', '2026-11-26:thanksgiving');

    expect(timelineCell($page->html(), '2026-11-26')['tone'])->toBe('holiday_on');
});

it('counts the limited days the project runs into', function () {
    // Oct 27 – Dec 21: Thanksgiving week's Mon–Wed are limited (Thu and Fri are off).
    Livewire::actingAs(User::find(1))
        ->test(ProjectTimeline::class, ['from' => '2026-01'])
        ->set('length', '8w')
        ->call('pickDate', '2026-10-27')
        ->assertSee('3 limited days (thin crew)')
        ->call('toggleHoliday', '2026-11-26:thanksgiving')
        ->assertSee('4 limited days (thin crew)');
});

it('fades the calendar in over the 8 days before the start and out over the 16 after the finish', function () {
    $start = CarbonImmutable::parse('2026-10-26');
    $finish = CarbonImmutable::parse('2026-12-20');
    $fade = fn (string $day) => ProjectDuration::fade(CarbonImmutable::parse($day), $start, $finish);

    expect($fade('2026-10-25'))->toBe(0.89)
        ->and($fade('2026-10-18'))->toBe(0.11)
        ->and($fade('2026-10-17'))->toBeNull()
        ->and($fade('2026-11-10'))->toBeNull()
        ->and($fade('2026-12-21'))->toBe(0.94)
        ->and($fade('2027-01-05'))->toBe(0.06)
        ->and($fade('2027-01-06'))->toBeNull();

    $html = Livewire::actingAs(User::find(1))
        ->test(ProjectTimeline::class, ['from' => '2026-01'])
        ->set('length', '8w')
        ->call('pickDate', '2026-10-27')
        ->html();

    expect(timelineFade($html, '2026-10-26'))->toBe('0.89')
        ->and(timelineFade($html, '2026-10-18'))->toBeNull()
        ->and(timelineFade($html, '2026-12-22'))->toBe('0.94')
        // A holiday keeps its own tile inside the fade.
        ->and(timelineFade($html, '2026-12-25'))->toBeNull();
});

it('greys out the week each GS job starts, and will not start a project there', function () {
    $gs = Vendor::factory()->create();
    $otherCompany = Vendor::factory()->create();
    $patryk = User::find(1);
    $patryk->forceFill(['primary_vendor_id' => $gs->id])->save();
    $this->actingAs($patryk);

    timelineJob($gs->id, 5, '2026-11-02');                  // Scheduled: its estimate starts Mon Nov 2
    timelineJob($gs->id, 6, '2026-06-29', statusDate: '2026-09-14'); // Active: its estimate start, not the status date
    timelineJob($gs->id, 7, '2026-06-01');                  // Complete: no longer holds a week
    timelineJob($otherCompany->id, 5, '2026-08-03');        // someone else's job

    $page = Livewire::actingAs($patryk)->test(ProjectTimeline::class, ['from' => '2026-01']);
    $html = $page->html();

    expect(timelineCell($html, '2026-11-01')['tone'])->toBe('weekend')
        ->and(timelineCell($html, '2026-11-02')['tone'])->toBe('booked')
        ->and(timelineCell($html, '2026-11-08')['tone'])->toBe('booked')
        ->and(timelineCell($html, '2026-11-09')['tone'])->toBe('day')
        ->and(timelineCell($html, '2026-06-30')['tone'])->toBe('booked')
        ->and(timelineCell($html, '2026-09-16')['tone'])->toBe('day')
        ->and(timelineCell($html, '2026-06-02')['tone'])->toBe('day')
        ->and(timelineCell($html, '2026-08-04')['tone'])->toBe('day')
        ->and($page->instance()->bookedDays['2026-11-03'])->toContain('(123 Main) starts Mon Nov 2');

    // Election Day (Nov 3) keeps its red tile but is just as booked.
    expect(timelineCell($html, '2026-11-03')['tone'])->toBe('holiday_on');

    $page->assertSeeHtml('wire:dblclick="pickDate(\'2026-11-04\', true)"')
        ->call('pickDate', '2026-11-03')
        ->assertSet('start', null)
        ->call('pickDate', '2026-11-04')
        ->assertSet('start', null)
        ->call('pickDate', '2026-11-10')
        ->assertSet('start', '2026-11-10')
        ->assertDontSee('The start lands in a booked week');

    // A double-click starts it there anyway, and says so.
    $page->call('pickDate', '2026-11-04', true)
        ->assertSet('start', '2026-11-04')
        ->assertSee('The start lands in a booked week')
        ->assertSee('so two GS jobs would start that week');

    // Booking holds starts, not deliveries: a cabinet week can sit there.
    $page->set('clickSets', 'cabinets')
        ->call('pickDate', '2026-11-05')
        ->assertSet('cabinets', '2026-11-02');
});

it('starts a job with no estimate date on the first day it went Active, not a reopen', function () {
    $gs = Vendor::factory()->create();
    $patryk = User::find(1);
    $patryk->forceFill(['primary_vendor_id' => $gs->id])->save();
    $this->actingAs($patryk);

    // 429 Helen's history: Active, Complete, then Active again when reopened.
    $job = timelineJob($gs->id, 6, null, statusDate: '2026-06-30');
    ProjectStatus::create(['project_id' => $job->id, 'belongs_to_vendor_id' => $gs->id, 'status_code' => 7, 'start_date' => '2026-09-29']);
    ProjectStatus::create(['project_id' => $job->id, 'belongs_to_vendor_id' => $gs->id, 'status_code' => 6, 'start_date' => '2026-10-21']);

    $html = Livewire::actingAs($patryk)->test(ProjectTimeline::class, ['from' => '2026-01'])->html();

    expect(timelineCell($html, '2026-07-01')['tone'])->toBe('booked')
        ->and(timelineCell($html, '2026-10-22')['tone'])->toBe('day');
});

it('warns when a cabinet week puts the start in a booked week', function () {
    $gs = Vendor::factory()->create();
    $patryk = User::find(1);
    $patryk->forceFill(['primary_vendor_id' => $gs->id])->save();
    $this->actingAs($patryk);
    timelineJob($gs->id, 5, '2026-11-02');

    // Cabinets the week of Nov 30, 4 weeks of work before: the start is Nov 2.
    Livewire::actingAs($patryk)
        ->test(ProjectTimeline::class, ['from' => '2026-01'])
        ->set('clickSets', 'cabinets')
        ->set('prepWeeks', 4)
        ->call('pickDate', '2026-12-02')
        ->assertSet('start', '2026-11-03')
        ->assertSee('The start lands in a booked week');
});

it('shows the holidays we work, like Pulaski Day, in yellow', function () {
    $page = Livewire::actingAs(User::find(1))
        ->test(ProjectTimeline::class, ['from' => '2026-01'])
        ->set('length', '4w')
        ->call('pickDate', '2026-02-24');

    expect(timelineCell($page->html(), '2026-03-02')['tone'])->toBe('holiday_on')
        ->and(timelineClass($page->html(), '2026-03-02'))->toContain('bg-yellow-200');

    $page->assertSeeHtml('data-holiday="2026-03-02:casimir-pulaski-day" data-works="yes"');
});

it('shows twelve months from this month, a month at a time', function () {
    $this->travelTo('2026-10-23 09:00:00');

    $page = Livewire::actingAs(User::find(1))
        ->test(ProjectTimeline::class)
        ->assertSet('from', '2026-10')
        ->assertSee('Oct 2026 – Sep 2027')
        ->assertSeeInOrder(['October 2026', 'December 2026', 'January 2027', 'September 2027']);

    expect(timelineCell($page->html(), '2027-09-30')['tone'])->toBe('day');
    expect(str_contains($page->html(), 'data-date="2026-09-30"'))->toBeFalse();

    $page->call('nextMonth')->assertSet('from', '2026-11')->assertSee('Nov 2026 – Oct 2027')
        ->call('previousMonth')->call('previousMonth')->assertSet('from', '2026-09')
        ->call('thisMonth')->assertSet('from', '2026-10');
});

it('slides the months to a finish past the last one', function () {
    $this->travelTo('2026-10-23 09:00:00');

    // 12 months from Tue Oct 27 finishes Mon Oct 26, 2027: past September.
    Livewire::actingAs(User::find(1))
        ->test(ProjectTimeline::class)
        ->set('length', '12m')
        ->call('pickDate', '2026-10-27')
        ->assertSee('Continues into Oct 2027')
        ->call('showProject')
        ->assertSet('from', '2026-11')
        ->assertDontSee('Continues into Oct 2027');
});

it('will not pick a day before today, nor snap a start back onto one', function () {
    // Friday Oct 23, 2026: Thursday the 22nd is gone.
    $this->travelTo('2026-10-23 09:00:00');

    $page = Livewire::actingAs(User::find(1))
        ->test(ProjectTimeline::class)
        ->set('length', '4w')
        ->call('pickDate', '2026-10-20')
        ->assertSet('start', null)
        ->call('pickDate', '2026-10-23')
        ->assertSet('start', '2026-10-27');

    $html = $page->html();
    expect(timelineCell($html, '2026-10-22')['tone'])->toBe('past')
        ->and(str_contains($html, "pickDate('2026-10-22')"))->toBeFalse()
        ->and(str_contains($html, "pickDate('2026-10-23')"))->toBeTrue()
        ->and(ProjectDuration::demoDay(CarbonImmutable::parse('2026-10-23'), [2, 3, 4], CarbonImmutable::parse('2026-10-23'))->toDateString())->toBe('2026-10-27');

    // Cabinets the week of Nov 2 with 3 weeks needed: the best week (Oct 12) is gone.
    $page->set('clickSets', 'cabinets')
        ->set('prepWeeks', 3)
        ->call('pickDate', '2026-11-04')
        ->assertSet('cabinets', '2026-11-02')
        ->assertSet('start', '2026-10-27')
        ->assertSee('The best start has passed');
});

it('names the churches the Orthodox option covers', function () {
    Livewire::actingAs(User::find(1))
        ->test(ProjectTimeline::class, ['from' => '2026-01'])
        ->assertSee('Orthodox (Greek, Serbian, Ukrainian…)')
        // The legend says only "Limited days"; each day's tooltip says why.
        ->assertDontSee('GS takes about a week off');
});

it('dims weekends and leaves them out of hovering and clicking', function () {
    $html = Livewire::actingAs(User::find(1))
        ->test(ProjectTimeline::class, ['from' => '2026-01'])
        ->set('length', '4w')
        ->call('pickDate', '2026-10-27')
        ->html();

    // Saturday Nov 7, inside the project: shaded but dim, and inert.
    expect(timelineClass($html, '2026-11-07'))->toContain('opacity-50')
        ->and(str_contains($html, "pickDate('2026-11-07')"))->toBeFalse()
        ->and(str_contains($html, "show('2026-11-07'"))->toBeFalse()
        // The finish, Monday Nov 23, and the weekdays stay as they were.
        ->and(timelineClass($html, '2026-11-06'))->not->toContain('opacity-50')
        ->and(str_contains($html, "pickDate('2026-11-06')"))->toBeTrue()
        ->and(str_contains($html, "show('2026-11-06'"))->toBeTrue();
});

it('opens blank: nothing preselected', function () {
    Livewire::actingAs(User::find(1))
        ->test(ProjectTimeline::class)
        ->assertSet('from', now()->format('Y-m'))
        ->assertSet('start', null)
        ->assertSet('cabinets', null)
        ->assertSet('length', null)
        ->assertSet('prepWeeks', null)
        ->assertSet('clickSets', null)
        ->assertSet('faiths', [])
        ->assertDontSeeHtml('data-timeline-summary')
        ->assertDontSeeHtml('data-timeline-holidays')
        ->assertSee('Pick a project length to start; the calendar previews it as you hover.')
        ->assertDontSee('Clear dates')
        ->set('length', '8w')
        ->assertSee('Click a day on the calendar for the start');
});

it('only lets the weeks before cabinets be picked when working from a cabinet week', function () {
    $page = Livewire::actingAs(User::find(1))->test(ProjectTimeline::class, ['from' => '2026-01']);

    expect(substr_count($page->html(), 'disabled'))->toBeGreaterThanOrEqual(count(ProjectDuration::PREP_WEEKS));

    $page->set('clickSets', 'cabinets');
    $withCabinets = substr_count($page->html(), 'disabled');

    $page->set('clickSets', 'start');
    expect($withCabinets)->toBeLessThan(substr_count($page->html(), 'disabled'));
});

it('sets the start on a first click, and waits for a length before the finish', function () {
    Livewire::actingAs(User::find(1))
        ->test(ProjectTimeline::class, ['from' => '2026-01'])
        ->call('pickDate', '2026-10-27')
        ->assertSet('clickSets', 'start')
        ->assertSee('Tue, Oct 27, 2026')
        ->assertSee('Pick a project length.')
        ->assertDontSee('working days')
        ->set('length', '4w')
        ->assertSee('Mon, Nov 23, 2026');
});

it('waits for the prep weeks before starting from a cabinet week', function () {
    Livewire::actingAs(User::find(1))
        ->test(ProjectTimeline::class, ['from' => '2026-01'])
        ->set('clickSets', 'cabinets')
        ->call('pickDate', '2026-12-03')
        ->assertSet('start', null)
        ->assertSee('Pick the weeks of work before cabinets.')
        ->set('prepWeeks', 2)
        ->assertSet('start', '2026-11-17');
});

it('lists a picked faith\'s holidays for the year with no project set, in indigo', function () {
    $page = Livewire::actingAs(User::find(1))
        ->test(ProjectTimeline::class, ['from' => '2026-01'])
        ->set('faiths', ['jewish'])
        ->assertSee('Jewish · Jan 2026 – Dec 2026')
        ->assertSeeHtml('data-holiday="2026-09-21:yom-kippur" data-works="no"')
        ->assertSeeHtml('data-holiday="2026-04-02:passover-day-1"')
        // US & Illinois pills wait for a project.
        ->assertDontSeeHtml('data-holiday="2026-11-26:thanksgiving"');

    expect(timelineCell($page->html(), '2026-09-21')['tone'])->toBe('faith_off')
        ->and(timelineCell($page->html(), '2026-03-03')['tone'])->toBe('faith_on')
        ->and(timelineCell($page->html(), '2026-11-26')['tone'])->toBe('holiday_off');

    // With a project set the faith's whole year still shows, and its project holidays count.
    $page->set('length', '4w')
        ->call('pickDate', '2026-09-01')
        ->assertSeeHtml('data-holiday="2026-04-02:passover-day-1"')
        ->assertSeeHtml('data-holiday="2026-09-07:labor-day"')
        ->assertSee('18 working days');
});
