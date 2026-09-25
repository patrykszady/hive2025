<?php

use App\Livewire\Timesheets\TimesheetPaymentCreate;
use App\Livewire\Vendors\VendorPaymentCreate;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function sec2p_company(): Vendor
{
    return Vendor::factory()->create(['business_type' => 'LLC']);
}

function sec2p_admin(Vendor $vendor): User
{
    $user = new User();
    $user->forceFill([
        'first_name' => 'Admin', 'last_name' => 'User',
        'email' => 'sec2p-admin-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'password' => bcrypt('password'), 'primary_vendor_id' => $vendor->id,
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return $user;
}

function sec2p_member(Vendor $vendor): User
{
    $user = new User();
    $user->forceFill([
        'first_name' => 'Team', 'last_name' => 'Member',
        'email' => 'sec2p-member-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'password' => bcrypt('password'), 'primary_vendor_id' => $vendor->id,
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 2, 'is_employed' => 1]);

    return $user;
}

// Finding 14: TimesheetPolicy::viewPayment must require the target employee
// belongs to the acting admin's own company, not just "some Admin, any user".
it('refuses viewing a payment page for an employee of another company', function () {
    $companyA = sec2p_company();
    $companyB = sec2p_company();
    $adminA = sec2p_admin($companyA);
    $employeeB = sec2p_member($companyB);

    Livewire::actingAs($adminA)
        ->test(TimesheetPaymentCreate::class, ['user' => $employeeB])
        ->assertForbidden();
});

it('allows viewing the payment page for your own employee', function () {
    $company = sec2p_company();
    $admin = sec2p_admin($company);
    $employee = sec2p_member($company);

    Livewire::actingAs($admin)
        ->test(TimesheetPaymentCreate::class, ['user' => $employee])
        ->assertOk();
});

it('allows an employee to view their own payment page', function () {
    $company = sec2p_company();
    $employee = sec2p_member($company);

    Livewire::actingAs($employee)
        ->test(TimesheetPaymentCreate::class, ['user' => $employee])
        ->assertOk();
});

// Finding 13: VendorPaymentCreate had no policy check at all — Members must
// not be able to write payment checks.
it('refuses a non-admin from opening the vendor payment page', function () {
    $company = sec2p_company();
    $member = sec2p_member($company);
    $sub = Vendor::factory()->create(['business_type' => 'Sub']);

    Livewire::actingAs($member)
        ->test(VendorPaymentCreate::class, ['vendor' => $sub])
        ->assertForbidden();
});

it('refuses a 1099 vendor\'s own admin from opening the vendor payment page', function () {
    $company = Vendor::factory()->create(['business_type' => '1099']);
    $admin = sec2p_admin($company);
    $sub = Vendor::factory()->create(['business_type' => 'Sub']);

    Livewire::actingAs($admin)
        ->test(VendorPaymentCreate::class, ['vendor' => $sub])
        ->assertForbidden();
});

it('allows an admin to open the vendor payment page', function () {
    $company = sec2p_company();
    $admin = sec2p_admin($company);
    $sub = Vendor::factory()->create(['business_type' => 'Sub']);

    Livewire::actingAs($admin)
        ->test(VendorPaymentCreate::class, ['vendor' => $sub])
        ->assertOk();
});
