<?php

use App\Livewire\Planner\CardsIndex;

/**
 * The gantt's day window: grows a chunk per infinite-scroll load, never past
 * MAX_DAYS_LOADED, and slides past today instead of staying anchored to it.
 */
it('extends the window by one chunk per load', function (): void {
    $component = new CardsIndex();

    $component->loadFutureDays();

    expect($component->futureDaysLoaded)->toBe(7 + CardsIndex::DAYS_PER_LOAD)
        ->and($component->previousDaysLoaded)->toBe(7);

    $component->loadPreviousDays();

    expect($component->previousDaysLoaded)->toBe(7 + CardsIndex::DAYS_PER_LOAD);
});

it('caps the window and evicts days from the side being scrolled away from', function (): void {
    $component = new CardsIndex();

    for ($i = 0; $i < 20; $i++) {
        $component->loadFutureDays();
    }

    expect($component->previousDaysLoaded + $component->futureDaysLoaded)->toBe(CardsIndex::MAX_DAYS_LOADED)
        ->and($component->futureDaysLoaded)->toBe(7 + 20 * CardsIndex::DAYS_PER_LOAD)
        ->and($component->previousDaysLoaded)->toBeLessThan(0);

    $futureBefore = $component->futureDaysLoaded;
    $component->loadPreviousDays();

    expect($component->previousDaysLoaded + $component->futureDaysLoaded)->toBe(CardsIndex::MAX_DAYS_LOADED)
        ->and($component->futureDaysLoaded)->toBe($futureBefore - CardsIndex::DAYS_PER_LOAD);
});

it('builds the day range from the counters even when the window starts after today', function (): void {
    $component = new CardsIndex();
    $component->previousDaysLoaded = -3;
    $component->futureDaysLoaded = 10;

    $days = $component->days();

    expect($days)->toHaveCount(7)
        ->and($days->first()->toDateString())->toBe(browser_today()->addDays(3)->toDateString())
        ->and($days->last()->toDateString())->toBe(browser_today()->addDays(9)->toDateString());
});
