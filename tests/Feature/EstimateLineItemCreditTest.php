<?php

use App\Livewire\LineItems\EstimateLineItemCreate;
use App\Models\Bid;
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

function makeCreditScenario(bool $signed = true): array
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
        'email' => 'vendor-signer-'.Str::random(8).'@example.test',
        'cell_phone' => '7'.random_int(100000000, 999999999),
        'password' => bcrypt('password'),
        'remember_token' => Str::random(10),
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($vendorSigner->id, ['is_employed' => true, 'role_id' => 1]);

    $clientSigner = User::query()->create([
        'first_name' => 'Client',
        'last_name' => 'Signer',
        'email' => 'client-signer-'.Str::random(8).'@example.test',
        'cell_phone' => '7'.random_int(100000000, 999999999),
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
        'name' => 'Structural Header',
        'category' => 'Framing',
        'sub_category' => 'Rough',
        'unit_type' => 'no_unit',
        'cost' => 2850,
        'belongs_to_vendor_id' => $vendor->id,
    ]);

    $estimateLineItem = EstimateLineItem::query()->create([
        'estimate_id' => $estimate->id,
        'line_item_id' => $globalLineItem->id,
        'section_id' => $section->id,
        'order' => 0,
        'name' => 'Structural Header',
        'category' => 'Framing',
        'sub_category' => 'Rough',
        'unit_type' => 'no_unit',
        'quantity' => 1,
        'cost' => 2850,
        'total' => 2850,
    ]);

    if ($signed) {
        $signedAt = now();
        foreach ([[$vendorSigner, 'vendor'], [$clientSigner, 'client']] as $i => [$signer, $kind]) {
            EstimateSignature::query()->create([
                'estimate_id' => $estimate->id,
                'user_id' => $signer->id,
                'signer_name' => $signer->first_name.' '.$signer->last_name,
                'signer_email' => $signer->email,
                'signer_phone' => '+1224333444'.$i,
                'signature_data' => $kind.'-signature',
                'signature_type' => 'draw',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
                'document_hash' => 'hash-'.$kind,
                'signed_at' => $signedAt->copy()->addMinutes($i),
            ]);
        }
    }

    return compact('vendor', 'vendorSigner', 'project', 'estimate', 'section', 'estimateLineItem');
}

it('creates a negative credit line item in a new change-order section', function () {
    $s = makeCreditScenario();

    // Change orders happen after signing — same-second created_at would
    // read as locked (isLocked uses <=).
    $this->travel(1)->hour();

    expect($s['section']->fresh()->isLocked())->toBeTrue();

    Livewire::test(EstimateLineItemCreate::class, ['estimate' => $s['estimate']->fresh()])
        ->call('editOnEstimate', $s['estimateLineItem']->id)
        ->call('creditToChangeOrder');

    $creditSection = EstimateSection::query()
        ->where('estimate_id', $s['estimate']->id)
        ->where('id', '!=', $s['section']->id)
        ->first();

    expect($creditSection)->not->toBeNull()
        ->and($creditSection->name)->toBe('Change Order')
        ->and($creditSection->isLocked())->toBeFalse()
        ->and((int) $creditSection->bid->type)->toBeGreaterThanOrEqual(2);

    $credit = $creditSection->estimate_line_items()->first();

    expect($credit->name)->toBe('Credit: Structural Header')
        ->and((float) $credit->cost)->toBe(-2850.0)
        ->and((float) $credit->total)->toBe(-2850.0)
        ->and($credit->category)->toBe('Framing')
        ->and($credit->desc)->toBe('Credit for Line Item #1.1: Structural Header')
        ->and($credit->notes)->toBeNull()
        // observer cascade: section total and change-order bid amount go negative
        ->and((float) $creditSection->fresh()->total)->toBe(-2850.0)
        ->and((float) $creditSection->bid->fresh()->amount)->toBe(-2850.0)
        // the original signed line item is untouched
        ->and((float) $s['estimateLineItem']->fresh()->total)->toBe(2850.0);
});

it('reuses an existing unlocked change-order section for subsequent credits', function () {
    $s = makeCreditScenario();
    $this->travel(1)->hour();

    $component = Livewire::test(EstimateLineItemCreate::class, ['estimate' => $s['estimate']->fresh()])
        ->call('editOnEstimate', $s['estimateLineItem']->id)
        ->call('creditToChangeOrder');

    $component
        ->call('editOnEstimate', $s['estimateLineItem']->id)
        ->call('creditToChangeOrder');

    $sections = EstimateSection::query()
        ->where('estimate_id', $s['estimate']->id)
        ->where('id', '!=', $s['section']->id)
        ->get();

    expect($sections)->toHaveCount(1)
        ->and($sections->first()->estimate_line_items()->count())->toBe(2)
        ->and(Bid::where('project_id', $s['project']->id)->where('type', '>=', 2)->count())->toBe(1);
});

it('does nothing on unsigned estimates — credits only exist for locked contract items', function () {
    $s = makeCreditScenario(signed: false);

    Livewire::test(EstimateLineItemCreate::class, ['estimate' => $s['estimate']->fresh()])
        ->call('editOnEstimate', $s['estimateLineItem']->id)
        ->call('creditToChangeOrder')
        ->assertDontSee('Credit</flux:button>');

    expect(EstimateLineItem::query()
        ->where('estimate_id', $s['estimate']->id)
        ->where('id', '!=', $s['estimateLineItem']->id)
        ->count())->toBe(0);
});

it('does not offer credit for items already in a change-order section', function () {
    $s = makeCreditScenario();
    $this->travel(1)->hour();

    // First credit creates the change-order section + credit item.
    Livewire::test(EstimateLineItemCreate::class, ['estimate' => $s['estimate']->fresh()])
        ->call('editOnEstimate', $s['estimateLineItem']->id)
        ->call('creditToChangeOrder');

    $creditItem = EstimateLineItem::query()
        ->where('estimate_id', $s['estimate']->id)
        ->where('id', '!=', $s['estimateLineItem']->id)
        ->firstOrFail();

    // Crediting the credit (an unlocked change-order item) is refused.
    Livewire::test(EstimateLineItemCreate::class, ['estimate' => $s['estimate']->fresh()])
        ->call('editOnEstimate', $creditItem->id)
        ->call('creditToChangeOrder');

    expect(EstimateLineItem::query()->where('estimate_id', $s['estimate']->id)->count())->toBe(2);
});
