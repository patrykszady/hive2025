<?php

/**
 * EstimateAIGenerator resolved sectionId (a client-writable dropdown value)
 * and generatedItems[*].id (a client-controlled array) straight off
 * EstimateSection::findOrFail()/EstimateLineItem::find() with no tenant
 * check, and draftId (a private bookkeeping id) was a plain public property
 * an attacker could point at another tenant's EstimateAiDraft row.
 */

use App\Livewire\Estimates\EstimateAIGenerator;
use App\Models\EstimateAiDraft;
use App\Models\EstimateLineItem;
use App\Models\LineItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

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

it('draftId cannot be written directly by the client', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);
    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    expect(fn () => Livewire::test(EstimateAIGenerator::class, ['estimate' => $estimate])->set('draftId', 999))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('refuses generate() when sectionId points at another tenant\'s section', function () {
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
    $component->sectionId = $foreignSection->id;

    expect(fn () => $component->generate())->toThrow(ModelNotFoundException::class);
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

    // Legitimate: the same update against my own line item works.
    $componentB = Livewire::test(EstimateAIGenerator::class, ['estimate' => $estimate]);
    $componentB->instance()->generatedItems = [sec3_generatedItemRow($myLine)];
    $componentB->instance()->updatedGeneratedItems(9, '0.quantity');

    expect((float) $myLine->fresh()->quantity)->toEqual(9.0);
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

    $componentB = Livewire::test(EstimateAIGenerator::class, ['estimate' => $estimate]);
    $componentB->instance()->generatedItems = [sec3_generatedItemRow($myLine)];
    $componentB->instance()->removeItem(0);

    expect(EstimateLineItem::withTrashed()->find($myLine->id))->toBeNull();
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

    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    // draftId can only ever be set server-side (create/recordDraft or via
    // reset()), never by the client, but a raw PHP assignment stands in for
    // "somehow it pointed at another tenant's draft" to exercise the query
    // scoping in discardDraft()/finish() themselves.
    $component = Livewire::test(EstimateAIGenerator::class, ['estimate' => $estimate])
        ->set('sectionId', $section->id);
    $component->instance()->draftId = $foreignDraft->id;

    $component->call('discardDraft');
    expect($foreignDraft->fresh()->status)->toBe(EstimateAiDraft::DRAFTED);

    $component->instance()->draftId = $foreignDraft->id;
    $component->call('finish');
    expect($foreignDraft->fresh()->status)->toBe(EstimateAiDraft::DRAFTED);
});
