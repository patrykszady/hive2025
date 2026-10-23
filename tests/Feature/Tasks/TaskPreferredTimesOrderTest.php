<?php

use App\Models\Project;
use App\Services\Tasks\TaskPreferredTimes;

/*
 * Homeowner submitted times (Edit Task → Dates, and the vendor availability
 * page) list each day's time frames in the order of the day, not the order
 * the homeowner ticked them (client 335, Wed Oct 28: 11-1 PM, 3-5 PM, 7-9 AM,
 * 9-11 AM — 2026-10-23).
 */

it('orders a day\'s time frames by the time of day', function () {
    expect(TaskPreferredTimes::inDayOrder(['11-1 PM', '3-5 PM', '7-9 AM', '9-11 AM', 'Anytime', 'Evening', '1-3 PM']))
        ->toBe(['Anytime', '7-9 AM', '9-11 AM', '11-1 PM', '1-3 PM', '3-5 PM', 'Evening']);
});

it('lists the homeowner\'s submitted times in the order of the day', function () {
    $project = new Project(['service_availability' => ['slots' => [
        ['date' => '2026-10-28', 'time' => '11-1 PM'],
        ['date' => '2026-10-28', 'time' => '3-5 PM'],
        ['date' => '2026-10-28', 'time' => '7-9 AM'],
        ['date' => '2026-10-28', 'time' => '9-11 AM'],
        ['date' => '2026-10-29', 'time' => 'Anytime'],
    ]]]);

    $days = app(TaskPreferredTimes::class)->groupedSlots($project, null);

    expect(array_column($days[0]['times'], 'time'))->toBe(['7-9 AM', '9-11 AM', '11-1 PM', '3-5 PM'])
        ->and($days[0]['label'])->toBe('Wed, Oct 28')
        ->and(array_column($days[1]['times'], 'time'))->toBe(['Anytime']);
});
