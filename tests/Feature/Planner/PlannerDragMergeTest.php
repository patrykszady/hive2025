<?php

use App\Livewire\Planner\CardsIndex;
use App\Models\Task;
use Carbon\Carbon;

/**
 * Dragging a bar rewrites the run of dates under it. Bars span weekends
 * visually, so the pointer's range is not the day list: weekend days only
 * join when the dragged run already had them, a move keeps its working-day
 * count, a resize fills the new range, and per-day arrival times follow.
 *
 * 2026-09-21 is a Monday.
 */
function mergeDraggedDates(array $options, string $oldStart, string $oldEnd, string $newStart, string $newEnd, ?string $start = null, ?string $end = null): array
{
    $task = new Task();
    $task->options = $options;
    if ($start !== null) {
        $task->start_date = $start;
        $task->end_date = $end;
    }

    $method = new ReflectionMethod(CardsIndex::class, 'replaceTaskDateSegment');

    return $method->invoke(
        new CardsIndex(),
        $task,
        Carbon::parse($oldStart)->startOfDay(),
        Carbon::parse($oldEnd)->startOfDay(),
        Carbon::parse($newStart)->startOfDay(),
        Carbon::parse($newEnd)->startOfDay(),
    );
}

const WEEKDAYS_21_TO_25 = ['2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25'];

it('moves a weekday run without adding weekend days and keeps its working-day count', function (): void {
    $options = mergeDraggedDates(['dates' => WEEKDAYS_21_TO_25], '2026-09-21', '2026-09-25', '2026-09-23', '2026-09-27');

    expect($options['dates'])->toBe(['2026-09-23', '2026-09-24', '2026-09-25', '2026-09-28', '2026-09-29']);
});

it('moves a weekday run backwards across a weekend the same way', function (): void {
    $options = mergeDraggedDates(['dates' => WEEKDAYS_21_TO_25], '2026-09-21', '2026-09-25', '2026-09-17', '2026-09-21');

    expect($options['dates'])->toBe(['2026-09-17', '2026-09-18', '2026-09-21', '2026-09-22', '2026-09-23']);
});

it('resizes by filling the working days of the new range', function (): void {
    $options = mergeDraggedDates(['dates' => WEEKDAYS_21_TO_25], '2026-09-21', '2026-09-25', '2026-09-21', '2026-09-29');

    expect($options['dates'])->toBe([...WEEKDAYS_21_TO_25, '2026-09-28', '2026-09-29']);
});

it('keeps weekend days eligible when the dragged run already worked them', function (): void {
    $options = mergeDraggedDates(['dates' => [...WEEKDAYS_21_TO_25, '2026-09-26']], '2026-09-21', '2026-09-26', '2026-09-22', '2026-09-27');

    expect($options['dates'])->toBe(['2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25', '2026-09-26', '2026-09-28']);
});

it('honours the task\'s saturday and sunday flags', function (): void {
    $options = mergeDraggedDates(['dates' => ['2026-09-24', '2026-09-25'], 'saturday' => true, 'sunday' => true], '2026-09-24', '2026-09-25', '2026-09-25', '2026-09-26');

    expect($options['dates'])->toBe(['2026-09-25', '2026-09-26']);
});

it('moves per-day arrival times with their days', function (): void {
    $options = mergeDraggedDates([
        'dates' => ['2026-09-21', '2026-09-22'],
        'time_settings' => [
            '2026-09-21' => ['use_time' => true, 'start_time' => '08:00'],
            '2026-09-22' => ['use_time' => true, 'start_time' => '13:00'],
        ],
    ], '2026-09-21', '2026-09-22', '2026-09-28', '2026-09-29');

    expect($options['dates'])->toBe(['2026-09-28', '2026-09-29'])
        ->and($options['time_settings'])->toBe([
            '2026-09-28' => ['use_time' => true, 'start_time' => '08:00'],
            '2026-09-29' => ['use_time' => true, 'start_time' => '13:00'],
        ]);
});

it('leaves the other runs of a split task alone', function (): void {
    $options = mergeDraggedDates(['dates' => ['2026-09-14', '2026-09-15', ...WEEKDAYS_21_TO_25]], '2026-09-21', '2026-09-25', '2026-09-22', '2026-09-26');

    expect($options['dates'])->toBe(['2026-09-14', '2026-09-15', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25', '2026-09-28']);
});

it('treats a legacy task without a dates list as spanning every day of its range', function (): void {
    $options = mergeDraggedDates([], '2026-09-21', '2026-09-27', '2026-09-22', '2026-09-28', '2026-09-21', '2026-09-27');

    expect($options['dates'])->toBe(['2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25', '2026-09-26', '2026-09-27', '2026-09-28']);
});
