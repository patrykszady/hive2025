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

/** Run the gate against a bound request, the way the component sees it. */
function passkeyGate(string $url, string $rpId): bool
{
    config(['webauthn.relying_party.id' => $rpId]);
    app()->instance('request', \Illuminate\Http\Request::create($url));

    return (new ReflectionMethod(Login::class, 'canUsePasskeysForCurrentRequest'))->invoke(new Login());
}

it('allows passkeys on plain-http localhost, the way browsers do', function () {
    expect(passkeyGate('http://localhost:8000/login', 'localhost'))->toBeTrue();
});

it('still refuses an IP address, which no browser accepts as a passkey domain', function () {
    expect(passkeyGate('http://127.0.0.1:8000/login', 'localhost'))->toBeFalse();
});

it('gates on the relying-party host, which is what actually decides', function () {
    // NOTE: bootstrap/app.php trusts every proxy (trustProxies at '*'), so
    // isFromTrustedProxy() is always true and the isSecure() arm of the gate
    // never fires on its own. The effective gate is this host match — a page
    // served from a host the relying party doesn't cover can't do passkeys.
    expect(passkeyGate('https://hive.contractors/login', 'hive.contractors'))->toBeTrue()
        ->and(passkeyGate('https://dev.hive.contractors/login', 'hive.contractors'))->toBeTrue()
        ->and(passkeyGate('https://someone-else.test/login', 'hive.contractors'))->toBeFalse();
});

it('points a 127.0.0.1 visitor at the same page on localhost', function () {
    config(['webauthn.relying_party.id' => 'localhost']);
    app()->instance('request', \Illuminate\Http\Request::create('http://127.0.0.1:8000/login?next=%2Fhub'));

    // The login PAGE on localhost — deliberately not fullUrl(), which inside
    // a Livewire update is the /livewire-<hash>/update endpoint.
    expect((new Login())->passkeyLocalhostUrl)->toBe('http://localhost:8000/login');

    app()->instance('request', \Illuminate\Http\Request::create('http://localhost:8000/login'));
    expect((new Login())->passkeyLocalhostUrl)->toBeNull();

    app()->instance('request', \Illuminate\Http\Request::create('https://hive.contractors/login'));
    config(['webauthn.relying_party.id' => 'hive.contractors']);
    expect((new Login())->passkeyLocalhostUrl)->toBeNull();
});

it('does not offer a passkey minted for a different relying party', function () {
    config(['webauthn.relying_party.id' => 'localhost']);
    $user = passkeyLoginUser(password: null);

    // A production passkey, seen from local dev: the browser scopes
    // credentials by relying party, so it can never present this one here.
    $credential = new \Laragear\WebAuthn\Models\WebAuthnCredential();
    $credential->forceFill([
        'id' => 'prod-credential-'.uniqid(),
        'authenticatable_type' => $user->getMorphClass(),
        'authenticatable_id' => $user->id,
        'user_id' => \Illuminate\Support\Str::uuid()->toString(),
        'counter' => 0,
        'rp_id' => 'hive.contractors',
        'origin' => 'https://hive.contractors',
        'aaguid' => '00000000-0000-0000-0000-000000000000',
        'attestation_format' => 'none',
        'public_key' => 'key',
        'device_type' => deviceTypeForTestRequest(),
    ])->save();

    Livewire::test(Login::class)
        ->set('identifier', $user->email)
        ->call('checkEmail')
        ->assertSet('hasPasskey', false);

    // The same credential under THIS relying party is offered — proving the
    // rp_id scoping is what withheld it, not some other gate.
    $credential->forceFill(['rp_id' => 'localhost', 'origin' => 'http://localhost:8000'])->save();

    Livewire::test(Login::class)
        ->set('identifier', $user->email)
        ->call('checkEmail')
        ->assertSet('hasPasskey', true);
});

/** What the app resolves for a request with no User-Agent — 'Unknown'. */
function deviceTypeForTestRequest(): string
{
    return (new class { use \App\Traits\DetectsDeviceType; public function get(): string { return $this->currentDeviceType(); } })->get();
}
