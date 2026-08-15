<?php

use App\Models\Task;

/*
 * The task modal holds its own copy of the checklist from when it opened,
 * while ticking a box on a task CARD writes straight to the task. Saving the
 * modal used to overwrite the whole array and silently revert those ticks.
 */

it('keeps a box ticked elsewhere while the modal was open', function (): void {
    $original = [
        ['uid' => 'a', 'text' => 'Staircase 3-way', 'completed' => false],
        ['uid' => 'b', 'text' => 'Closet light', 'completed' => false],
    ];

    // Crew ticked item B from a card after the modal loaded.
    $stored = [
        ['uid' => 'a', 'text' => 'Staircase 3-way', 'completed' => false],
        ['uid' => 'b', 'text' => 'Closet light', 'completed' => true],
    ];

    // The modal saves its stale copy (B still unticked in its memory).
    $merged = Task::mergeChecklist($stored, $original, $original);

    expect(collect($merged)->firstWhere('uid', 'b')['completed'])->toBeTrue();
});

it('still honors a box the modal itself unticked', function (): void {
    $original = [['uid' => 'a', 'text' => 'Rough-in', 'completed' => true]];
    $stored = [['uid' => 'a', 'text' => 'Rough-in', 'completed' => true]];
    $incoming = [['uid' => 'a', 'text' => 'Rough-in', 'completed' => false]];

    $merged = Task::mergeChecklist($stored, $original, $incoming);

    expect($merged[0]['completed'])->toBeFalse();
});

it('keeps items added elsewhere and honors modal removals', function (): void {
    $original = [
        ['uid' => 'a', 'text' => 'Keep me', 'completed' => false],
        ['uid' => 'b', 'text' => 'Remove me', 'completed' => false],
    ];

    // Someone added C from a card; the modal meanwhile removed B.
    $stored = array_merge($original, [['uid' => 'c', 'text' => 'Added on site', 'completed' => true]]);
    $incoming = [['uid' => 'a', 'text' => 'Keep me', 'completed' => false]];

    $merged = Task::mergeChecklist($stored, $original, $incoming);
    $uids = collect($merged)->pluck('uid')->all();

    expect($uids)->toContain('a')
        ->and($uids)->toContain('c')
        ->and($uids)->not->toContain('b');
});

it('takes text edits and ordering from the modal', function (): void {
    $original = [
        ['uid' => 'a', 'text' => 'First', 'completed' => false],
        ['uid' => 'b', 'text' => 'Second', 'completed' => false],
    ];

    $incoming = [
        ['uid' => 'b', 'text' => 'Second', 'completed' => false],
        ['uid' => 'a', 'text' => 'First (edited)', 'completed' => false],
    ];

    $merged = Task::mergeChecklist($original, $original, $incoming);

    expect(collect($merged)->pluck('uid')->all())->toBe(['b', 'a'])
        ->and($merged[1]['text'])->toBe('First (edited)');
});

it('merges legacy items that have no uid by their text', function (): void {
    $original = [['text' => 'Legacy item', 'completed' => false]];
    $stored = [['text' => 'Legacy item', 'completed' => true]]; // ticked from a card

    $merged = Task::mergeChecklist($stored, $original, $original);

    expect($merged[0]['completed'])->toBeTrue();
});

it('gives every normalized item a stable uid and clean shape', function (): void {
    $items = Task::normalizeChecklist([
        ['text' => 'No uid here', 'completed' => 1],
        'plain string item',
    ]);

    expect($items)->toHaveCount(2)
        ->and($items[0]['uid'])->not->toBeEmpty()
        ->and($items[0]['completed'])->toBeTrue()
        ->and($items[1]['text'])->toBe('plain string item')
        ->and($items[1]['completed'])->toBeFalse();
});
