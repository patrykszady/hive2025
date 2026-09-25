<?php

use App\Livewire\BulkMatch\BulkMatchCreate;
use App\Livewire\Forms\BulkMatchForm;
use App\Models\TransactionBulkMatch;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * BulkMatchCreate has no Blade view (livewire.bulk-match.form doesn't exist —
 * this feature is unrouted/unreachable through the UI today, confirmed by
 * the pre-existing "dd('here in store of BulkMatch')" and commented-out
 * render() authorize). Livewire::test() renders after every call and fails
 * with "View not found" regardless of the action under test, so — same as
 * MatchVendorApplySuggestionTest — the component is driven directly as a
 * plain PHP object instead of through the Livewire test harness.
 */
function sec2b_admin(): array
{
    $vendor = Vendor::factory()->create();
    $admin = new User();
    $admin->forceFill([
        'first_name' => 'Admin', 'last_name' => 'User',
        'email' => 'sec2b-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'password' => bcrypt('password'), 'primary_vendor_id' => $vendor->id,
    ]);
    $admin->save();
    $vendor->users()->attach($admin->id, ['role_id' => 1]);

    return [$vendor, $admin];
}

function sec2b_component(): BulkMatchCreate
{
    $component = new BulkMatchCreate();
    $component->form = new BulkMatchForm($component, 'form');

    return $component;
}

/**
 * Outside a real Livewire request there is no "current" component for
 * modal()->show()/Flux::toast() to attach to — both are the LAST statement
 * in their methods (same pattern as MatchVendorApplySuggestionTest), so
 * everything under test has already run by the time this throws.
 */
function sec2b_ignoreLivewireChrome(callable $callback): void
{
    try {
        $callback();
    } catch (\Error $e) {
        if (! str_contains($e->getMessage(), 'dispatch()')) {
            throw $e;
        }
    }
}

// Finding 9/11: TransactionBulkMatch has no tenant scope of its own — a rule
// must only be editable/deletable by the company that owns it
// (belongs_to_vendor_id), never by another tenant's admin.
it('refuses updateMatch for another company\'s matching rule', function () {
    [$companyA, $adminA] = sec2b_admin();
    [$companyB] = sec2b_admin();
    $merchant = Vendor::factory()->create(['business_type' => 'Retail']);
    $rule = TransactionBulkMatch::create([
        'amount' => 10, 'vendor_id' => $merchant->id,
        'options' => ['amount_type' => 'ANY'], 'belongs_to_vendor_id' => $companyB->id,
    ]);
    test()->actingAs($adminA);

    $component = sec2b_component();

    expect(fn () => $component->updateMatch($rule))->toThrow(AuthorizationException::class);
});

it('allows updateMatch for your own company\'s matching rule', function () {
    [$company, $admin] = sec2b_admin();
    $merchant = Vendor::factory()->create(['business_type' => 'Retail']);
    $company->vendors()->syncWithoutDetaching([$merchant->id]); // VendorScope visibility for $match->vendor->name
    $rule = TransactionBulkMatch::create([
        'amount' => 10, 'vendor_id' => $merchant->id,
        'options' => ['amount_type' => 'ANY'], 'belongs_to_vendor_id' => $company->id,
    ]);
    test()->actingAs($admin);

    $component = sec2b_component();
    sec2b_ignoreLivewireChrome(fn () => $component->updateMatch($rule));

    expect($component->form->vendor_id)->toBe($merchant->id);
});

it('refuses edit() from rewriting another company\'s matching rule even once loaded', function () {
    [$companyA, $adminA] = sec2b_admin();
    [$companyB] = sec2b_admin();
    $merchant = Vendor::factory()->create(['business_type' => 'Retail']);
    $rule = TransactionBulkMatch::create([
        'amount' => 10, 'vendor_id' => $merchant->id,
        'options' => ['amount_type' => 'ANY'], 'belongs_to_vendor_id' => $companyB->id,
    ]);
    test()->actingAs($adminA);

    $form = new BulkMatchForm(new BulkMatchCreate(), 'form');
    $form->setMatch($rule);

    expect(fn () => $form->update())->toThrow(AuthorizationException::class);
    expect($rule->fresh()->amount)->toEqual(10.0);
});

it('refuses remove() from deleting another company\'s matching rule even if the form somehow held it', function () {
    [$companyA, $adminA] = sec2b_admin();
    [$companyB] = sec2b_admin();
    $merchant = Vendor::factory()->create(['business_type' => 'Retail']);
    $rule = TransactionBulkMatch::create([
        'amount' => 10, 'vendor_id' => $merchant->id,
        'options' => ['amount_type' => 'ANY'], 'belongs_to_vendor_id' => $companyB->id,
    ]);
    test()->actingAs($adminA);

    // remove() carries its own authorize independent of updateMatch()'s —
    // bypass the guarded setter to prove that second check holds on its own.
    $component = sec2b_component();
    $ref = new ReflectionClass($component->form);
    $matchProp = $ref->getProperty('match');
    $matchProp->setAccessible(true);
    $matchProp->setValue($component->form, $rule);

    expect(fn () => $component->remove())->toThrow(AuthorizationException::class);
    expect(TransactionBulkMatch::query()->whereKey($rule->id)->exists())->toBeTrue();
});

it('allows remove() for your own company\'s matching rule', function () {
    [$company, $admin] = sec2b_admin();
    $merchant = Vendor::factory()->create(['business_type' => 'Retail']);
    $company->vendors()->syncWithoutDetaching([$merchant->id]); // VendorScope visibility for $match->vendor->name
    $rule = TransactionBulkMatch::create([
        'amount' => 10, 'vendor_id' => $merchant->id,
        'options' => ['amount_type' => 'ANY'], 'belongs_to_vendor_id' => $company->id,
    ]);
    test()->actingAs($admin);

    $component = sec2b_component();
    sec2b_ignoreLivewireChrome(fn () => $component->updateMatch($rule));
    sec2b_ignoreLivewireChrome(fn () => $component->remove());

    expect(TransactionBulkMatch::query()->whereKey($rule->id)->exists())->toBeFalse();
});
