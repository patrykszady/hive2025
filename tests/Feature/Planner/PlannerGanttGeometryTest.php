<?php

use App\Livewire\Planner\CardsIndex;
use App\Models\Task;
use Carbon\Carbon;

/**
 * Bar geometry is a calendar-day calculation. The window is built in the
 * browser's timezone (America/Chicago here) while task dates are UTC
 * midnights; comparing instants dropped every bar that ended on the first
 * visible day and marked bars starting on it as truncated.
 */
function ganttBar(string $start, string $end, string $firstDay, string $lastDay, int $pxPerDay = 100): ?array
{
    $task = new Task();
    $task->id = 1;

    $method = new ReflectionMethod(CardsIndex::class, 'buildGanttBar');

    return $method->invoke(
        new CardsIndex(),
        $task,
        Carbon::parse($start, 'UTC')->startOfDay(),
        Carbon::parse($end, 'UTC')->startOfDay(),
        Carbon::parse($firstDay, 'America/Chicago')->startOfDay(),
        Carbon::parse($lastDay, 'America/Chicago')->startOfDay(),
        $pxPerDay,
        [],
        collect(),
    );
}

it('keeps a bar that ends on the first visible day when the window is in another timezone', function (): void {
    $bar = ganttBar('2026-09-21', '2026-09-23', '2026-09-23', '2026-10-06');

    expect($bar)->not->toBeNull()
        ->and($bar['left_px'])->toBe(0)
        ->and($bar['width_px'])->toBe(100)
        ->and($bar['truncated_left'])->toBeTrue()
        ->and($bar['truncated_right'])->toBeFalse();
});

it('does not mark a bar that starts on the first visible day as truncated', function (): void {
    $bar = ganttBar('2026-09-23', '2026-09-25', '2026-09-23', '2026-10-06');

    expect($bar['left_px'])->toBe(0)
        ->and($bar['width_px'])->toBe(300)
        ->and($bar['truncated_left'])->toBeFalse();
});

it('drops a bar that ends before the window or starts after it', function (): void {
    expect(ganttBar('2026-09-20', '2026-09-22', '2026-09-23', '2026-10-06'))->toBeNull()
        ->and(ganttBar('2026-10-07', '2026-10-09', '2026-09-23', '2026-10-06'))->toBeNull();
});

it('positions and clips a bar by calendar day', function (): void {
    $bar = ganttBar('2026-09-25', '2026-10-08', '2026-09-23', '2026-10-06');

    expect($bar['left_px'])->toBe(200)
        ->and($bar['width_px'])->toBe(1200)
        ->and($bar['truncated_right'])->toBeTrue();
});

it('counts whole days across daylight-saving changes in either direction', function (): void {
    expect(CardsIndex::daysBetweenYmd('2026-03-07', '2026-03-09'))->toBe(2)
        ->and(CardsIndex::daysBetweenYmd('2026-10-31', '2026-11-02'))->toBe(2)
        ->and(CardsIndex::daysBetweenYmd('2026-11-02', '2026-10-31'))->toBe(-2)
        ->and(CardsIndex::daysBetweenYmd('2026-09-23', '2026-09-23'))->toBe(0);
});
