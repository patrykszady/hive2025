<?php

use App\Livewire\Entry\Registration;
use App\Mail\EmailVerificationCode;
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

function regFlowStep(string $step)
{
    return Livewire::withQueryParams(['step' => $step])->test(Registration::class);
}

function regFlowVerifyPhone(string $formatted): void
{
    regFlowStep('phone')
        ->set('user_cell', $formatted)
        ->call('confirmUserCellAction')
        ->assertRedirect(route('registration', ['step' => 'verify-phone']));

    regFlowStep('verify-phone')
        ->set('cell_verification_code', session('registration_codes.phone'))
        ->call('cell_verification_code_confirm')
        ->assertHasNoErrors()
        ->assertRedirect(route('registration', ['step' => 'email']));
}

it('registers a brand-new person from phone to password', function () {
    regFlowVerifyPhone('(224) 555-0611');

    regFlowStep('email')
        ->set('email', 'new.person@example.test')
        ->assertHasNoErrors()
        ->call('user_email')
        ->assertRedirect(route('registration', ['step' => 'verify-email']));

    Mail::assertSent(EmailVerificationCode::class, fn ($mail) => $mail->hasTo('new.person@example.test'));

    regFlowStep('verify-email')
        ->set('email_verification_code', session('registration_codes.email'))
        ->call('email_verification_code_confirm')
        ->assertHasNoErrors()
        ->assertRedirect(route('registration', ['step' => 'complete']));

    regFlowStep('complete')
        ->set('first_name', 'Nova')
        ->set('last_name', 'Person')
        ->set('use_password', true)
        ->set('password', 'secret-pass-1')
        ->set('password_confirmation', 'secret-pass-1')
        ->call('register_user')
        ->assertHasNoErrors();

    $user = User::query()->where('email', 'new.person@example.test')->firstOrFail();

    expect($user->first_name)->toBe('Nova')
        ->and($user->last_name)->toBe('Person')
        ->and($user->cell_phone)->toBe('2245550611')
        ->and($user->registration)->toBe(['registered' => true])
        ->and(Hash::check('secret-pass-1', $user->password))->toBeTrue();

    $this->assertAuthenticatedAs($user);
});

it('keeps the typed email across page loads and refuses a code for a different address', function () {
    regFlowVerifyPhone('(224) 555-0622');

    regFlowStep('email')
        ->set('email', 'first@example.test')
        ->call('user_email');

    $code = session('registration_codes.email');

    $page = regFlowStep('verify-email')->assertSet('email', 'first@example.test');

    // Changing the typed address after the code went out does not move the
    // verification to the new address.
    $page->set('email', 'someone-else@example.test')
        ->set('email_verification_code', $code)
        ->call('email_verification_code_confirm')
        ->assertHasNoErrors();

    expect(session('registration_verified_email'))->toBe('first@example.test');
});

it('will not create an account whose email was never verified', function () {
    regFlowVerifyPhone('(224) 555-0633');

    regFlowStep('email')
        ->set('email', 'unverified@example.test')
        ->call('user_email');

    // Skip the code: jump the page to the last step in the browser.
    regFlowStep('email')
        ->set('first_name', 'Skip')
        ->set('last_name', 'Ahead')
        ->set('use_password', true)
        ->set('password', 'secret-pass-1')
        ->set('password_confirmation', 'secret-pass-1')
        ->call('register_user')
        ->assertHasErrors('email');

    expect(User::query()->where('email', 'unverified@example.test')->exists())->toBeFalse();
    $this->assertGuest();
});

it('finishes an invited account with its existing email and names', function () {
    $invited = User::factory()->create([
        'first_name' => 'Ivy',
        'last_name' => 'Invited',
        'email' => 'ivy@example.test',
        'cell_phone' => '(224) 555-0644',
        'registration' => ['phone_code_sent' => true],
    ]);
    $usersBefore = User::query()->count();

    regFlowVerifyPhone('(224) 555-0644');

    regFlowStep('email')
        ->assertSee('We found this email associated with your phone number.')
        ->call('user_email')
        ->assertHasNoErrors();

    Mail::assertSent(EmailVerificationCode::class, fn ($mail) => $mail->hasTo('ivy@example.test'));

    regFlowStep('verify-email')
        ->set('email_verification_code', session('registration_codes.email'))
        ->call('email_verification_code_confirm')
        ->assertHasNoErrors();

    regFlowStep('complete')
        ->set('use_password', true)
        ->set('password', 'secret-pass-1')
        ->set('password_confirmation', 'secret-pass-1')
        ->call('register_user')
        ->assertHasNoErrors();

    $invited->refresh();

    expect($invited->first_name)->toBe('Ivy')
        ->and($invited->email)->toBe('ivy@example.test')
        ->and($invited->registration)->toBe(['registered' => true])
        ->and(User::query()->count())->toBe($usersBefore);

    $this->assertAuthenticatedAs($invited);
});
