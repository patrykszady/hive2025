<?php

use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateLineItem;
use App\Models\EstimateSection;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function sortingFixture(): array
{
    $vendor = Vendor::query()->create([
        'business_name' => 'GS Construction',
        'business_type' => 'Sub',
        'business_email' => 'gc@example.test',
        'address' => '123 Main St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);

    $user = User::query()->create([
        'first_name' => 'Sort', 'last_name' => 'Tester',
        'email' => 'sorting-' . Str::random(8) . '@example.test',
        'cell_phone' => '7' . random_int(100000000, 999999999),
        'password' => bcrypt('password'),
    ]);
    $user->forceFill(['primary_vendor_id' => $vendor->id])->saveQuietly();
    test()->actingAs($user);

    $project = Project::query()->create([
        'project_name' => 'Sorting',
        'client_id' => Client::query()->create([
            'business_name' => 'Owner', 'address' => '1 Oak St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
        ])->id,
        'address' => '1 Oak St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601',
    ]);

    $estimate = Estimate::withoutGlobalScopes()->create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
    ]);

    return compact('vendor', 'user', 'project', 'estimate');
}

function makeSection(Estimate $estimate, string $name): EstimateSection
{
    return EstimateSection::create([
        'estimate_id' => $estimate->id,
        'name' => $name,
        'total' => 0,
    ]);
}

function makeItem(Estimate $estimate, EstimateSection $section, string $name): EstimateLineItem
{
    $catalogItem = \App\Models\LineItem::withoutGlobalScopes()->firstOrCreate(
        ['name' => 'Catalog', 'belongs_to_vendor_id' => $estimate->belongs_to_vendor_id],
        ['cost' => 100, 'category' => 'General', 'sub_category' => 'General', 'unit_type' => 'each'],
    );

    return EstimateLineItem::create([
        'estimate_id' => $estimate->id,
        'section_id' => $section->id,
        'line_item_id' => $catalogItem->id,
        'name' => $name,
        'category' => 'General',
        'sub_category' => 'General',
        'unit_type' => 'each',
        'quantity' => 1,
        'cost' => 100,
        'total' => 100,
    ]);
}

function sectionOrder(EstimateSection $section): array
{
    return EstimateLineItem::query()
        ->where('section_id', $section->id)
        ->orderBy('order')
        ->pluck('name')
        ->all();
}

it('lands a dragged line item exactly at the requested index despite estimate-wide order numbers', function () {
    $fx = sortingFixture();
    $this->actingAs($fx['user']);

    // Two sections: the creation hook numbers items across the whole
    // estimate, so section B's items are born with orders 3, 4, 5 — the
    // exact dirty state that made moves land in the wrong place.
    $a = makeSection($fx['estimate'], 'A');
    $b = makeSection($fx['estimate'], 'B');
    foreach (['A1', 'A2', 'A3'] as $name) {
        makeItem($fx['estimate'], $a, $name);
    }
    $items = [];
    foreach (['B1', 'B2', 'B3'] as $name) {
        $items[$name] = makeItem($fx['estimate'], $b, $name);
    }

    expect($items['B3']->order)->toBeGreaterThan(2); // the dirty precondition is real

    // Drag B3 to the top of section B (index 0).
    $items['B3']->move(0);

    expect(sectionOrder($b))->toBe(['B3', 'B1', 'B2'])
        ->and(EstimateLineItem::where('section_id', $b->id)->orderBy('order')->pluck('order')->all())->toBe([0, 1, 2]);
});

it('moves a line item into the middle of another section at the right spot', function () {
    $fx = sortingFixture();
    $this->actingAs($fx['user']);

    $a = makeSection($fx['estimate'], 'A');
    $b = makeSection($fx['estimate'], 'B');
    foreach (['A1', 'A2'] as $name) {
        makeItem($fx['estimate'], $a, $name);
    }
    $items = [];
    foreach (['B1', 'B2', 'B3'] as $name) {
        $items[$name] = makeItem($fx['estimate'], $b, $name);
    }

    // Drag A2 between B1 and B2 (index 1 of section B) — the exact
    // sequence EstimateShow::sort_line_item performs for a cross-section move.
    $a2 = EstimateLineItem::where('section_id', $a->id)->where('name', 'A2')->firstOrFail();
    $a2->displace();
    $a2->section_id = $b->id;
    $a2->save();
    $a2->unsetRelation('section');
    $a2->move(1);

    expect(sectionOrder($b))->toBe(['B1', 'A2', 'B2', 'B3'])
        ->and(sectionOrder($a))->toBe(['A1'])
        ->and(EstimateLineItem::where('section_id', $a->id)->orderBy('order')->pluck('order')->all())->toBe([0]);
});

it('leaves a newer item alone when it already sits high in the section', function () {
    $fx = sortingFixture();
    $this->actingAs($fx['user']);

    $a = makeSection($fx['estimate'], 'A');
    $items = [];
    foreach (['A1', 'A2', 'A3'] as $name) {
        $items[$name] = makeItem($fx['estimate'], $a, $name);
    }

    // A LATER-created item dragged near the top: highest id, low order —
    // exactly estimate 275's "Build Wall". Renumbering by id would fling it
    // back to the end on the next unrelated drag.
    $newest = makeItem($fx['estimate'], $a, 'A4-newest');
    $newest->move(1);
    expect(sectionOrder($a))->toBe(['A1', 'A4-newest', 'A2', 'A3']);

    // Move a DIFFERENT item; the newest one must hold its place.
    $items['A3']->refresh();
    $items['A3']->move(0);

    expect(sectionOrder($a))->toBe(['A3', 'A1', 'A4-newest', 'A2']);
});

it('keeps working after a deletion left a displaced 999999 order behind', function () {
    $fx = sortingFixture();
    $this->actingAs($fx['user']);

    $a = makeSection($fx['estimate'], 'A');
    $items = [];
    foreach (['A1', 'A2', 'A3', 'A4'] as $name) {
        $items[$name] = makeItem($fx['estimate'], $a, $name);
    }

    // Soft-delete A2: the deleting hook displaces it to order 999999, where
    // it stays in the table.
    $items['A2']->delete();

    // Drag A4 (visually index 2 of the three live items) to the top.
    $items['A4']->refresh();
    $items['A4']->move(0);

    expect(sectionOrder($a))->toBe(['A4', 'A1', 'A3']);
});
