<?php

use App\Livewire\CompanyEmails\CompanyEmailsForm;
use App\Livewire\Users\AdminLoginAsUser;
use App\Models\CompanyEmail;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function sec2e_company(): Vendor
{
    return Vendor::factory()->create();
}

function sec2e_admin(Vendor $vendor): User
{
    $user = new User();
    $user->forceFill([
        'first_name' => 'Admin', 'last_name' => 'User',
        'email' => 'sec2e-admin-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'password' => bcrypt('password'), 'primary_vendor_id' => $vendor->id,
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return $user;
}

function sec2e_member(Vendor $vendor): User
{
    $user = new User();
    $user->forceFill([
        'first_name' => 'Team', 'last_name' => 'Member',
        'email' => 'sec2e-member-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'password' => bcrypt('password'), 'primary_vendor_id' => $vendor->id,
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 2, 'is_employed' => 1]);

    return $user;
}

// Finding 16: CompanyEmailsForm::store() had no authorize at all.
it('refuses a non-admin from adding a company email', function () {
    $company = sec2e_company();
    $member = sec2e_member($company);

    Livewire::actingAs($member)
        ->test(CompanyEmailsForm::class)
        ->set('email', 'crew-'.uniqid().'@example.test')
        ->call('store')
        ->assertForbidden();
});

it('allows an admin past the authorization check to add a company email', function () {
    // CompanyEmailsForm::store() writes CompanyEmail::create() without a
    // grant_id, and that column is NOT NULL (migration
    // 2025_04_02_211458_add_grant_id_to_company_emails) — this form is
    // pre-existing dead/broken code independent of the authorize() fix here
    // (real accounts are created through the Nylas OAuth callback instead).
    // What this test proves is that an admin clears the authorization gate
    // — the same gate that correctly stops a Member above.
    $company = sec2e_company();
    $admin = sec2e_admin($company);

    expect(\Illuminate\Support\Facades\Gate::forUser($admin)->allows('create', CompanyEmail::class))->toBeTrue();
});

// Finding 15: AdminLoginAsUser::login_as_user() ran Auth::login() before any
// authorization check — now authorized in mount() and again in the action.
it('refuses login_as_user for anyone but the platform superadmin', function () {
    $company = sec2e_company();
    $admin = sec2e_admin($company);
    $victim = sec2e_member($company);

    Livewire::actingAs($admin)
        ->test(AdminLoginAsUser::class)
        ->assertForbidden();

    // Auth::login() must never have run.
    expect(Auth::id())->toBe($admin->id);
});

it('refuses the login_as_user action directly even if mount were bypassed', function () {
    $company = sec2e_company();
    $admin = sec2e_admin($company);
    $victim = sec2e_member($company);
    test()->actingAs($admin);

    $component = new AdminLoginAsUser();
    $component->user_id = $victim->id;

    expect(fn () => $component->login_as_user())
        ->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);

    expect(Auth::id())->toBe($admin->id);
});
