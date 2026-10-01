<?php

use App\Livewire\Estimates\EstimateAIGenerator;
use App\Models\EstimateLineItem;
use App\Services\EstimateAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/estimate-ai-fixtures.php';

/**
 * Each drafted line's quantity box changes that line only (2026-09-30:
 * changing one changed every drafted line's quantity).
 */
it('gives each drafted line its own quantity box and changes only that line', function () {
    $fx = claudeEstimateFixture();
    $c = $fx['catalog'];
    $fx['user']->vendors()->attach($fx['vendor']->id, ['role_id' => 1, 'is_employed' => 1]);
    $fx['user']->refresh();

    app()->instance(EstimateAIService::class, new FakeClaudeEstimateService([
        'text' => json_encode(['reasoning' => 'Hall bath.', 'line_items' => [
            ['line_item_id' => $c['Floor Tile']->id, 'quantity' => 40],
            ['line_item_id' => $c['New GFCI Location']->id, 'quantity' => 2],
            ['line_item_id' => $c['Cement Boards']->id, 'quantity' => 10],
        ]]),
        'stop_reason' => 'end_turn',
    ]));

    $component = Livewire::test(EstimateAIGenerator::class, ['estimate' => $fx['estimate']])
        ->set('sectionId', $fx['section']->id)
        ->set('inquiry', 'Hall bath: new tile floor and two outlets.');

    capturingLivewireStream(fn () => $component->call('generate'));

    preg_match_all('/wire:model[^=]*="(generatedItems\.\d+\.quantity)"/', $component->html(), $bindings);
    expect($bindings[1])->toBe(['generatedItems.0.quantity', 'generatedItems.1.quantity', 'generatedItems.2.quantity']);

    $component->set('generatedItems.1.quantity', 5);

    $quantities = EstimateLineItem::query()->whereIn('id', array_column($component->get('generatedItems'), 'id'))
        ->orderBy('id')->pluck('quantity')->map(fn ($q) => (float) $q)->all();
    expect($quantities)->toBe([40.0, 5.0, 10.0])
        ->and(array_map(fn ($item) => (float) $item['quantity'], $component->get('generatedItems')))->toBe([40.0, 5.0, 10.0]);
});

/**
 * Removing a line shifts every later position (2026-09-30, estimate 283 on
 * production: a quantity typed into one row saved to, and totalled, the next
 * line). Each row's key carries its position so shifted rows are rebuilt,
 * and the remove button's line id wins over a stale position.
 */
it('rebuilds shifted rows after a removal and removes by line id', function () {
    $fx = claudeEstimateFixture();
    $c = $fx['catalog'];
    $fx['user']->vendors()->attach($fx['vendor']->id, ['role_id' => 1, 'is_employed' => 1]);
    $fx['user']->refresh();

    app()->instance(EstimateAIService::class, new FakeClaudeEstimateService([
        'text' => json_encode(['reasoning' => 'Hall bath.', 'line_items' => [
            ['line_item_id' => $c['Floor Tile']->id, 'quantity' => 40],
            ['line_item_id' => $c['New GFCI Location']->id, 'quantity' => 2],
            ['line_item_id' => $c['Cement Boards']->id, 'quantity' => 10],
        ]]),
        'stop_reason' => 'end_turn',
    ]));

    $component = Livewire::test(EstimateAIGenerator::class, ['estimate' => $fx['estimate']])
        ->set('sectionId', $fx['section']->id)
        ->set('inquiry', 'Hall bath: new tile floor and two outlets.');

    capturingLivewireStream(fn () => $component->call('generate'));

    [$tile, $gfci, $boards] = array_map('intval', array_column($component->get('generatedItems'), 'id'));

    $component->call('removeItem', 0, $tile);

    preg_match_all('/wire:key="(draft-line-[^"]+)"/', $component->html(), $keys);
    expect($keys[1])->toBe(["draft-line-{$gfci}-at-0", "draft-line-{$boards}-at-1"]);

    $component->call('removeItem', 1, $gfci);

    expect(array_map('intval', array_column($component->get('generatedItems'), 'id')))->toBe([$boards])
        ->and(EstimateLineItem::query()->whereKey($boards)->exists())->toBeTrue()
        ->and(EstimateLineItem::query()->whereKey($gfci)->exists())->toBeFalse();
});

/**
 * A browser that sends the whole list at once (2026-10-01 on production:
 * three 500s, "Argument #2 ($key) must be of type string, null given")
 * saves nothing and gets the rows back as the estimate has them.
 */
it('treats a whole-list update as a reload, never a save', function () {
    $fx = claudeEstimateFixture();
    $c = $fx['catalog'];
    $fx['user']->vendors()->attach($fx['vendor']->id, ['role_id' => 1, 'is_employed' => 1]);
    $fx['user']->refresh();

    app()->instance(EstimateAIService::class, new FakeClaudeEstimateService([
        'text' => json_encode(['reasoning' => 'Hall bath.', 'line_items' => [
            ['line_item_id' => $c['Floor Tile']->id, 'quantity' => 40],
            ['line_item_id' => $c['New GFCI Location']->id, 'quantity' => 2],
        ]]),
        'stop_reason' => 'end_turn',
    ]));

    $component = Livewire::test(EstimateAIGenerator::class, ['estimate' => $fx['estimate']])
        ->set('sectionId', $fx['section']->id)
        ->set('inquiry', 'Hall bath: new tile floor and two outlets.');

    capturingLivewireStream(fn () => $component->call('generate'));

    $sent = array_map(fn (array $row) => ['quantity' => 999] + $row, $component->get('generatedItems'));

    $component->set('generatedItems', $sent)->assertOk();

    expect(array_map(fn (array $item) => (float) $item['quantity'], $component->get('generatedItems')))->toBe([40.0, 2.0])
        ->and(EstimateLineItem::query()->whereIn('id', array_column($sent, 'id'))->orderBy('id')->pluck('quantity')->map(fn ($q) => (float) $q)->all())->toBe([40.0, 2.0]);
});
