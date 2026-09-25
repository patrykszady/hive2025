<?php

/**
 * LineItem carries no tenant scope of its own (belongs_to_vendor_id is set,
 * but nothing filters by it). LineItemCreate::line_items() (the catalog
 * search) and LineItemsIndex::line_items() (the catalog page) listed every
 * tenant's line items, and editItem(LineItem $line_item) — an unscoped
 * route/event-bound model — let an admin load and overwrite another
 * tenant's catalog item.
 */

use App\Livewire\Forms\LineItemForm;
use App\Livewire\LineItems\LineItemCreate;
use App\Livewire\LineItems\LineItemsIndex;
use App\Models\LineItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/t3-fixtures.php';

it('only lists an Admin\'s own catalog in the create-form search and the index, never another tenant\'s', function () {
    $vendor = sec3_makeVendor();
    $mine = sec3_makeCatalogItem($vendor, ['name' => 'Sec3 Demo Bathroom']);

    $vendorB = sec3_makeVendor('Sec3 LineB');
    $foreign = sec3_makeCatalogItem($vendorB, ['name' => 'Sec3 Demo Bathroom']); // same name, different tenant

    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    $searchResults = Livewire::test(LineItemCreate::class)
        ->set('form.name', 'Sec3 Demo Bathroom')
        ->instance()
        ->line_items();

    expect($searchResults->pluck('id')->all())->toBe([$mine->id]);

    $indexResults = Livewire::test(LineItemsIndex::class)->instance()->line_items();
    expect($indexResults->pluck('id')->all())->toContain($mine->id)->not->toContain($foreign->id);
});

it('refuses an Admin editing another tenant\'s catalog line item', function () {
    $vendor = sec3_makeVendor();
    $vendorB = sec3_makeVendor('Sec3 LineC');
    $foreign = sec3_makeCatalogItem($vendorB, ['name' => 'Foreign Item']);

    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    Livewire::test(LineItemCreate::class)
        ->call('editItem', $foreign)
        ->assertNotFound();
});

it('lets an Admin edit their own catalog line item', function () {
    $vendor = sec3_makeVendor();
    $mine = sec3_makeCatalogItem($vendor, ['name' => 'Mine']);
    $admin = sec3_makeAdmin($vendor);
    test()->actingAs($admin);

    Livewire::test(LineItemCreate::class)
        ->call('editItem', $mine)
        ->set('form.desc', 'Updated description here')
        ->call('edit')
        ->assertHasNoErrors();

    expect($mine->fresh()->desc)->toBe('Updated description here');
});
