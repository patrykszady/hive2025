<?php

use App\Livewire\Auth\OneTimeCodeLogin;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
});

function otpLoginUser(): User
{
    $vendor = Vendor::factory()->create();
    $vendor->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();

    $user = new User();
    $user->forceFill([
        'first_name' => 'One',
        'last_name' => 'Time',
        'email' => 'otp-login-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return $user;
}

function otpMailedCode(User $user): string
{
    $code = Cache::get('otp_login_code:'.$user->email);
    expect($code)->toMatch('/^\d{6}$/');

    return $code;
}

it('signs in with the code that was mailed', function () {
    $user = otpLoginUser();

    $component = Livewire::test(OneTimeCodeLogin::class)
        ->set('email', $user->email)
        ->call('sendCode')
        ->assertSet('step', 'verify_code');

    $component->set('verification_code', otpMailedCode($user))
        ->call('verifyCode')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($user);
    expect(Cache::get('otp_login_code:'.$user->email))->toBeNull();
});

it('keeps the code on the server, out of the page and the snapshot', function () {
    $user = otpLoginUser();

    $component = Livewire::test(OneTimeCodeLogin::class)
        ->set('email', $user->email)
        ->call('sendCode');

    $code = otpMailedCode($user);

    expect(property_exists(OneTimeCodeLogin::class, 'generated_code'))->toBeFalse()
        ->and($component->html())->not->toContain($code)
        ->and(json_encode($component->snapshot))->not->toContain($code);
});

it('rejects a wrong code and stays signed out', function () {
    $user = otpLoginUser();

    $component = Livewire::test(OneTimeCodeLogin::class)
        ->set('email', $user->email)
        ->call('sendCode');

    $wrong = otpMailedCode($user) === '000000' ? '111111' : '000000';

    $component->set('verification_code', $wrong)
        ->call('verifyCode')
        ->assertHasErrors('verification_code');

    $this->assertGuest();
});

it('throws the code away after five wrong guesses', function () {
    $user = otpLoginUser();

    $component = Livewire::test(OneTimeCodeLogin::class)
        ->set('email', $user->email)
        ->call('sendCode');

    $code = otpMailedCode($user);
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $attempt) {
        $component->set('verification_code', $wrong)->call('verifyCode');
    }

    expect(Cache::get('otp_login_code:'.$user->email))->toBeNull();

    $component->set('verification_code', $code)
        ->call('verifyCode')
        ->assertHasErrors('verification_code');

    $this->assertGuest();
});

it('refuses to let the browser change the account or skip a step', function () {
    $user = otpLoginUser();
    $other = otpLoginUser();

    $component = Livewire::test(OneTimeCodeLogin::class)
        ->set('email', $user->email)
        ->call('sendCode');

    expect(fn () => $component->set('user', ['id' => $other->id]))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect(fn () => $component->set('step', 'request'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('cannot use a code mailed to one address to sign in as another', function () {
    $user = otpLoginUser();
    $victim = otpLoginUser();

    $component = Livewire::test(OneTimeCodeLogin::class)
        ->set('email', $user->email)
        ->call('sendCode');

    $component->set('email', $victim->email)
        ->set('verification_code', otpMailedCode($user))
        ->call('verifyCode')
        ->assertHasErrors('verification_code');

    $this->assertGuest();
});
