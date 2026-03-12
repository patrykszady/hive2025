<?php

use App\Livewire\Sms\CallList;

it('has loadMore method that increments limit by 25', function () {
    $component = new CallList();
    expect($component->limit)->toBe(25);

    $component->loadMore();
    expect($component->limit)->toBe(50);

    $component->loadMore();
    expect($component->limit)->toBe(75);
});

it('resets limit when call filter changes', function () {
    $component = new CallList();
    $component->limit = 50;

    $component->callFilter = 'missed';
    $component->updatedCallFilter();

    expect($component->limit)->toBe(25);
    expect($component->callFilter)->toBe('missed');
    expect($component->selectedCallId)->toBeNull();
});

it('normalizes invalid call filter to all', function () {
    $component = new CallList();
    $component->callFilter = 'invalid';
    $component->updatedCallFilter();

    expect($component->callFilter)->toBe('all');
});

it('accepts valid filter values', function (string $filter) {
    $component = new CallList();
    $component->callFilter = $filter;
    $component->updatedCallFilter();

    expect($component->callFilter)->toBe($filter);
})->with(['all', 'missed', 'voicemail']);

it('does not use WithPagination trait', function () {
    $traits = class_uses_recursive(CallList::class);

    expect($traits)->not->toContain('Livewire\WithPagination');
});
