<?php

use App\Livewire\Checks\ChecksIndex;
use App\Models\Check;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);
});

/**
 * An Admin of their own vendor with three checks on different dates.
 *
 * @return array{admin: User, oldest: Check, middle: Check, newest: Check}
 */
function checksIndexSortFixture(): array
{
    $vendor = Vendor::factory()->create(['business_type' => 'LLC']);
    $admin = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $admin->vendors()->attach($vendor->id, ['role_id' => 1, 'is_employed' => 1]);

    $check = fn (string $date) => Check::withoutGlobalScopes()->create([
        'check_type' => 'Check',
        'check_number' => random_int(1000, 999999),
        'date' => $date,
        'amount' => 250,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $admin->id,
    ]);

    return [
        'admin' => $admin,
        'oldest' => $check('2026-01-05'),
        'middle' => $check('2026-03-10'),
        'newest' => $check('2026-06-20'),
    ];
}

it('lists checks newest first by default', function () {
    $fx = checksIndexSortFixture();
    $this->actingAs($fx['admin']);

    $ids = Livewire::test(ChecksIndex::class)->instance()->checks()->pluck('id')->all();

    expect($ids)->toBe([$fx['newest']->id, $fx['middle']->id, $fx['oldest']->id]);
});

it('toggles the Date header between oldest and newest first instead of throwing', function () {
    $fx = checksIndexSortFixture();
    $this->actingAs($fx['admin']);

    $component = Livewire::test(ChecksIndex::class)->call('sort', 'date')->assertHasNoErrors();

    expect($component->get('sortDirection'))->toBe('asc')
        ->and($component->instance()->checks()->pluck('id')->all())->toBe([$fx['oldest']->id, $fx['middle']->id, $fx['newest']->id]);

    $component->call('sort', 'date');

    expect($component->get('sortDirection'))->toBe('desc')
        ->and($component->instance()->checks()->pluck('id')->all())->toBe([$fx['newest']->id, $fx['middle']->id, $fx['oldest']->id]);
});

it('ignores a column that is not sortable', function () {
    $fx = checksIndexSortFixture();
    $this->actingAs($fx['admin']);

    Livewire::test(ChecksIndex::class)
        ->call('sort', 'amount')
        ->assertSet('sortBy', 'date')
        ->assertSet('sortDirection', 'desc');
});
