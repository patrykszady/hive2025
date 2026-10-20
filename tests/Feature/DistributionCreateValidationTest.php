<?php

use App\Livewire\Distributions\DistributionCreate;
use App\Livewire\Forms\DistributionForm;
use App\Models\Distribution;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Distributions\DistributionFigures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * DistributionCreate::save() called $this->form->store() without ever
 * validating — DistributionForm's #[Rule] attributes on user_id/name were
 * declared but never enforced, and nothing confirmed the chosen user was
 * actually one of DistributionFigures::eligibleUsers() (DistributionFigures.
 * php:121-124), so a tampered user_id could point a distribution at any
 * user. Fixed by adding DistributionForm::rules() (Rule::in() against the
 * same $this->users the component already loads in mount()) and calling
 * $this->validate() from store().
 */
function distributionCreateFixture(): array
{
    $vendor = Vendor::factory()->create();
    $vendor->forceFill([
        'business_type' => 'LLC',
        'registration' => ['registered' => true],
    ])->save();

    $admin = User::query()->create([
        'first_name' => 'Dist', 'last_name' => 'Admin',
        'email' => 'dist-admin-'.uniqid().'@example.test',
        'cell_phone' => '555'.rand(1000000, 9999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($admin->id, ['role_id' => 1, 'is_employed' => 1]);

    $eligible = User::query()->create([
        'first_name' => 'Eligible', 'last_name' => 'Owner',
        'email' => 'dist-eligible-'.uniqid().'@example.test',
        'cell_phone' => '555'.rand(1000000, 9999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($eligible->id, ['role_id' => 1, 'is_employed' => 1]);

    // Already has a distribution, so DistributionFigures::eligibleUsers()
    // (whereDoesntHave('distributions')) excludes this one on purpose.
    $alreadyHasOne = User::query()->create([
        'first_name' => 'Already', 'last_name' => 'HasOne',
        'email' => 'dist-already-'.uniqid().'@example.test',
        'cell_phone' => '555'.rand(1000000, 9999999),
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($alreadyHasOne->id, ['role_id' => 1, 'is_employed' => 1]);
    Distribution::create(['vendor_id' => $vendor->id, 'user_id' => $alreadyHasOne->id, 'name' => 'Already - Home']);

    return compact('vendor', 'admin', 'eligible', 'alreadyHasOne');
}

it('rejects an empty submission (no user, no name)', function () {
    ['vendor' => $vendor, 'admin' => $admin] = distributionCreateFixture();

    Livewire::actingAs($admin)
        ->test(DistributionCreate::class)
        ->call('save')
        ->assertHasErrors(['form.user_id', 'form.name']);

    expect(Distribution::where('vendor_id', $vendor->id)->count())->toBe(1); // only the fixture's pre-existing one
});

it('creates a distribution for a user who is actually eligible', function () {
    ['admin' => $admin, 'eligible' => $eligible] = distributionCreateFixture();

    Livewire::actingAs($admin)
        ->test(DistributionCreate::class)
        ->set('form.user_id', $eligible->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Distribution::where('user_id', $eligible->id)->exists())->toBeTrue();
});

it('refuses a user_id that is not one of DistributionFigures::eligibleUsers(), even a real team member', function () {
    ['vendor' => $vendor, 'admin' => $admin, 'alreadyHasOne' => $alreadyHasOne] = distributionCreateFixture();

    test()->actingAs($admin);

    // Built directly (bypassing the component's updated() hook, which
    // assumes user_id is always one of $this->form->users and would throw
    // on a tampered id before validation ever got a chance to run) so this
    // pins down DistributionForm::store()'s own guard specifically.
    $component = new DistributionCreate;
    $form = new DistributionForm($component, 'form');
    $form->users = app(DistributionFigures::class)->eligibleUsers($vendor);
    $form->user_id = $alreadyHasOne->id;
    $form->name = 'Tampered - Home';
    $component->form = $form;

    expect(fn () => $component->save())->toThrow(ValidationException::class);

    expect(Distribution::where('user_id', $alreadyHasOne->id)->count())->toBe(1); // still just the fixture's original row
});
