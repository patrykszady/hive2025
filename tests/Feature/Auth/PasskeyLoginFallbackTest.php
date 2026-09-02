<?php

use App\Livewire\Auth\Login;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function passkeyLoginUser(?string $password): User
{
    $vendor = Vendor::factory()->create();

    $user = new User();
    $user->forceFill([
        'first_name' => 'Pass',
        'last_name' => 'Key',
        'email' => 'passkey-login-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'password' => $password ? bcrypt($password) : null,
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return $user;
}

it('tells a passwordless user why the passkey step was abandoned and still offers a way in', function () {
    // Registering a passkey nulls the password — on a new device with no
    // matching passkey this user would otherwise see a bare fallback with
    // no explanation of what happened.
    $user = passkeyLoginUser(password: null);

    Livewire::test(Login::class)
        ->set('identifier', $user->email)
        ->call('checkEmail')
        ->assertSet('step', 'credentials')
        ->call('showPasswordLogin', 'no-passkey')
        ->assertSee('No passkey for this device was found')
        ->assertSee('Use one-time code')
        ->assertDontSee('Your password');
});

it('offers the password alongside the notice when the user still has one', function () {
    $user = passkeyLoginUser(password: 'secret-pass-123');

    Livewire::test(Login::class)
        ->set('identifier', $user->email)
        ->call('checkEmail')
        ->call('showPasswordLogin', 'no-passkey')
        ->assertSee('No passkey for this device was found')
        ->assertSee('your password or')
        ->assertSee('Your password');
});

it('clears the notice when going back to the email step', function () {
    $user = passkeyLoginUser(password: null);

    Livewire::test(Login::class)
        ->set('identifier', $user->email)
        ->call('checkEmail')
        ->call('showPasswordLogin', 'no-passkey')
        ->assertSet('passkeyNotice', fn ($v) => $v !== null)
        ->call('goBack')
        ->assertSet('passkeyNotice', null);
});

it('logs every passkey options request to the passkeys channel', function () {
    $user = passkeyLoginUser(password: null);

    $spy = Mockery::spy(\Psr\Log\LoggerInterface::class);
    Log::shouldReceive('channel')->with('passkeys')->andReturn($spy);

    $this->postJson('/webauthn/login/options', ['email' => $user->email])->assertOk();

    $spy->shouldHaveReceived('info')->withArgs(fn ($message, $context) => $message === 'WebAuthn login: options requested'
        && $context['user_id'] === $user->id
        && $context['credentials_offered'] === 0);
});
