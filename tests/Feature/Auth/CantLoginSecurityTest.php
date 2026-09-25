<?php

use App\Livewire\Auth\CantLogin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
});

function recoveryUser(): User
{
    return User::factory()->create([
        'email' => 'recover-'.uniqid().'@example.test',
        'password' => Hash::make('original-pass-1'),
        'registration' => ['registered' => true],
    ]);
}

function recoveryCode(User $user): string
{
    $code = Cache::get('password_reset_code:'.$user->id);
    expect($code)->toMatch('/^\d{6}$/');

    return $code;
}

it('resets the password only after the mailed code is verified', function () {
    $user = recoveryUser();

    $component = Livewire::test(CantLogin::class)
        ->set('identifier', $user->email)
        ->call('sendVerificationCode')
        ->assertSet('step', 'verify_code');

    $component->set('verification_code', recoveryCode($user))
        ->call('verifyCode')
        ->assertHasNoErrors()
        ->assertSet('step', 'reset_password');

    $component->set('password', 'brand-new-pass-1')
        ->set('password_confirmation', 'brand-new-pass-1')
        ->call('resetPassword')
        ->assertHasNoErrors()
        ->assertRedirect(route('login'));

    expect(Hash::check('brand-new-pass-1', $user->fresh()->password))->toBeTrue()
        ->and(Cache::get('password_reset_verified:'.$user->id))->toBeNull();
});

it('refuses a password reset when no code was verified', function () {
    $user = recoveryUser();

    Livewire::test(CantLogin::class)
        ->set('identifier', $user->email)
        ->call('sendVerificationCode')
        ->set('password', 'hijacked-pass-1')
        ->set('password_confirmation', 'hijacked-pass-1')
        ->call('resetPassword')
        ->assertHasErrors('verification_code')
        ->assertSet('step', 'verify_code');

    expect(Hash::check('original-pass-1', $user->fresh()->password))->toBeTrue();
});

it('refuses a password reset before any account was chosen', function () {
    Livewire::test(CantLogin::class)
        ->set('password', 'hijacked-pass-1')
        ->set('password_confirmation', 'hijacked-pass-1')
        ->call('resetPassword')
        ->assertHasErrors('verification_code')
        ->assertSet('step', 'identifier');
});

it('rejects a wrong code and throws it away after five guesses', function () {
    $user = recoveryUser();

    $component = Livewire::test(CantLogin::class)
        ->set('identifier', $user->email)
        ->call('sendVerificationCode');

    $code = recoveryCode($user);
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $attempt) {
        $component->set('verification_code', $wrong)
            ->call('verifyCode')
            ->assertHasErrors('verification_code')
            ->assertSet('step', 'verify_code');
    }

    $component->set('verification_code', $code)
        ->call('verifyCode')
        ->assertHasErrors('verification_code')
        ->assertSet('step', 'verify_code');
});

it('keeps the code, the account and the step away from the browser', function () {
    $user = recoveryUser();
    $other = recoveryUser();

    $component = Livewire::test(CantLogin::class)
        ->set('identifier', $user->email)
        ->call('sendVerificationCode');

    $code = recoveryCode($user);

    expect(property_exists(CantLogin::class, 'generated_code'))->toBeFalse()
        ->and($component->html())->not->toContain($code)
        ->and(json_encode($component->snapshot))->not->toContain($code);

    expect(fn () => $component->set('step', 'reset_password'))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect(fn () => $component->set('user', ['id' => $other->id]))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});
