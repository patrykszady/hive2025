<?php

use App\Livewire\Estimates\EstimateMarkup;
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
use App\Services\Estimates\EstimateMarkupApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Mark up every line item of an estimate section — or the whole estimate —
 * at once (2026-11-03, estimate 293), by "about" the percentage: whole-dollar
 * prices stay whole, others land on a quarter. And revert it.
 */

function markupScenario(): array
{
    $vendor = Vendor::query()->create(['business_name' => 'Hive GC', 'business_type' => 'Sub', 'address' => '123 Main St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601']);
    $admin = User::query()->create([
        'first_name' => 'Vendor', 'last_name' => 'Admin', 'email' => 'markup-'.Str::random(8).'@example.test',
        'cell_phone' => '7'.random_int(100000000, 999999999), 'password' => bcrypt('password'), 'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($admin->id, ['is_employed' => true, 'role_id' => 1]);
    $client = Client::query()->create(['business_name' => 'Client Household', 'address' => '456 Oak St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601']);
    $client->vendors()->attach($vendor->id, ['source' => 'test']);
    test()->actingAs($admin);

    $project = Project::query()->create(['project_name' => 'Kitchen', 'client_id' => $client->id, 'belongs_to_vendor_id' => $vendor->id, 'address' => '456 Oak St', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60601']);
    $estimate = Estimate::withoutGlobalScopes()->create(['project_id' => $project->id, 'belongs_to_vendor_id' => $vendor->id, 'options' => []]);
    $catalog = LineItem::query()->create(['name' => 'Demo', 'category' => 'Demolition', 'unit_type' => 'no_unit', 'cost' => 100, 'belongs_to_vendor_id' => $vendor->id]);

    $kitchen = EstimateSection::query()->create(['estimate_id' => $estimate->id, 'name' => 'Kitchen Area', 'order' => 0, 'total' => 0]);
    $bath = EstimateSection::query()->create(['estimate_id' => $estimate->id, 'name' => 'Bath', 'order' => 1, 'total' => 0]);
    $line = fn (EstimateSection $section, string $name, float $quantity, float $cost, ?int $creditFor = null) => EstimateLineItem::query()->create([
        'estimate_id' => $estimate->id, 'line_item_id' => $catalog->id, 'section_id' => $section->id, 'credit_for_id' => $creditFor,
        'order' => 0, 'name' => $name, 'category' => 'Demolition', 'unit_type' => 'no_unit', 'quantity' => $quantity, 'cost' => $cost, 'total' => round($quantity * $cost, 2),
    ]);

    $demo = $line($kitchen, 'Demo', 1, 2150.00);
    $flooring = $line($kitchen, 'Move Furniture', 175, 0.75);
    $tile = $line($bath, 'Tile', 3, 333.33);
    $credit = $line($bath, 'Credit: Tile', 1, -333.33, $tile->id);
    foreach ([$kitchen, $bath] as $section) {
        $section->update(['total' => $section->estimate_line_items()->sum('total')]);
    }

    return compact('vendor', 'admin', 'client', 'estimate', 'kitchen', 'bath', 'demo', 'flooring', 'tile', 'credit');
}

it('marks every line item up by about the percentage: whole prices stay whole, others land on a quarter', function () {
    $s = markupScenario();

    $result = app(EstimateMarkupApplier::class)->apply($s['estimate'], 10, $s['admin']->id);

    // 2150 is whole: 2365 exactly.
    expect((float) $s['demo']->fresh()->cost)->toBe(2365.0)
        ->and((float) $s['demo']->fresh()->total)->toBe(2365.0)
        // 0.75 × 1.10 = 0.825 → the nearest quarter is 0.75.
        ->and((float) $s['flooring']->fresh()->cost)->toBe(0.75)
        ->and((float) $s['flooring']->fresh()->total)->toBe(131.25)
        // 333.33 × 1.10 = 366.663 → 366.75, × 3
        ->and((float) $s['tile']->fresh()->cost)->toBe(366.75)
        ->and((float) $s['tile']->fresh()->total)->toBe(1100.25)
        // A credit rounds the same way, so it still cancels its line unit for unit.
        ->and((float) $s['credit']->fresh()->cost)->toBe(-366.75)
        ->and((float) $s['kitchen']->fresh()->total)->toBe(2496.25)
        ->and((float) $s['bath']->fresh()->total)->toBe(733.5)
        ->and($result)->toBe(['before' => 2947.91, 'after' => 3229.75, 'lines' => 4]);

    $log = $s['estimate']->fresh()->options['markups'][0];
    expect($log['percent'])->toEqual(10)->and($log['by_user_id'])->toBe($s['admin']->id)->and($log['after'])->toEqual(3229.75)
        ->and($log['lines'][(string) $s['tile']->id])->toEqual(['cost_before' => 333.33, 'total_before' => 999.99, 'cost_after' => 366.75, 'total_after' => 1100.25]);
});

it('rounds each price on its own: whole to the dollar, cents to the quarter', function () {
    expect(EstimateMarkupApplier::markedUp(100, 1.10))->toBe(110.0)
        ->and(EstimateMarkupApplier::markedUp(115, 1.10))->toBe(127.0)
        ->and(EstimateMarkupApplier::markedUp(12.40, 1.10))->toBe(13.75)
        ->and(EstimateMarkupApplier::markedUp(-333.33, 1.10))->toBe(-366.75)
        ->and(EstimateMarkupApplier::markedUp(2150, 0.95))->toBe(2043.0);
});

it('reverts the newest markup, leaving a line edited since as it is', function () {
    $s = markupScenario();
    $applier = app(EstimateMarkupApplier::class);
    $applier->apply($s['estimate'], 10, $s['admin']->id);
    // Someone re-priced Demo by hand after the markup.
    $s['demo']->fresh()->update(['cost' => 2400, 'total' => 2400]);

    $result = $applier->revert($s['estimate'], $s['admin']->id);

    expect($result)->toBe(['restored' => 3, 'skipped' => 1, 'percent' => 10.0])
        ->and((float) $s['demo']->fresh()->cost)->toBe(2400.0)
        ->and((float) $s['tile']->fresh()->cost)->toBe(333.33)
        ->and((float) $s['tile']->fresh()->total)->toBe(999.99)
        ->and((float) $s['credit']->fresh()->cost)->toBe(-333.33)
        ->and((float) $s['bath']->fresh()->total)->toBe(666.66)
        ->and((float) $s['kitchen']->fresh()->total)->toBe(2531.25)
        ->and($s['estimate']->fresh()->options['markups'][0]['reverted_at'])->not->toBeNull()
        ->and($applier->latestRevertible($s['estimate']->fresh()))->toBeNull();

    expect(fn () => $applier->revert($s['estimate'], $s['admin']->id))->toThrow(ValidationException::class);
});

it('unwinds stacked markups newest first', function () {
    $s = markupScenario();
    $applier = app(EstimateMarkupApplier::class);
    $applier->apply($s['estimate'], 10, $s['admin']->id, $s['kitchen']->id);
    $applier->apply($s['estimate'], 20, $s['admin']->id);

    expect($applier->latestRevertible($s['estimate']->fresh())['percent'])->toEqual(20);
    $applier->revert($s['estimate'], $s['admin']->id);
    expect((float) $s['demo']->fresh()->cost)->toBe(2365.0)
        ->and((float) $s['tile']->fresh()->cost)->toBe(333.33);

    $applier->revert($s['estimate'], $s['admin']->id);
    expect((float) $s['demo']->fresh()->cost)->toBe(2150.0)
        ->and(count($s['estimate']->fresh()->options['markups']))->toBe(2);
});

it('previews the new total without saving anything', function () {
    $s = markupScenario();

    expect(app(EstimateMarkupApplier::class)->preview($s['estimate'], -5))->toBe(['before' => 2947.91, 'after' => 2807.75, 'lines' => 4])
        ->and((float) $s['demo']->fresh()->cost)->toBe(2150.0);
});

it('keeps a sub bid in step with its section', function () {
    $s = markupScenario();
    $bid = Bid::query()->create(['project_id' => $s['estimate']->project_id, 'vendor_id' => $s['vendor']->id, 'amount' => 0, 'type' => 'Labor']);
    $s['kitchen']->update(['bid_id' => $bid->id]);

    app(EstimateMarkupApplier::class)->apply($s['estimate'], 10, $s['admin']->id);

    expect((float) $bid->fresh()->amount)->toBe(2496.25);
});

it('leaves a disabled section alone', function () {
    $s = markupScenario();
    $s['bath']->delete();

    $result = app(EstimateMarkupApplier::class)->apply($s['estimate'], 10, $s['admin']->id);

    expect($result['lines'])->toBe(2)->and((float) $s['tile']->fresh()->cost)->toBe(333.33);
});

it('refuses a signed estimate, and a zero or out-of-range markup', function () {
    $s = markupScenario();
    $applier = app(EstimateMarkupApplier::class);

    expect(fn () => $applier->apply($s['estimate'], 0, $s['admin']->id))->toThrow(ValidationException::class)
        ->and(fn () => $applier->apply($s['estimate'], 250, $s['admin']->id))->toThrow(ValidationException::class)
        ->and(fn () => $applier->apply($s['estimate'], -60, $s['admin']->id))->toThrow(ValidationException::class);

    EstimateSignature::query()->create([
        'estimate_id' => $s['estimate']->id, 'user_id' => $s['admin']->id, 'signer_name' => 'Vendor Admin', 'signer_email' => $s['admin']->email,
        'signer_phone' => '+12243334440', 'signature_data' => 'sig', 'signature_type' => 'draw', 'ip_address' => '127.0.0.1',
        'user_agent' => 'PHPUnit', 'document_hash' => 'hash', 'signed_at' => now(),
    ]);

    expect(fn () => $applier->apply($s['estimate']->fresh(), 10, $s['admin']->id))->toThrow(ValidationException::class);
    expect((float) $s['demo']->fresh()->cost)->toBe(2150.0);
});

it('applies from the dialog: preview as you type, then the markup', function () {
    $s = markupScenario();

    Livewire::actingAs($s['admin'])
        ->test(EstimateMarkup::class, ['estimate' => $s['estimate']])
        ->call('openModal')
        ->set('markupPercent', '10')
        ->assertSeeHtml('data-markup-preview')
        ->assertSee('$3,229.75')
        ->assertSee('about 9.6%')
        ->call('apply')
        ->assertHasNoErrors()
        ->assertDispatched('refreshComponent');

    expect((float) $s['demo']->fresh()->cost)->toBe(2365.0);
});

it('marks up only the section whose menu opened the dialog, unless every section is asked for', function () {
    $s = markupScenario();

    $dialog = Livewire::actingAs($s['admin'])
        ->test(EstimateMarkup::class, ['estimate' => $s['estimate']])
        ->call('openModal', $s['kitchen']->id)
        ->assertSet('sectionId', $s['kitchen']->id)
        ->assertSet('allSections', false)
        ->assertSee('Mark up Kitchen Area')
        ->set('markupPercent', '10')
        // Kitchen alone: 2281.25 → 2496.25
        ->assertSee('$2,496.25');

    $dialog->call('apply')->assertHasNoErrors();

    expect((float) $s['kitchen']->fresh()->total)->toBe(2496.25)
        ->and((float) $s['tile']->fresh()->cost)->toBe(333.33)
        ->and((float) $s['bath']->fresh()->total)->toBe(666.66)
        ->and($s['estimate']->fresh()->options['markups'][0]['section_id'])->toBe($s['kitchen']->id);

    // The switch widens it to the whole estimate.
    Livewire::actingAs($s['admin'])
        ->test(EstimateMarkup::class, ['estimate' => $s['estimate']->fresh()])
        ->call('openModal', $s['bath']->id)
        ->set('allSections', true)
        ->assertSee('Mark up every section')
        ->set('markupPercent', '10')
        ->call('apply');

    expect((float) $s['tile']->fresh()->cost)->toBe(366.75)
        // 2365 is whole: 2601.5 → 2602.
        ->and((float) $s['demo']->fresh()->cost)->toBe(2602.0);
});

it('reverts from the dialog, which shows the last markup', function () {
    $s = markupScenario();
    app(EstimateMarkupApplier::class)->apply($s['estimate'], 10, $s['admin']->id, $s['kitchen']->id);

    Livewire::actingAs($s['admin'])
        ->test(EstimateMarkup::class, ['estimate' => $s['estimate']->fresh()])
        ->call('openModal', $s['kitchen']->id)
        ->assertSeeHtml('data-markup-last')
        ->assertSee('Last markup: +10% on Kitchen Area')
        ->call('revert')
        ->assertHasNoErrors()
        ->assertDispatched('refreshComponent');

    expect((float) $s['demo']->fresh()->cost)->toBe(2150.0);
    Livewire::actingAs($s['admin'])
        ->test(\App\Livewire\Estimates\EstimateShow::class, ['estimate' => $s['estimate']->fresh()])
        ->assertDontSee('Revert Last Markup');
});

it('offers Mark Up Line Items in a section\'s menu, and not on a signed estimate', function () {
    $s = markupScenario();

    Livewire::actingAs($s['admin'])
        ->test(\App\Livewire\Estimates\EstimateShow::class, ['estimate' => $s['estimate']])
        ->assertSee('Mark Up Line Items')
        ->assertDontSee('Revert Last Markup')
        ->assertSeeHtml("'openEstimateMarkup', { section: {$s['kitchen']->id} }");

    app(EstimateMarkupApplier::class)->apply($s['estimate'], 10, $s['admin']->id);
    Livewire::actingAs($s['admin'])
        ->test(\App\Livewire\Estimates\EstimateShow::class, ['estimate' => $s['estimate']->fresh()])
        ->assertSee('Revert Last Markup');

    EstimateSignature::query()->create([
        'estimate_id' => $s['estimate']->id, 'user_id' => $s['admin']->id, 'signer_name' => 'Vendor Admin', 'signer_email' => $s['admin']->email,
        'signer_phone' => '+12243334440', 'signature_data' => 'sig', 'signature_type' => 'draw', 'ip_address' => '127.0.0.1',
        'user_agent' => 'PHPUnit', 'document_hash' => 'hash', 'signed_at' => now(),
    ]);

    Livewire::actingAs($s['admin'])
        ->test(\App\Livewire\Estimates\EstimateShow::class, ['estimate' => $s['estimate']->fresh()])
        ->assertDontSee('Mark Up Line Items');
});

it('shows the refusal on a signed estimate in the dialog', function () {
    $s = markupScenario();
    EstimateSignature::query()->create([
        'estimate_id' => $s['estimate']->id, 'user_id' => $s['admin']->id, 'signer_name' => 'Vendor Admin', 'signer_email' => $s['admin']->email,
        'signer_phone' => '+12243334440', 'signature_data' => 'sig', 'signature_type' => 'draw', 'ip_address' => '127.0.0.1',
        'user_agent' => 'PHPUnit', 'document_hash' => 'hash', 'signed_at' => now(),
    ]);

    Livewire::actingAs($s['admin'])
        ->test(EstimateMarkup::class, ['estimate' => $s['estimate']->fresh()])
        ->set('markupPercent', '10')
        ->call('apply')
        ->assertHasErrors('markupPercent');
});
