<?php

use App\Livewire\Entry\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    Http::fake([
        'api.telnyx.com/*' => Http::response(['data' => ['id' => 'msg-1']], 200),
    ]);
    config([
        'services.telnyx.api_key' => 'test-key',
        'services.telnyx.from' => '+12245550000',
        'services.telnyx.messaging_profile_id' => null,
    ]);
});

/**
 * @param array<string, mixed> $registration
 */
function registrationPhoneUser(string $digits, array $registration): User
{
    return User::factory()->create([
        'first_name' => 'Owner',
        'last_name' => 'Account',
        'email' => 'owner-'.uniqid().'@example.test',
        'cell_phone' => $digits,
        'password' => Hash::make('original-pass-1'),
        'registration' => $registration,
    ]);
}

function registrationAtPhoneStep(string $formatted)
{
    return Livewire::withQueryParams(['step' => 'phone'])
        ->test(Registration::class)
        ->set('user_cell', $formatted);
}

it('sends a registered phone to the login page without attaching the account', function () {
    $victim = registrationPhoneUser('2245550101', ['registered' => true]);
    $usersBefore = User::query()->count();

    $component = registrationAtPhoneStep('(224) 555-0101')
        ->call('confirmUserCellAction')
        ->assertRedirect(route('login'));

    expect($component->get('user')->exists)->toBeFalse();
    Http::assertNothingSent();

    $component->set('use_password', true)
        ->set('password', 'hijacked-pass-1')
        ->set('password_confirmation', 'hijacked-pass-1')
        ->call('register_user');

    $this->assertGuest();
    expect(Hash::check('original-pass-1', $victim->fresh()->password))->toBeTrue();

    $component->call('prepareUserForPasskey');

    $this->assertGuest();
    expect($victim->fresh()->first_name)->toBe('Owner')
        ->and(User::query()->count())->toBe($usersBefore);
});

it('texts a code to an unregistered account even when an earlier session verified it', function () {
    $victim = registrationPhoneUser('2245550202', ['cell_verified' => true]);

    $component = registrationAtPhoneStep('(224) 555-0202')
        ->call('confirmUserCellAction')
        ->assertRedirect(route('registration', ['step' => 'verify-phone']));

    Http::assertSentCount(1);

    $component->set('use_password', true)
        ->set('password', 'hijacked-pass-1')
        ->set('password_confirmation', 'hijacked-pass-1')
        ->call('register_user');

    $this->assertGuest();
    expect(Hash::check('original-pass-1', $victim->fresh()->password))->toBeTrue()
        ->and($victim->fresh()->registration['registered'] ?? false)->toBeFalse();
});

it('keeps the texted code out of the browser and accepts it from the session', function () {
    $component = registrationAtPhoneStep('(224) 555-0303')
        ->call('confirmUserCellAction')
        ->assertRedirect(route('registration', ['step' => 'verify-phone']));

    $code = session('registration_codes.phone');
    expect($code)->toMatch('/^\d{6}$/')
        ->and(property_exists(Registration::class, 'phone_verification'))->toBeFalse()
        ->and($component->html())->not->toContain($code)
        ->and(json_encode($component->snapshot))->not->toContain($code);

    $wrong = $code === '000000' ? '111111' : '000000';

    $component->set('cell_verification_code', $wrong)
        ->call('cell_verification_code_confirm')
        ->assertHasErrors('cell_verification_code');

    expect(session('registration_verified_cell'))->toBeNull();

    $component->set('cell_verification_code', $code)
        ->call('cell_verification_code_confirm')
        ->assertHasNoErrors()
        ->assertRedirect(route('registration', ['step' => 'email']));

    expect(session('registration_verified_cell'))->toBe('2245550303')
        ->and(session('registration_codes.phone'))->toBeNull();
});

it('refuses to finish a registration for a phone other than the one verified', function () {
    registrationAtPhoneStep('(224) 555-0404')
        ->call('confirmUserCellAction');

    $component = Livewire::withQueryParams(['step' => 'verify-phone'])
        ->test(Registration::class)
        ->set('cell_verification_code', session('registration_codes.phone'))
        ->call('cell_verification_code_confirm')
        ->assertHasNoErrors();

    $victim = registrationPhoneUser('2245550505', ['cell_verified' => true]);

    // Point the (client-writable) phone field at someone else's number.
    $component->set('user_cell', '(224) 555-0505')
        ->set('use_password', true)
        ->set('password', 'hijacked-pass-1')
        ->set('password_confirmation', 'hijacked-pass-1')
        ->call('register_user');

    $this->assertGuest();
    expect(User::query()->where('cell_phone', '(224) 555-0505')->exists())->toBeFalse()
        ->and(Hash::check('original-pass-1', $victim->fresh()->password))->toBeTrue();
});
