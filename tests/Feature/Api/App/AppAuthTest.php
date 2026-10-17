<?php

use App\Livewire\Auth\OneTimeCodeLogin;
use App\Mail\EmailVerificationCode;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * GS Construction is vendor id 1 — the Hive Contractors app's sign-in
 * (primary_vendor_id === 1) depends on that literal id, so every allowed
 * fixture here pins it explicitly rather than hoping factory order lands
 * there.
 */
function appTeamUser(array $overrides = []): User
{
    $vendor = Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1]);

    $user = new User();
    $user->forceFill(array_merge([
        'first_name' => 'Patryk',
        'last_name' => 'Crew',
        'email' => 'app-team-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
    ], $overrides));
    $user->save();

    return $user;
}

/** A user on some OTHER vendor — may hold a Hive login but not this app. */
function appOutsiderUser(): User
{
    $vendor = Vendor::factory()->create();

    $user = new User();
    $user->forceFill([
        'first_name' => 'Other',
        'last_name' => 'Company',
        'email' => 'app-outsider-'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->save();

    expect((int) $user->primary_vendor_id)->not->toBe(1);

    return $user;
}

function appMailedCode(User $user): string
{
    $code = Cache::get('otp_login_code:'.$user->email);
    expect($code)->toMatch('/^\d{6}$/');

    return $code;
}

beforeEach(function () {
    Mail::fake();
});

it('mails a code to an allowed team member and signs them in', function () {
    $user = appTeamUser();

    $this->postJson('/api/app/v1/login/code', ['email' => $user->email])
        ->assertStatus(202)
        ->assertExactJson(['sent' => true]);

    Mail::assertSent(EmailVerificationCode::class);
    $code = appMailedCode($user);

    $response = $this->postJson('/api/app/v1/login', [
        'email' => $user->email,
        'code' => $code,
        'device_name' => "Patryk's iPhone",
    ])->assertOk();

    $response->assertJsonStructure(['token', 'user' => ['id', 'first_name', 'last_name', 'email', 'cell_phone']]);
    expect($response->json('user.id'))->toBe($user->id);

    $token = $response->json('token');
    $this->withToken($token)
        ->getJson('/api/app/v1/me')
        ->assertOk()
        ->assertJsonPath('user.id', $user->id);

    expect($user->tokens()->count())->toBe(1);
    expect($user->tokens()->first()->abilities)->toBe(['app']);
    expect($user->tokens()->first()->name)->toBe("Patryk's iPhone");

    // The code is single-use.
    expect(Cache::get('otp_login_code:'.$user->email))->toBeNull();
});

it('202s without mailing anything for a user outside GS Construction', function () {
    $user = appOutsiderUser();

    $this->postJson('/api/app/v1/login/code', ['email' => $user->email])
        ->assertStatus(202)
        ->assertExactJson(['sent' => true]);

    Mail::assertNothingSent();
    expect(Cache::get('otp_login_code:'.$user->email))->toBeNull();
});

it('202s without mailing anything for an email with no account', function () {
    $this->postJson('/api/app/v1/login/code', ['email' => 'nobody-'.uniqid().'@example.test'])
        ->assertStatus(202)
        ->assertExactJson(['sent' => true]);

    Mail::assertNothingSent();
});

it('respects the resend cooldown instead of mailing a second code', function () {
    $user = appTeamUser();

    $this->postJson('/api/app/v1/login/code', ['email' => $user->email])->assertStatus(202);
    $this->postJson('/api/app/v1/login/code', ['email' => $user->email])->assertStatus(202);

    Mail::assertSentCount(1);
});

it('rejects a wrong code with a 422', function () {
    $user = appTeamUser();
    $this->postJson('/api/app/v1/login/code', ['email' => $user->email]);
    $code = appMailedCode($user);
    $wrong = $code === '000000' ? '111111' : '000000';

    $this->postJson('/api/app/v1/login', [
        'email' => $user->email,
        'code' => $wrong,
        'device_name' => 'Test Device',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['code' => 'Invalid verification code.']);

    expect($user->tokens()->count())->toBe(0);
});

it('rejects a code that was never sent (expired) with a 422', function () {
    $user = appTeamUser();

    $this->postJson('/api/app/v1/login', [
        'email' => $user->email,
        'code' => '123456',
        'device_name' => 'Test Device',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['code' => 'That code has expired. Please request a new one.']);
});

it('throws the code away after five wrong guesses', function () {
    $user = appTeamUser();
    $this->postJson('/api/app/v1/login/code', ['email' => $user->email]);
    $code = appMailedCode($user);
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/app/v1/login', [
            'email' => $user->email,
            'code' => $wrong,
            'device_name' => 'Test Device',
        ]);
    }

    $this->postJson('/api/app/v1/login', [
        'email' => $user->email,
        'code' => $code,
        'device_name' => 'Test Device',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['code' => 'That code has expired. Please request a new one.']);
});

it('refuses to log in a disallowed user even with a correct, still-cached code', function () {
    $user = appOutsiderUser();
    // A code could only land here from a cache write outside this app's own
    // send-code endpoint (which never mails one for a disallowed user) —
    // seeded directly to prove the login endpoint itself still refuses.
    Cache::put('otp_login_code:'.$user->email, '654321', 600);

    $this->postJson('/api/app/v1/login', [
        'email' => $user->email,
        'code' => '654321',
        'device_name' => 'Test Device',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    expect($user->tokens()->count())->toBe(0);
    // Checked before the code, so a disallowed user's guess never burns an
    // attempt against it.
    expect(Cache::get('otp_login_code:'.$user->email))->toBe('654321');
});

it('rejects an app-ability token on authenticated routes once its owner is no longer allowed', function () {
    $user = appOutsiderUser();
    Sanctum::actingAs($user, ['app']);

    $this->getJson('/api/app/v1/me')->assertStatus(403);
});

it('rejects a token without the app ability', function () {
    $user = appTeamUser();
    Sanctum::actingAs($user, ['some-other-ability']);

    $this->getJson('/api/app/v1/me')->assertStatus(403);
});

it('needs a token at all', function () {
    $this->getJson('/api/app/v1/me')->assertUnauthorized();
});

it('logout revokes only the current token', function () {
    $user = appTeamUser();
    $token = $user->createToken('Device A', ['app']);
    $otherToken = $user->createToken('Device B', ['app']);

    $this->withToken($token->plainTextToken)
        ->postJson('/api/app/v1/logout')
        ->assertOk()
        ->assertExactJson(['logged_out' => true]);

    expect($user->tokens()->count())->toBe(1);
    expect($user->tokens()->first()->id)->toBe($otherToken->accessToken->id);

    // Sanctum's "sanctum" guard memoizes the resolved user for the lifetime
    // of the (shared, within-test) Application container — without this, the
    // second request below would see the logout call's cached resolution
    // rather than re-checking the now-deleted token.
    auth()->forgetGuards();

    $this->withToken($token->plainTextToken)
        ->getJson('/api/app/v1/me')
        ->assertUnauthorized();
});

it('still signs in through the web OneTimeCodeLogin component via the same service', function () {
    $user = appTeamUser();

    $component = Livewire::test(OneTimeCodeLogin::class)
        ->set('email', $user->email)
        ->call('sendCode')
        ->assertSet('step', 'verify_code');

    $component->set('verification_code', appMailedCode($user))
        ->call('verifyCode')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($user);
});
