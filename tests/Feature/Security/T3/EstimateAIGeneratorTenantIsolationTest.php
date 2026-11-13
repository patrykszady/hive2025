<?php

/**
 * EstimateAIGenerator resolved sectionId (a client-writable dropdown value)
 * and generatedItems[*].id (a client-controlled array) straight off
 * EstimateSection::findOrFail()/EstimateLineItem::find() with no tenant
 * check, and draftId (a private bookkeeping id) was a plain public property
 * an attacker could point at another tenant's EstimateAiDraft row.
 *
 * Since the generator drafts whole estimates the same three doors are a
 * section row's section_id (client-held, in sectionRows), generatedItems
 * as before, and runId (the run being followed) in draftId's place.
 */

use App\Livewire\Estimates\EstimateAIGenerator;
use App\Models\EstimateAiDraft;
use App\Models\EstimateAiRun;
use App\Models\EstimateLineItem;
use App\Models\LineItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\HandleComponents;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/t3-fixtures.php';

function sec3_generatedItemRow(EstimateLineItem $line): array
{
    return [
        'id' => $line->id,
        'line_item_id' => $line->line_item_id,
        'name' => $line->name,
        'category' => $line->category,
        'sub_category' => $line->sub_category,
        'quantity' => (float) $line->quantity,
        'unit_type' => $line->unit_type,
        'cost' => (float) $line->cost,
        'total' => (float) $line->total,
    ];
}

/**
 * A finished run of $estimate that drafted exactly these lines — what the
 * review of a draft stands on.
 */
function sec3_reviewedRun(\App\Models\Estimate $estimate, \App\Models\EstimateSection $section, EstimateLineItem ...$lines): EstimateAiRun
{
    $run = EstimateAiRun::create([
        'vendor_id' => $estimate->belongs_to_vendor_id, 'estimate_id' => $estimate->id, 'status' => EstimateAiRun::FINISHED, 'inquiry' => 'Mine',
        'sections' => [['key' => 'S1', 'name' => 'Mine', 'section_id' => $section->id, 'origin' => 'existing', 'draft_id' => null, 'done' => true]],
    ]);

    EstimateAiDraft::create([
        'vendor_id' => $estimate->belongs_to_vendor_id, 'estimate_id' => $estimate->id, 'section_id' => $section->id, 'run_id' => $run->id, 'inquiry' => 'Mine', 'status' => EstimateAiDraft::DRAFTED,
        'drafted_items' => array_map(fn (EstimateLineItem $line) => ['estimate_line_item_id' => $line->id, 'line_item_id' => $line->line_item_id, 'name' => $line->name, 'quantity' => 1, 'unit_type' => 'pieces', 'cost' => 1], $lines),
    ]);

    return $run;
}

it('runId cannot be written directly by the client', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);
    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    expect(fn () => Livewire::test(EstimateAIGenerator::class, ['estimate' => $estimate])->set('runId', 999))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('refuses generate() when a section row points at another tenant\'s section', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);
    sec3_makeSection($estimate);

    $vendorB = sec3_makeVendor('Sec3 GenB');
    $clientB = sec3_makeClient($vendorB);
    $projectB = sec3_makeProject($vendorB, $clientB);
    $estimateB = sec3_makeEstimate($projectB, $vendorB);
    $foreignSection = sec3_makeSection($estimateB);

    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    $component = app(EstimateAIGenerator::class);
    $component->estimate = $estimate;
    $component->mount();
    $component->inquiry = 'A long enough inquiry to pass validation.';
    $component->sectionRows = [['key' => 'section-'.$foreignSection->id, 'kind' => 'existing', 'name' => 'Theirs', 'include' => true, 'section_id' => $foreignSection->id, 'detail' => '']];

    // Nothing is drafted, into their section or anywhere: the draft stops with
    // a message and the list goes back to this estimate's own sections.
    $component->generate();
    expect(EstimateAiRun::count())->toBe(0)
        ->and($component->error)->toContain('no longer on this estimate')
        ->and(collect($component->sectionRows)->pluck('section_id')->all())->not->toContain($foreignSection->id)
        ->and(EstimateLineItem::query()->where('section_id', $foreignSection->id)->count())->toBe(0);
});

it('never reuses or removes another tenant\'s earlier request', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);

    $vendorB = sec3_makeVendor('Sec3 GenF');
    $clientB = sec3_makeClient($vendorB);
    $projectB = sec3_makeProject($vendorB, $clientB);
    $estimateB = sec3_makeEstimate($projectB, $vendorB);
    $sectionB = sec3_makeSection($estimateB);
    $foreignLine = sec3_makeEstimateLineItem($estimateB, $sectionB);
    $foreignRun = EstimateAiRun::create([
        'vendor_id' => $vendorB->id, 'estimate_id' => $estimateB->id, 'status' => EstimateAiRun::FINISHED, 'inquiry' => 'Their private request',
        'plan' => ['listed' => true, 'sections' => [['key' => 'S1', 'name' => 'Their Kitchen', 'section_id' => null, 'on_estimate' => false, 'rooms' => [['name' => 'Kitchen', 'floor_sqft' => 200]]]]],
        'sections' => [['key' => 'S1', 'name' => 'Their Kitchen', 'section_id' => $sectionB->id, 'origin' => 'created', 'draft_id' => null, 'done' => true]],
    ]);
    $foreignDraft = EstimateAiDraft::create([
        'vendor_id' => $vendorB->id, 'estimate_id' => $estimateB->id, 'section_id' => $sectionB->id, 'run_id' => $foreignRun->id, 'inquiry' => 'Their private request', 'status' => EstimateAiDraft::DRAFTED,
        'drafted_items' => [['estimate_line_item_id' => $foreignLine->id, 'line_item_id' => $foreignLine->line_item_id, 'name' => $foreignLine->name, 'quantity' => 1, 'unit_type' => 'pieces', 'cost' => 1]],
    ]);

    test()->actingAs(sec3_makeAdmin($vendor));

    Livewire::test(EstimateAIGenerator::class, ['estimate' => $estimate])
        ->assertDontSee('Their private request')
        ->call('useRequest', $foreignRun->id)
        ->assertSet('inquiry', '')
        ->assertSet('reusedRunId', null)
        ->assertSet('sectionRows', [])
        ->call('removeDraft', $foreignRun->id);

    expect($foreignDraft->fresh()->status)->toBe(EstimateAiDraft::DRAFTED)
        ->and(EstimateLineItem::query()->find($foreignLine->id))->not->toBeNull()
        ->and($sectionB->fresh())->not->toBeNull();

    // reusedRunId, like runId, is not the client's to set.
    expect(fn () => Livewire::test(EstimateAIGenerator::class, ['estimate' => $estimate])->set('reusedRunId', $foreignRun->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('never lets updatedGeneratedItems touch another tenant\'s line item, only its own', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);
    $section = sec3_makeSection($estimate);
    $myLine = sec3_makeEstimateLineItem($estimate, $section, ['quantity' => 1]);

    $vendorB = sec3_makeVendor('Sec3 GenC');
    $clientB = sec3_makeClient($vendorB);
    $projectB = sec3_makeProject($vendorB, $clientB);
    $estimateB = sec3_makeEstimate($projectB, $vendorB);
    $sectionB = sec3_makeSection($estimateB);
    $foreignLine = sec3_makeEstimateLineItem($estimateB, $sectionB, ['quantity' => 1]);

    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    // Attack: generatedItems[0]['id'] is swapped for the foreign line's id.
    // generatedItems is seeded via ->instance() (plain PHP assignment)
    // rather than ->set('generatedItems', ...) — setting the whole array
    // makes Livewire auto-invoke this same hook with just one argument,
    // which this method (by design) does not accept, and is not the call
    // shape a real per-row `wire:model="generatedItems.0.quantity"` update
    // produces anyway. updatedGeneratedItems() is then called directly with
    // the args Livewire itself passes for that update: the new value and the
    // key MINUS the property-name prefix ("0.quantity", not
    // "generatedItems.0.quantity").
    $componentA = Livewire::test(EstimateAIGenerator::class, ['estimate' => $estimate]);
    $componentA->instance()->generatedItems = [sec3_generatedItemRow($foreignLine)];
    $componentA->instance()->updatedGeneratedItems(9, '0.quantity');

    expect((float) $foreignLine->fresh()->quantity)->toEqual(1.0);

    // Nor a line of my own estimate that the draft under review did not make
    // (2026-11-13: any line of the estimate could be named from the review).
    $handMade = sec3_makeEstimateLineItem($estimate, $section, ['quantity' => 3]);
    $componentB = Livewire::test(EstimateAIGenerator::class, ['estimate' => $estimate]);
    $componentB->instance()->runId = sec3_reviewedRun($estimate, $section, $myLine)->id;
    $componentB->instance()->generatedItems = [sec3_generatedItemRow($handMade)];
    $componentB->instance()->updatedGeneratedItems(9, '0.quantity');

    expect((float) $handMade->fresh()->quantity)->toEqual(3.0);

    // Legitimate: the same update against a line the draft made works.
    $componentB->instance()->generatedItems = [sec3_generatedItemRow($myLine)];
    $componentB->instance()->updatedGeneratedItems(9, '0.quantity');

    expect((float) $myLine->fresh()->quantity)->toEqual(9.0)
        ->and((float) $componentB->instance()->generatedItems[0]['quantity'])->toEqual(9.0);
});

it('never lets removeItem force-delete another tenant\'s line item, only its own', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);
    $section = sec3_makeSection($estimate);
    $myLine = sec3_makeEstimateLineItem($estimate, $section);

    $vendorB = sec3_makeVendor('Sec3 GenE');
    $clientB = sec3_makeClient($vendorB);
    $projectB = sec3_makeProject($vendorB, $clientB);
    $estimateB = sec3_makeEstimate($projectB, $vendorB);
    $sectionB = sec3_makeSection($estimateB);
    $foreignLine = sec3_makeEstimateLineItem($estimateB, $sectionB);

    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    // Seeded via ->instance() rather than ->set() — see the note in the
    // updatedGeneratedItems test above.
    $componentA = Livewire::test(EstimateAIGenerator::class, ['estimate' => $estimate]);
    $componentA->instance()->generatedItems = [sec3_generatedItemRow($foreignLine)];
    $componentA->instance()->removeItem(0);

    expect(EstimateLineItem::withTrashed()->find($foreignLine->id))->not->toBeNull();

    // A line of my own estimate the draft did not make is not the review's to delete either.
    $handMade = sec3_makeEstimateLineItem($estimate, $section);
    $componentB = Livewire::test(EstimateAIGenerator::class, ['estimate' => $estimate]);
    $componentB->instance()->runId = sec3_reviewedRun($estimate, $section, $myLine)->id;
    $componentB->instance()->generatedItems = [sec3_generatedItemRow($handMade), sec3_generatedItemRow($myLine)];
    $componentB->instance()->removeItem(0);

    expect(EstimateLineItem::query()->find($handMade->id))->not->toBeNull();

    // The row is gone from the list all the same; the draft's own line, now first, is removed for good.
    $componentB->instance()->removeItem(0);

    expect(EstimateLineItem::withTrashed()->find($myLine->id))->toBeNull()
        ->and(EstimateLineItem::query()->find($handMade->id))->not->toBeNull();
});

it('never lets discardDraft/finish touch another tenant\'s EstimateAiDraft row', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);
    $section = sec3_makeSection($estimate);

    $vendorB = sec3_makeVendor('Sec3 GenD');
    $clientB = sec3_makeClient($vendorB);
    $projectB = sec3_makeProject($vendorB, $clientB);
    $estimateB = sec3_makeEstimate($projectB, $vendorB);
    $sectionB = sec3_makeSection($estimateB);
    $foreignDraft = EstimateAiDraft::create([
        'vendor_id' => $vendorB->id,
        'estimate_id' => $estimateB->id,
        'section_id' => $sectionB->id,
        'inquiry' => 'Foreign tenant inquiry',
        'status' => EstimateAiDraft::DRAFTED,
        'drafted_items' => [],
    ]);

    $foreignRun = EstimateAiRun::create([
        'vendor_id' => $vendorB->id,
        'estimate_id' => $estimateB->id,
        'status' => EstimateAiRun::FINISHED,
        'inquiry' => 'Foreign tenant inquiry',
        'sections' => [['key' => 'S1', 'name' => 'Theirs', 'section_id' => $sectionB->id, 'origin' => 'created', 'draft_id' => $foreignDraft->id, 'done' => true]],
    ]);
    $foreignDraft->update(['run_id' => $foreignRun->id]);

    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    // runId can only ever be set server-side (generate()/openModal() or via
    // reset()), never by the client, so a raw PHP assignment on the live
    // instance stands in for "somehow it pointed at another tenant's run",
    // and the methods are called on that same instance (a fresh request
    // would start again from the snapshot, without the assignment) to
    // exercise the query scoping in discardDraft()/finish()/pollDraft().
    $instance = Livewire::test(EstimateAIGenerator::class, ['estimate' => $estimate])->instance();
    // Toasts and modals speak through the request's current component; outside a request that is this instance.
    HandleComponents::$componentStack[] = $instance;

    $instance->runId = $foreignRun->id;
    $instance->discardDraft();
    expect($foreignDraft->fresh()->status)->toBe(EstimateAiDraft::DRAFTED)
        ->and($sectionB->fresh())->not->toBeNull();

    $instance->runId = $foreignRun->id;
    $instance->finish();
    expect($foreignDraft->fresh()->status)->toBe(EstimateAiDraft::DRAFTED);

    $foreignLine = sec3_makeEstimateLineItem($estimateB, $sectionB);
    $foreignDraft->update(['drafted_items' => [['estimate_line_item_id' => $foreignLine->id, 'line_item_id' => $foreignLine->line_item_id, 'name' => $foreignLine->name, 'quantity' => 1, 'unit_type' => 'pieces', 'cost' => 1]]]);
    $instance->runId = $foreignRun->id;
    $instance->pollDraft();
    expect($instance->generatedItems)->toBe([])->and($instance->showPreview)->toBeFalse();

    // The same calls against my own estimate's run do what they say.
    $myLine = sec3_makeEstimateLineItem($estimate, $section);
    $myRun = EstimateAiRun::create([
        'vendor_id' => $vendor->id, 'estimate_id' => $estimate->id, 'status' => EstimateAiRun::FINISHED, 'inquiry' => 'Mine',
        'sections' => [['key' => 'S1', 'name' => 'Mine', 'section_id' => $section->id, 'origin' => 'existing', 'draft_id' => null, 'done' => true]],
    ]);
    $myDraft = EstimateAiDraft::create([
        'vendor_id' => $vendor->id, 'estimate_id' => $estimate->id, 'section_id' => $section->id, 'run_id' => $myRun->id, 'inquiry' => 'Mine', 'status' => EstimateAiDraft::DRAFTED,
        'drafted_items' => [['estimate_line_item_id' => $myLine->id, 'line_item_id' => $myLine->line_item_id, 'name' => $myLine->name, 'quantity' => 1, 'unit_type' => 'pieces', 'cost' => 1]],
    ]);

    $instance->runId = $myRun->id;
    $instance->pollDraft();
    expect(array_column($instance->generatedItems, 'id'))->toBe([$myLine->id]);

    $instance->discardDraft();
    expect($myDraft->fresh()->status)->toBe(EstimateAiDraft::DISCARDED)
        ->and(EstimateLineItem::withTrashed()->find($myLine->id))->toBeNull()
        ->and(EstimateLineItem::query()->find($foreignLine->id))->not->toBeNull();

    HandleComponents::$componentStack = [];
});
