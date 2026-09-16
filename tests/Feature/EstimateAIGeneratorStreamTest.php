<?php

use App\Livewire\Estimates\EstimateAIGenerator;
use App\Models\EstimateLineItem;
use App\Services\EstimateAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/estimate-ai-fixtures.php';

it('puts every drafted line on the estimate as it streams, and shows the same rows afterwards', function () {
    [$fx, $component] = draftingGenerator();

    $output = capturingLivewireStream(fn () => $component->call('generate'));

    $chunks = collect(explode('}{', $output))->map(fn ($chunk) => json_decode('{'.trim($chunk, '{}').'}', true));
    $rows = $chunks->where('body.name', 'draft-rows')->pluck('body.content')->values();
    $totals = $chunks->where('body.name', 'draft-total')->pluck('body.content')->values();
    $status = $chunks->where('body.name', 'draft-status')->pluck('body.content')->values();

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toContain('Demo Bathroom')->toContain('Demolition/Demo')->toContain('$850.00')
        ->and($rows[1])->toContain('Floor Tile')->toContain('value="40"')->toContain('sq.ft.')->toContain('$954.00')
        // Each row is appended, never replacing what streamed before it.
        ->and($chunks->where('body.name', 'draft-rows')->pluck('body.mode')->unique()->all())->toBe(['default'])
        ->and($totals->all())->toBe(['$850.00', '$1,804.00'])
        ->and($status->last())->toBe('Drafting from your catalog… 2 line items so far');

    // The lines are on the section already — no apply step.
    $lines = draftedLines($fx);
    expect($lines)->toHaveCount(2)
        ->and($lines[0]->name)->toBe('Demo Bathroom')
        ->and((float) $lines[1]->quantity)->toBe(40.0)
        ->and($lines[1]->desc)->toBe('Catalog description of Floor Tile')
        ->and((float) $fx['section']->fresh()->total)->toBe(850.0 + 1073.25 + 460.0 + 850.0 + 954.0);

    // The section remembers what was asked for and how the scope was read.
    expect($fx['section']->fresh()->ai_inquiry)->toBe('Hall bath: new tile floor and shower.')
        ->and($fx['section']->fresh()->ai_scope)->toBe('Hall bath.');

    $component->assertSet('showPreview', true)
        ->assertSet('generatedItems.0.id', $lines[0]->id)
        ->assertSet('generatedItems.1.category', 'Tiles')
        ->assertSet('generatedItems.1.total', 954.0)
        ->assertSee('2 line items drafted into Hall Bath')
        // Clicking a drafted line opens the estimate's own edit modal.
        ->assertSeeHtml("editOnEstimate', { estimate_line_item_id: {$lines[1]->id} }")
        ->assertDontSee('Apply');
});

it('saves a quantity typed into the table to its line, and treats a blank as one', function () {
    [$fx, $component] = draftingGenerator();
    capturingLivewireStream(fn () => $component->call('generate'));

    $component->set('generatedItems.1.quantity', '52.5')->assertOk();
    $tile = draftedLines($fx)[1];
    expect((float) $tile->quantity)->toBe(52.5)
        ->and((float) $tile->total)->toEqualWithDelta(52.5 * 23.85, 0.01);
    $component->assertSet('generatedItems.1.total', (float) $tile->total);

    $component->set('generatedItems.1.quantity', '')->assertOk();
    expect((float) $tile->fresh()->quantity)->toBe(1.0);
});

it('removes a drafted line for good, and a discarded draft leaves the estimate as it was', function () {
    [$fx, $component] = draftingGenerator();
    capturingLivewireStream(fn () => $component->call('generate'));
    $lines = draftedLines($fx);

    $component->call('removeItem', 0)->assertOk();
    expect(EstimateLineItem::withTrashed()->find($lines[0]->id))->toBeNull()
        ->and(EstimateLineItem::query()->find($lines[1]->id))->not->toBeNull();
    $component->assertSet('generatedItems.0.name', 'Floor Tile')->assertCount('generatedItems', 1);

    $component->call('discardDraft')->assertOk()->assertSet('showPreview', false)->assertSet('generatedItems', []);
    expect(EstimateLineItem::withTrashed()->find($lines[1]->id))->toBeNull()
        ->and((float) $fx['section']->fresh()->total)->toBe(850.0 + 1073.25 + 460.0)
        ->and($fx['section']->fresh()->ai_scope)->toBeNull();
});

it('re-reads its rows when the edit modal saves or removes a line', function () {
    [$fx, $component] = draftingGenerator();
    capturingLivewireStream(fn () => $component->call('generate'));
    $lines = draftedLines($fx);

    $lines[1]->update(['quantity' => 12, 'total' => 12 * 23.85]);
    $lines[0]->delete();

    $component->dispatch('estimate-line-item-saved', id: $lines[1]->id)
        ->assertCount('generatedItems', 1)
        ->assertSet('generatedItems.0.name', 'Floor Tile')
        ->assertSet('generatedItems.0.quantity', 12.0);
});

it('finishes by closing up, the lines already being on the estimate', function () {
    [$fx, $component] = draftingGenerator();
    capturingLivewireStream(fn () => $component->call('generate'));

    $component->call('finish')->assertOk()->assertSet('generatedItems', [])->assertSet('showPreview', false);
    expect(draftedLines($fx))->toHaveCount(2);
});

it('keeps what was drafted when the model is cut off, and says so', function () {
    [$fx, $component] = draftingGenerator();
    $fake = app(EstimateAIService::class);
    $fake->reply['stop_reason'] = 'max_tokens';

    capturingLivewireStream(fn () => $component->call('generate'));

    expect(draftedLines($fx))->toHaveCount(2);
    $component->assertSet('showPreview', true)->assertSee('The draft stopped early')->assertSee('cut off');
});
