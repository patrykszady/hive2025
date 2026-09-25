<?php

/**
 * EstimateLineItemCreate::line_items() (the catalog dropdown) listed every
 * tenant's line items. updateGlobalLineItem() resolved the client-writable
 * line_item_id straight off LineItem::findOrFail() with no tenant check, and
 * removeFromEstimate() had no authorize() call, so a homeowner viewing their
 * own estimate could delete a line item from it.
 */

use App\Livewire\LineItems\EstimateLineItemCreate;
use App\Models\EstimateLineItem;
use App\Models\LineItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/t3-fixtures.php';

it('only lists an Admin\'s own catalog in the estimate line-item dropdown, never another tenant\'s', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);
    $mine = sec3_makeCatalogItem($vendor);

    $vendorB = sec3_makeVendor('Sec3 ELineB');
    $foreign = sec3_makeCatalogItem($vendorB);

    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    $items = Livewire::test(EstimateLineItemCreate::class, ['estimate' => $estimate])->instance()->line_items();

    expect($items->keys()->all())->toContain($mine->id)->not->toContain($foreign->id);
});

it('refuses updateGlobalLineItem() touching another tenant\'s catalog item', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);
    $section = sec3_makeSection($estimate);
    $line = sec3_makeEstimateLineItem($estimate, $section, ['desc' => 'A valid description']);

    $vendorB = sec3_makeVendor('Sec3 ELineC');
    $foreignCatalogItem = sec3_makeCatalogItem($vendorB, ['desc' => 'Original foreign desc']);

    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    $component = Livewire::test(EstimateLineItemCreate::class, ['estimate' => $estimate])
        ->call('editOnEstimate', $line->id);
    // The dropdown normally can't select it (previous test), but the
    // underlying property is still a plain client-writable value.
    $component->set('line_item_id', $foreignCatalogItem->id);

    expect(fn () => $component->instance()->updateGlobalLineItem())->toThrow(ModelNotFoundException::class);
    expect($foreignCatalogItem->fresh()->desc)->toBe('Original foreign desc');
});

it('refuses a homeowner removing a line item from their own visible estimate, but lets an Admin', function () {
    $vendor = sec3_makeVendor();
    $client = sec3_makeClient($vendor);
    $project = sec3_makeProject($vendor, $client);
    $estimate = sec3_makeEstimate($project, $vendor);
    $section = sec3_makeSection($estimate);
    $line = sec3_makeEstimateLineItem($estimate, $section);
    $homeowner = sec3_makeClientUser($client);
    test()->actingAs($homeowner);

    Livewire::test(EstimateLineItemCreate::class, ['estimate' => $estimate])
        ->call('editOnEstimate', $line->id)
        ->call('removeFromEstimate')
        ->assertForbidden();

    expect(EstimateLineItem::withTrashed()->findOrFail($line->id)->trashed())->toBeFalse();

    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    Livewire::test(EstimateLineItemCreate::class, ['estimate' => $estimate])
        ->call('editOnEstimate', $line->id)
        ->call('removeFromEstimate')
        ->assertHasNoErrors();

    expect(EstimateLineItem::withTrashed()->findOrFail($line->id)->trashed())->toBeTrue();
});
