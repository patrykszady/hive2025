<?php

use App\Livewire\Estimates\ProjectTimeline;
use App\Livewire\Estimates\ProjectTimelineSettings;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Estimates\ProjectDuration;
use App\Services\Estimates\TimelineSettings;
use App\Support\ConstructionHolidays;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/../../Support/project-timeline-fixtures.php';

uses(RefreshDatabase::class);

// The 2026 calendar is ahead: days before today cannot be picked.
beforeEach(fn () => $this->travelTo('2026-01-01 09:00:00'));

/*
 * Each Hive company's own project timeline rules (2026-10-23): demo days,
 * holidays worked or not, limited weeks, booked weeks and the fade, starting
 * from GS Construction's.
 */

/** User 1 as the admin of a fresh company. */
function timelineCompanyAdmin(): array
{
    $company = Vendor::factory()->create();
    $patryk = User::find(1);
    $company->users()->attach($patryk->id, ['role_id' => 1, 'is_employed' => 1]);
    $patryk->forceFill(['primary_vendor_id' => $company->id])->save();

    return [$company, $patryk->fresh()];
}

it('starts every company from GS Construction\'s rules', function () {
    $settings = TimelineSettings::forVendor(Vendor::factory()->create());

    expect($settings->demoDays)->toBe([2, 3, 4])
        ->and($settings->holidayChoice('Thanksgiving'))->toBe('off')
        ->and($settings->holidayChoice('Casimir Pulaski Day'))->toBe('work')
        ->and($settings->holidayChoice('Independence Day (observed)'))->toBe('off')
        ->and([$settings->thanksgivingWeek, $settings->christmasWeek, $settings->dayBeforeChristmasEve, $settings->bridgeDays, $settings->easterDays, $settings->orthodoxDays, $settings->bookedWeeks])->toBe([true, true, true, true, true, true, true])
        ->and([$settings->bookedDays, $settings->bookedStatuses, $settings->fadeBeforeDays, $settings->fadeAfterDays])->toBe([7, [5, 6], 8, 16]);
});

it('keeps only sane values when reading settings back', function () {
    $settings = TimelineSettings::fromArray([
        'demo_days' => ['1', 9, 'x', 3],
        'holidays' => ['thanksgiving' => 'work', 'casimir-pulaski-day' => 'work', 'not-a-holiday' => 'hide', 'labor-day' => 'nap'],
        'booked_days' => 99,
        'booked_statuses' => [6, 7],
        'fade_before_days' => -3,
        'fade_after_days' => 500,
    ]);

    expect($settings->demoDays)->toBe([1, 3])
        // Pulaski Day's 'work' is its default, so it is not stored as a change.
        ->and($settings->holidays)->toBe(['thanksgiving' => 'work'])
        ->and($settings->bookedDays)->toBe(TimelineSettings::MAX_BOOKED_DAYS)
        ->and($settings->bookedStatuses)->toBe([6])
        ->and($settings->fadeBeforeDays)->toBe(0)
        ->and($settings->fadeAfterDays)->toBe(TimelineSettings::MAX_FADE_AFTER_DAYS)
        ->and(TimelineSettings::fromArray(['demo_days' => []])->demoDays)->toBe([2, 3, 4]);
});

it('saves a company\'s timeline settings into its options', function () {
    [$company, $patryk] = timelineCompanyAdmin();

    Livewire::actingAs($patryk)
        ->test(ProjectTimelineSettings::class)
        ->assertSet('demoDays', ['2', '3', '4'])
        ->assertSet('holidays.casimir-pulaski-day', 'work')
        ->set('demoDays', ['1', '2', '3', '4', '5'])
        ->set('holidays.christmas-eve', 'work')
        ->set('holidays.lincolns-birthday', 'hide')
        ->set('thanksgivingWeek', false)
        ->set('orthodoxDays', false)
        ->set('dayBeforeChristmasEve', false)
        ->set('bookedDays', 5)
        ->set('fadeBeforeDays', 0)
        ->call('save')
        ->assertHasNoErrors();

    $saved = TimelineSettings::forVendor($company->fresh());

    expect($saved->demoDays)->toBe([1, 2, 3, 4, 5])
        ->and($saved->holidays)->toBe(['lincolns-birthday' => 'hide', 'christmas-eve' => 'work'])
        ->and($saved->thanksgivingWeek)->toBeFalse()
        ->and($saved->orthodoxDays)->toBeFalse()
        ->and($saved->dayBeforeChristmasEve)->toBeFalse()
        ->and($saved->easterDays)->toBeTrue()
        ->and($saved->bookedDays)->toBe(5)
        ->and($saved->fadeBeforeDays)->toBe(0)
        ->and($saved->fadeAfterDays)->toBe(16);
});

it('refuses settings that would break the timeline', function () {
    [, $patryk] = timelineCompanyAdmin();

    Livewire::actingAs($patryk)
        ->test(ProjectTimelineSettings::class)
        ->set('demoDays', [])
        ->set('bookedDays', 0)
        ->set('bookedStatuses', [])
        ->set('fadeAfterDays', 61)
        ->call('save')
        ->assertHasErrors(['demoDays', 'bookedDays', 'bookedStatuses', 'fadeAfterDays'])
        ->assertSee('Pick at least one day a project can start on.')
        ->call('useDefaults')
        ->assertSet('demoDays', ['2', '3', '4'])
        ->assertHasNoErrors();
});

it('is for company admins only', function () {
    $company = Vendor::factory()->create();
    $patryk = User::find(1);
    $company->users()->attach($patryk->id, ['role_id' => 2, 'is_employed' => 1]);
    $patryk->forceFill(['primary_vendor_id' => $company->id])->save();

    Livewire::actingAs($patryk->fresh())->test(ProjectTimelineSettings::class)->assertForbidden();
    Livewire::actingAs(User::factory()->create())->test(ProjectTimelineSettings::class)->assertForbidden();
});

it('runs the timeline on the company\'s own rules', function () {
    [$company, $patryk] = timelineCompanyAdmin();
    (new TimelineSettings(
        demoDays: [1, 2, 3, 4, 5],
        holidays: ['christmas-eve' => 'work', 'lincolns-birthday' => 'hide'],
        thanksgivingWeek: false,
        bridgeDays: false,
        easterDays: false,
        fadeBeforeDays: 0,
    ))->saveFor($company);

    $page = Livewire::actingAs($patryk)
        ->test(ProjectTimeline::class, ['from' => '2026-01'])
        ->set('length', '8w')
        // Mondays are demo days here.
        ->call('pickDate', '2026-10-26')
        ->assertSet('start', '2026-10-26');
    $html = $page->html();

    expect(timelineCell($html, '2026-12-24')['tone'])->toBe('holiday_on')
        ->and(timelineCell($html, '2026-02-12')['tone'])->toBe('day')
        ->and(timelineCell($html, '2026-11-24'))->toBe(['tone' => 'project', 'limited' => false])
        ->and(timelineCell($html, '2026-12-28')['limited'])->toBeTrue()
        ->and(timelineCell($html, '2026-04-06')['limited'])->toBeFalse()
        // No bridge: Friday Jan 2 is an ordinary day.
        ->and(timelineCell($html, '2026-01-02')['limited'])->toBeFalse()
        ->and(timelineFade($html, '2026-10-25'))->toBeNull()
        ->and(timelineFade($html, '2026-12-21'))->toBe('0.94');

    $page->assertSee('Settings')
        ->assertSeeHtml('demoDays: JSON.parse');
});

it('limits only the runs a company keeps', function () {
    $days = fn (bool $thanksgiving, bool $christmas, bool $bridge) => ConstructionHolidays::limitedDays(
        CarbonImmutable::parse('2025-11-01'), CarbonImmutable::parse('2026-01-05'), $thanksgiving, $christmas, $bridge,
    );

    expect($days(false, true, true))->not->toContain('2025-11-24')->toContain('2026-01-02')
        ->and($days(true, false, true))->toContain('2025-11-24')->not->toContain('2025-12-26')
        ->and($days(true, true, false))->toContain('2026-01-01')->not->toContain('2026-01-02');

    expect(ProjectDuration::demoDay(CarbonImmutable::parse('2026-10-26'), [3])->toDateString())->toBe('2026-10-28')
        ->and(ProjectDuration::startBeforeCabinets(CarbonImmutable::parse('2026-11-30'), 3, [1, 2, 3, 4, 5])->toDateString())->toBe('2026-11-09');
});
