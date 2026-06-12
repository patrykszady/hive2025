<?php

use App\Livewire\Estimates\EstimateShow;
use App\Livewire\LineItems\EstimateLineItemCreate;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateLineItem;
use App\Models\EstimateSection;
use App\Models\EstimateSignature;
use App\Models\LineItem;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeLockingScenario(): array
{
    $vendor = Vendor::query()->create([
        'business_name' => 'Hive GC',
        'business_type' => 'Sub',
        'address' => '123 Main St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
    ]);

    $vendorSigner = User::query()->create([
        'first_name' => 'Vendor',
        'last_name' => 'Signer',
        'email' => 'vendor-signer-' . Str::random(8) . '@example.test',
        'cell_phone' => '7' . random_int(100000000, 999999999),
        'password' => bcrypt('password'),
        'remember_token' => Str::random(10),
        'primary_vendor_id' => $vendor->id,
    ]);

    $vendor->users()->attach($vendorSigner->id, [
        'is_employed' => true,
        'role_id' => 1,
    ]);

    $clientSigner = User::query()->create([
        'first_name' => 'Client',
        'last_name' => 'Signer',
        'email' => 'client-signer-' . Str::random(8) . '@example.test',
        'cell_phone' => '7' . random_int(100000000, 999999999),
        'password' => bcrypt('password'),
        'remember_token' => Str::random(10),
    ]);

    $client = Client::query()->create([
        'business_name' => 'Client Household',
        'address' => '456 Oak St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
    ]);

    $client->vendors()->attach($vendor->id, ['source' => 'test']);
    $client->users()->attach($clientSigner->id);

    test()->actingAs($vendorSigner);

    $project = Project::query()->create([
        'project_name' => 'Kitchen Remodel',
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '456 Oak St',
        'city' => 'Chicago',
        'state' => 'IL',
        'zip_code' => '60601',
    ]);

    $estimate = Estimate::withoutGlobalScopes()->create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'options' => [],
    ]);

    $section = EstimateSection::query()->create([
        'estimate_id' => $estimate->id,
        'name' => 'Main',
        'order' => 0,
        'total' => 0,
    ]);

    $globalLineItem = LineItem::query()->create([
        'name' => 'Labor',
        'category' => 'Labor',
        'sub_category' => 'General',
        'unit_type' => 'hour',
        'cost' => 100,
        'belongs_to_vendor_id' => $vendor->id,
    ]);

    return compact('vendor', 'vendorSigner', 'clientSigner', 'client', 'project', 'estimate', 'section', 'globalLineItem');
}

it('shows hide action for locked line items on fully signed estimates', function () {
    $s = makeLockingScenario();

    $estimateLineItem = EstimateLineItem::query()->create([
        'estimate_id' => $s['estimate']->id,
        'line_item_id' => $s['globalLineItem']->id,
        'section_id' => $s['section']->id,
        'order' => 0,
        'name' => 'Labor',
        'category' => 'Labor',
        'sub_category' => 'General',
        'unit_type' => 'hour',
        'quantity' => 2,
        'cost' => 100,
        'total' => 200,
    ]);

    $s['section']->update(['total' => 200]);
    $signedAt = now();

    EstimateSignature::query()->create([
        'estimate_id' => $s['estimate']->id,
        'user_id' => $s['vendorSigner']->id,
        'signer_name' => 'Vendor Signer',
        'signer_email' => $s['vendorSigner']->email,
        'signer_phone' => '+12243334444',
        'signature_data' => 'vendor-signature',
        'signature_type' => 'draw',
        'ip_address' => '127.0.0.1',
        'user_agent' => 'PHPUnit',
        'document_hash' => 'hash-vendor',
        'signed_at' => $signedAt,
    ]);

    EstimateSignature::query()->create([
        'estimate_id' => $s['estimate']->id,
        'user_id' => $s['clientSigner']->id,
        'signer_name' => 'Client Signer',
        'signer_email' => $s['clientSigner']->email,
        'signer_phone' => '+12243335555',
        'signature_data' => 'client-signature',
        'signature_type' => 'draw',
        'ip_address' => '127.0.0.1',
        'user_agent' => 'PHPUnit',
        'document_hash' => 'hash-client',
        'signed_at' => $signedAt->copy()->addMinute(),
    ]);

    $s['section']->refresh();
    expect($s['section']->isLocked())->toBeTrue();

    Livewire::test(EstimateLineItemCreate::class, ['estimate' => $s['estimate']->fresh()])
        ->call('editOnEstimate', $estimateLineItem->id)
        ->assertSee('Hide')
        ->assertDontSee('Edit Item');
});

it('restores a hidden line item to its original order position', function () {
    $s = makeLockingScenario();

    // Three items: 0=Foundation, 1=Structural Header, 2=Framing
    $item0 = EstimateLineItem::query()->create([
        'estimate_id' => $s['estimate']->id,
        'line_item_id' => $s['globalLineItem']->id,
        'section_id' => $s['section']->id,
        'order' => 0,
        'name' => 'Foundation',
        'category' => 'Structural',
        'sub_category' => '',
        'unit_type' => 'no_unit',
        'quantity' => 1,
        'cost' => 500,
        'total' => 500,
    ]);

    $item1 = EstimateLineItem::query()->create([
        'estimate_id' => $s['estimate']->id,
        'line_item_id' => $s['globalLineItem']->id,
        'section_id' => $s['section']->id,
        'order' => 1,
        'name' => 'Structural Header',
        'category' => 'Structural',
        'sub_category' => '',
        'unit_type' => 'no_unit',
        'quantity' => 1,
        'cost' => 300,
        'total' => 300,
    ]);

    $item2 = EstimateLineItem::query()->create([
        'estimate_id' => $s['estimate']->id,
        'line_item_id' => $s['globalLineItem']->id,
        'section_id' => $s['section']->id,
        'order' => 2,
        'name' => 'Framing',
        'category' => 'Structural',
        'sub_category' => '',
        'unit_type' => 'no_unit',
        'quantity' => 1,
        'cost' => 200,
        'total' => 200,
    ]);

    $s['section']->update(['total' => 1000]);

    // Hide Structural Header — displace() logs original order=1 to the activity log
    $item1->delete();

    expect(EstimateLineItem::onlyTrashed()->find($item1->id))->not->toBeNull();

    $activity = \Spatie\Activitylog\Models\Activity::query()
        ->where('subject_type', EstimateLineItem::class)
        ->where('subject_id', $item1->id)
        ->where('event', 'deleted')
        ->first();

    expect($activity)->not->toBeNull()
        ->and((int) ($activity->properties['old']['order'] ?? null))->toBe(1);

    // Restore via the component method directly (avoids full view render)
    \Flux::shouldReceive('toast')->andReturnNull();

    $component = app(EstimateShow::class);
    $component->estimate = $s['estimate']->fresh();
    $component->lineItemRestore($item1->id);

    // Structural Header should be back at order=1
    $restored = EstimateLineItem::find($item1->id);
    expect($restored)->not->toBeNull()
        ->and($restored->order)->toBe(1);

    // Foundation and Framing stay at their original positions
    expect(EstimateLineItem::find($item0->id)->order)->toBe(0);
    expect(EstimateLineItem::find($item2->id)->order)->toBe(2);
});
