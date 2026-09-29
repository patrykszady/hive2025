<?php

use App\Livewire\Estimates\EstimateShow;
use App\Models\EstimateLineItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/estimate-ai-fixtures.php';

/**
 * The AI Generate modal, the line item editor and the email composer are
 * children of the estimate page. Their automatic keys picked up the last key
 * of the line-item loop, so a change to the lines re-keyed them and the page's
 * refresh re-mounted them: the AI draft modal closed and lost its rows the
 * moment the draft landed or a quantity was typed.
 */
it('keeps the estimate page children on the same keys when the lines change', function () {
    $fx = claudeEstimateFixture();
    $fx['user']->vendors()->attach($fx['vendor']->id, ['role_id' => 1, 'is_employed' => 1]);
    $fx['user']->refresh();
    $project = $fx['estimate']->project()->withoutGlobalScopes()->first();
    $project->client()->withoutGlobalScopes()->first()->vendors()->attach($fx['vendor']->id);
    $project->vendors()->attach($fx['vendor']->id, ['client_id' => $project->client_id]);

    $page = Livewire::test(EstimateShow::class, ['estimate' => $fx['estimate']]);
    $before = $page->snapshot['memo']['children'];

    expect($before)->toHaveKeys([
        "estimate-line-item-create-{$fx['estimate']->id}",
        "estimate-ai-generator-{$fx['estimate']->id}",
        "estimate-email-{$fx['estimate']->id}",
    ]);

    // A drafted line lands on the section, then the generator asks the page to refresh.
    $tile = $fx['catalog']['Floor Tile'];
    EstimateLineItem::create([
        'estimate_id' => $fx['estimate']->id, 'section_id' => $fx['section']->id, 'line_item_id' => $tile->id,
        'name' => $tile->name, 'category' => $tile->category, 'sub_category' => $tile->sub_category,
        'unit_type' => $tile->unit_type, 'quantity' => 12, 'cost' => $tile->cost, 'total' => 12 * $tile->cost,
    ]);

    // Re-rendered with the new line (the test harness cannot re-render
    // children in place, so a fresh render stands in for the refresh).
    $after = Livewire::test(EstimateShow::class, ['estimate' => $fx['estimate']->fresh()])->snapshot['memo']['children'];

    foreach (['estimate-line-item-create', 'estimate-ai-generator', 'estimate-email'] as $child) {
        expect($after)->toHaveKey("{$child}-{$fx['estimate']->id}");
    }
    expect(array_keys($after))->toBe(array_keys($before));
});
