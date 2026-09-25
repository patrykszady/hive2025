<?php

use App\Livewire\Users\UserCreate;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function sec2_company(): Vendor
{
    $vendor = Vendor::factory()->create();
    $vendor->forceFill(['registration' => ['registered' => true]])->save();

    return $vendor;
}

function sec2_admin(Vendor $vendor, string $tag = 'admin'): User
{
    $user = new User();
    $user->forceFill([
        'first_name' => 'Admin',
        'last_name' => 'User',
        'email' => $tag.'-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'password' => bcrypt('password'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return $user;
}

/**
 * Vendor's own global scope hides other tenants' rows from whoever is
 * currently logged in, so checking a user↔vendor pivot through the
 * Eloquent relation (which joins the scoped `vendors` table) silently
 * returns false for a company the acting user can't see — false negative,
 * not proof of anything. Read the pivot table directly instead.
 */
function sec2_isEmployedBy(User $user, Vendor $vendor): bool
{
    return \Illuminate\Support\Facades\DB::table('user_vendor')
        ->where('user_id', $user->id)
        ->where('vendor_id', $vendor->id)
        ->where('is_employed', 1)
        ->exists();
}

function sec2_member(Vendor $vendor, string $tag = 'member'): User
{
    $user = new User();
    $user->forceFill([
        'first_name' => 'Team',
        'last_name' => 'Member',
        'email' => $tag.'-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'password' => bcrypt('password'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 2, 'is_employed' => 1]);

    return $user;
}

// Finding 1: UserCreate::editMember() must only load users employed by the
// signed-in company (or the user themselves) into the edit form.
it('refuses editMember for a user employed by a different company', function () {
    $companyA = sec2_company();
    $companyB = sec2_company();
    $adminA = sec2_admin($companyA);
    $victim = sec2_admin($companyB, 'victim');

    Livewire::actingAs($adminA)
        ->test(UserCreate::class)
        ->call('editMember', $victim)
        ->assertForbidden();
});

it('allows editMember for a teammate employed by the same company', function () {
    $company = sec2_company();
    $admin = sec2_admin($company);
    $teammate = sec2_member($company);

    Livewire::actingAs($admin)
        ->test(UserCreate::class)
        ->call('editMember', $teammate)
        ->assertSet('form.email', $teammate->email);
});

it('allows editMember on your own profile', function () {
    $company = sec2_company();
    $admin = sec2_admin($company);

    Livewire::actingAs($admin)
        ->test(UserCreate::class)
        ->call('editMember', $admin)
        ->assertSet('form.email', $admin->email);
});

// Finding 2: save() must not attach the acting user as Admin of a company
// other than their own — $model is now Locked, and save() re-checks the id.
it('locks the model property so the browser cannot retarget which company save() attaches to', function () {
    $companyA = sec2_company();
    $adminA = sec2_admin($companyA);

    $component = Livewire::actingAs($adminA)->test(UserCreate::class);

    expect(fn () => $component->set('model.id', 999999))
        ->toThrow(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
});

it('refuses save() from attaching a user to a company other than the signed-in one, even if $model were set to it', function () {
    $companyA = sec2_company();
    $companyB = sec2_company();
    $adminA = sec2_admin($companyA);
    test()->actingAs($adminA);

    $component = new UserCreate();
    $component->form = new \App\Livewire\Forms\UserForm($component, 'form');
    $ref = new ReflectionClass($component);
    $modelProp = $ref->getProperty('model');
    $modelProp->setAccessible(true);
    $modelProp->setValue($component, ['type' => 'vendor', 'id' => (string) $companyB->id]);

    $newUser = User::factory()->create();
    $component->form->setUser($newUser);

    expect(fn () => $component->save())
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    expect(sec2_isEmployedBy($newUser, $companyB))->toBeFalse();
});

it('allows save() to attach a new user to the signed-in company', function () {
    $company = sec2_company();
    $admin = sec2_admin($company);

    Livewire::actingAs($admin)
        ->test(UserCreate::class)
        ->call('newMember', 'vendor', (string) $company->id)
        ->set('user_cell', '2245551234')
        ->call('user_cell_find')
        ->set('form.first_name', 'Brand')
        ->set('form.last_name', 'New')
        ->set('form.email', 'brandnew-'.uniqid().'@example.test')
        ->set('form.role', '2')
        ->set('form.hourly_rate', '25')
        ->call('save');

    $created = User::where('cell_phone', '2245551234')->first();
    expect($created)->not->toBeNull()
        ->and(sec2_isEmployedBy($created, $company))->toBeTrue();
});

// Finding 3: removeMember() must only remove a user from the signed-in
// company's own employment, not from a different tenant's.
it('refuses removeMember for a user employed by a different company', function () {
    $companyA = sec2_company();
    $companyB = sec2_company();
    $adminA = sec2_admin($companyA);
    $victim = sec2_admin($companyB, 'victim2');

    Livewire::actingAs($adminA)
        ->test(UserCreate::class)
        ->call('removeMember', $victim)
        ->assertForbidden();

    expect(sec2_isEmployedBy($victim, $companyB))->toBeTrue();
});

it('allows removeMember for a teammate employed by the same company', function () {
    $company = sec2_company();
    $admin = sec2_admin($company);
    $teammate = sec2_member($company, 'leaving');

    Livewire::actingAs($admin)
        ->test(UserCreate::class)
        ->call('removeMember', $teammate);

    expect(sec2_isEmployedBy($teammate, $company))->toBeFalse();
});

// Finding 4: UserForm::store() must never overwrite an existing user's
// identity fields (name/phone) just because a form submitted their email.
it('does not overwrite an existing user\'s name or phone when reused by email', function () {
    $companyA = sec2_company();
    $companyB = sec2_company();
    $adminA = sec2_admin($companyA);
    $victim = sec2_admin($companyB, 'identity');
    $originalName = $victim->first_name;
    $originalPhone = $victim->cell_phone;

    Livewire::actingAs($adminA)
        ->test(UserCreate::class)
        ->call('newMember', 'vendor', (string) $companyA->id)
        ->set('user_cell', '2245559876')
        ->call('user_cell_find')
        ->set('form.first_name', 'Impersonator')
        ->set('form.last_name', 'Name')
        ->set('form.email', $victim->email)
        ->call('save_user_only');

    $victim->refresh();
    expect($victim->first_name)->toBe($originalName)
        ->and($victim->cell_phone)->toBe($originalPhone);
});

it('lets an admin edit a team member who is flagged as not employed', function () {
    $vendor = sec2_company();
    $admin = sec2_admin($vendor);

    $contractor = new User();
    $contractor->forceFill([
        'first_name' => 'Not',
        'last_name' => 'Employed',
        'email' => 'sec2-not-employed-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224777####'),
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ]);
    $contractor->save();
    $vendor->users()->attach($contractor->id, ['role_id' => 2, 'is_employed' => 0]);

    $outsider = sec2_admin(sec2_company(), 'outsider');

    expect($admin->can('update', $contractor))->toBeTrue()
        ->and($outsider->can('update', $contractor))->toBeFalse();
});
