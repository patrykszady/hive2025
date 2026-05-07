<?php

use App\Livewire\Users\Passkeys;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laragear\WebAuthn\Models\WebAuthnCredential;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makePasskeyTestUser(array $overrides = []): User
{
    // The `email_verified_at` column was dropped by a later migration, so we
    // cannot use the User factory directly — build the model attribute-by-attribute.
    $unique = str()->random(8);

    $user = new User();
    $user->forceFill(array_merge([
        'first_name' => 'Test',
        'last_name' => 'User-' . $unique,
        'email' => "passkey-{$unique}@example.test",
        'cell_phone' => (string) random_int(2_000_000_000, 8_999_999_999),
        'password' => null,
    ], $overrides));
    $user->save();

    return $user->refresh();
}

function makeCredentialForUser(User $user, array $overrides = []): WebAuthnCredential
{
    $credential = new WebAuthnCredential();
    $credential->forceFill(array_merge([
        'id' => 'cred_' . str()->random(20),
        'authenticatable_type' => $user->getMorphClass(),
        'authenticatable_id' => $user->id,
        'user_id' => (string) \Illuminate\Support\Str::uuid(),
        'counter' => 0,
        'rp_id' => 'localhost',
        'origin' => 'https://localhost',
        'transports' => ['internal'],
        'aaguid' => '00000000-0000-0000-0000-000000000000',
        'public_key' => 'fake-public-key',
        'attestation_format' => 'none',
        'certificates' => [],
        'alias' => 'My Laptop',
        'device_type' => 'macOS',
        'device_name' => 'macOS',
        'user_agent' => 'PestTest/1.0',
    ], $overrides));
    $credential->save();

    return $credential;
}

it('renders the passkeys component for the authenticated user', function () {
    $user = makePasskeyTestUser();

    Livewire::actingAs($user)
        ->test(Passkeys::class, ['user' => $user])
        ->assertOk()
        ->assertSee('Your Passkeys')
        ->assertSee('Add a passkey')
        ->assertSee('No passkeys yet');
});

it('lists existing passkeys', function () {
    $user = makePasskeyTestUser();
    makeCredentialForUser($user, ['alias' => 'Patryks iPhone', 'device_type' => 'iOS']);

    Livewire::actingAs($user)
        ->test(Passkeys::class, ['user' => $user])
        ->assertSee('Patryks iPhone')
        ->assertSee('iOS');
});

it('renames a passkey', function () {
    $user = makePasskeyTestUser();
    $cred = makeCredentialForUser($user, ['alias' => 'Old Name']);

    Livewire::actingAs($user)
        ->test(Passkeys::class, ['user' => $user])
        ->call('startRename', $cred->id)
        ->set('newAlias', 'Brand New Name')
        ->call('saveRename')
        ->assertHasNoErrors();

    expect($cred->fresh()->alias)->toBe('Brand New Name');
});

it('rejects empty alias on rename', function () {
    $user = makePasskeyTestUser();
    $cred = makeCredentialForUser($user);

    Livewire::actingAs($user)
        ->test(Passkeys::class, ['user' => $user])
        ->call('startRename', $cred->id)
        ->set('newAlias', '')
        ->call('saveRename')
        ->assertHasErrors(['newAlias' => 'required']);
});

it('disables and re-enables a passkey', function () {
    $user = makePasskeyTestUser();
    $cred = makeCredentialForUser($user);

    Livewire::actingAs($user)
        ->test(Passkeys::class, ['user' => $user])
        ->call('disablePasskey', $cred->id);

    expect($cred->fresh()->isDisabled())->toBeTrue();

    Livewire::actingAs($user)
        ->test(Passkeys::class, ['user' => $user])
        ->call('enablePasskey', $cred->id);

    expect($cred->fresh()->isEnabled())->toBeTrue();
});

it('deletes a passkey permanently', function () {
    $user = makePasskeyTestUser();
    $cred = makeCredentialForUser($user);

    Livewire::actingAs($user)
        ->test(Passkeys::class, ['user' => $user])
        ->call('deletePasskey', $cred->id);

    expect(WebAuthnCredential::query()->whereKey($cred->id)->exists())->toBeFalse();
});

it('forbids managing passkeys when viewing another user', function () {
    // The view policy will normally block cross-user access during mount(),
    // but we still defend the manage actions explicitly. Verify canManage
    // is false and the manage methods abort when invoked directly.
    $owner = makePasskeyTestUser();
    $viewer = makePasskeyTestUser();
    $cred = makeCredentialForUser($owner);

    // Bypass mount's view-policy check by acting as the owner first to mount,
    // then swapping auth and calling a manage action.
    $component = Livewire::actingAs($owner)
        ->test(Passkeys::class, ['user' => $owner]);

    auth()->login($viewer);

    $component->call('disablePasskey', $cred->id)->assertStatus(403);

    expect($cred->fresh()->isEnabled())->toBeTrue();
});

it('does not show the add-passkey button when viewing another user', function () {
    $owner = makePasskeyTestUser();
    $viewer = makePasskeyTestUser();

    Livewire::actingAs($viewer)
        ->test(Passkeys::class, ['user' => $owner])
        ->assertDontSee('Add a passkey');
});
