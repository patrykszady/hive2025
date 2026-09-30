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
