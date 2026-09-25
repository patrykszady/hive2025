<?php

use App\Livewire\Vendors\VendorCreate;
use App\Livewire\Vendors\VendorOptions;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function sec2v_company(bool $registered = true): Vendor
{
    $vendor = Vendor::factory()->create();
    $vendor->forceFill(['registration' => $registered ? ['registered' => true] : null])->save();

    return $vendor;
}

function sec2v_admin(Vendor $vendor): User
{
    $user = new User();
    $user->forceFill([
        'first_name' => 'Admin', 'last_name' => 'User',
        'email' => 'sec2v-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'password' => bcrypt('password'), 'primary_vendor_id' => $vendor->id,
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return $user;
}

// Finding 6: VendorForm::update()/VendorCreate::editVendor() must not let one
// tenant rewrite another tenant's own registered company record.
it('refuses editVendor for another tenant\'s registered company', function () {
    $companyA = sec2v_company();
    $companyB = sec2v_company(); // registered — its own real business
    $adminA = sec2v_admin($companyA);
    // Company A has (legitimately or not) linked company B into its list —
    // VendorScope would let it load company B, editing must still refuse.
    $companyA->vendors()->syncWithoutDetaching([$companyB->id]);

    Livewire::actingAs($adminA)
        ->test(VendorCreate::class)
        ->call('editVendor', $companyB)
        ->assertForbidden();
});

it('allows editVendor for your own company', function () {
    $company = sec2v_company();
    $admin = sec2v_admin($company);

    Livewire::actingAs($admin)
        ->test(VendorCreate::class)
        ->call('editVendor', $company)
        ->assertSet('business_name_text', $company->business_name);
});

it('allows editVendor for an unregistered directory vendor the company added', function () {
    $company = sec2v_company();
    $admin = sec2v_admin($company);
    $sub = sec2v_company(registered: false);
    $sub->forceFill(['business_type' => 'Sub'])->save();
    $company->vendors()->syncWithoutDetaching([$sub->id]);

    Livewire::actingAs($admin)
        ->test(VendorCreate::class)
        ->call('editVendor', $sub)
        ->assertSet('business_name_text', $sub->business_name);
});

// Finding 5: VendorCreate::addVendorToCompany() must not let a tenant link
// another tenant's own registered company into its vendor list.
it('refuses linking another tenant\'s registered company', function () {
    $companyA = sec2v_company();
    $companyB = sec2v_company();
    $adminA = sec2v_admin($companyA);

    Livewire::actingAs($adminA)
        ->test(VendorCreate::class)
        ->call('addVendorToCompany', $companyB->id)
        ->assertForbidden();

    expect(\Illuminate\Support\Facades\DB::table('vendors_vendor')
        ->where('belongs_to_vendor_id', $companyA->id)
        ->where('vendor_id', $companyB->id)
        ->exists())->toBeFalse();
});

it('allows linking an unregistered retail vendor', function () {
    $company = sec2v_company();
    $admin = sec2v_admin($company);
    $retailer = sec2v_company(registered: false);
    $retailer->forceFill(['business_type' => 'Retail'])->save();

    Livewire::actingAs($admin)
        ->test(VendorCreate::class)
        ->call('addVendorToCompany', $retailer->id);

    expect(\Illuminate\Support\Facades\DB::table('vendors_vendor')
        ->where('belongs_to_vendor_id', $company->id)
        ->where('vendor_id', $retailer->id)
        ->exists())->toBeTrue();
});

// Finding 7: VendorOptions::$existing_logo must be Locked — the browser must
// not be able to point removeLogo()/save() at another company's file.
it('locks existing_logo so the browser cannot retarget which file gets deleted', function () {
    $company = sec2v_company();
    $admin = sec2v_admin($company);
    $company->forceFill(['options' => ['logo' => 'vendor-logos/mine.png']])->save();

    $component = Livewire::actingAs($admin)->test(VendorOptions::class);

    expect(fn () => $component->set('existing_logo', 'vendor-logos/someone-elses-secret.png'))
        ->toThrow(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
});

it('removeLogo deletes only the signed-in company\'s own logo file', function () {
    Storage::fake('public');
    $company = sec2v_company();
    $admin = sec2v_admin($company);
    Storage::disk('public')->put('vendor-logos/mine.png', 'fake-image-bytes');
    $company->forceFill(['options' => ['logo' => 'vendor-logos/mine.png']])->save();

    Livewire::actingAs($admin)
        ->test(VendorOptions::class)
        ->call('removeLogo');

    Storage::disk('public')->assertMissing('vendor-logos/mine.png');
    expect(data_get($company->fresh()->options, 'logo'))->toBeNull();
});
