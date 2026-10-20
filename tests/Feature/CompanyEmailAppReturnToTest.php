<?php

use App\Models\CompanyEmail;
use App\Models\User;
use App\Models\Vendor;
use App\Services\NylasService;
use App\Support\AppReturnTo;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Covers the Hive Contractors app's mailbox connect/reconnect flow: the
 * app opens /company-email/login inside its in-app browser (via the signed
 * web-session hand-off) with a `return_to` of its own
 * "hivecontractors://…" deep link, and expects the round trip through
 * Nylas and back (CompanyEmailController::nylasAuthResponse()) to redirect
 * there once it finishes — see App\Support\AppReturnTo and
 * App\Rules\AppReturnToUrl. Every existing Nylas OAuth/CSRF behavior
 * (NylasOAuthStateTest) must keep working unchanged when return_to is
 * absent, which is every desktop/web visit today.
 */
function appReturnToVendorUser(): User
{
    $vendor = Vendor::factory()->create();

    $user = new User();
    $user->forceFill([
        'first_name' => 'Return',
        'last_name' => 'To',
        'email' => 'return-to-'.uniqid().'@example.test',
        'cell_phone' => (string) random_int(2000000000, 9999999999),
        'password' => null,
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return $user;
}

it('rejects an open-redirect return_to on the login route and accepts the app scheme', function () {
    $user = appReturnToVendorUser();

    foreach ([
        'https://evil.test/phish',
        'http://evil.test',
        '//evil.test',
        'hivecontractors.evil.test://x',
        'javascript:alert(1)',
        'hivecontractors://ok/path?x=hivecontractors://nested',
    ] as $badReturnTo) {
        // A plain GET's failed FormRequest validation redirects back with
        // the error flashed to the session (not a JSON 422) — the point
        // under test is that it's rejected and never reaches Nylas with an
        // attacker-controlled return_to in tow. (The fallback "back" target
        // with no referer is this same request's own URL, still on our own
        // host — not a hop to the attacker's URL.)
        $response = $this->actingAs($user)
            ->get('/company-email/login?return_to='.urlencode($badReturnTo));

        $response->assertSessionHasErrors(['return_to']);
        expect($response->headers->get('Location'))->toStartWith(config('app.url'));
    }

    $this->actingAs($user)
        ->get('/company-email/login?return_to='.urlencode('hivecontractors://manage/emails'))
        ->assertStatus(302); // redirected to Nylas, not a validation error
});

it('leaves return_to off entirely without any error, like every desktop visit today', function () {
    $user = appReturnToVendorUser();

    $this->actingAs($user)->get('/company-email/login')->assertStatus(302);
});

it('redirects a successful connect back to the app\'s own return_to, with the account saved', function () {
    $user = appReturnToVendorUser();

    $this->mock(NylasService::class, function ($mock) {
        $mock->shouldReceive('exchangeAuthCodeForToken')
            ->once()
            ->andReturn([
                'email' => 'inbox-'.uniqid().'@example.test',
                'grant_id' => 'grant-'.uniqid(),
            ]);
    });

    $token = 'session-token-for-return-to';
    $returnTo = 'hivecontractors://manage/emails';
    $state = base64_encode(json_encode(['csrf' => $token, 'popup' => true, 'return_to' => $returnTo]));

    $response = $this->actingAs($user)
        ->withSession(['_token' => $token])
        ->get('/company-email/auth-response?state='.urlencode($state).'&code=legit-code');

    $response->assertRedirect($returnTo.'?connected=1');
    expect(CompanyEmail::where('vendor_id', $user->vendor->id)->count())->toBe(1);
});

it('redirects a failed connect back to the app\'s own return_to instead of showing the popup error page', function () {
    $user = appReturnToVendorUser();

    $token = 'session-token-for-return-to-error';
    $returnTo = 'hivecontractors://manage/emails';
    $state = base64_encode(json_encode(['csrf' => $token, 'popup' => true, 'return_to' => $returnTo]));

    $response = $this->actingAs($user)
        ->withSession(['_token' => $token])
        ->get('/company-email/auth-response?state='.urlencode($state).'&error=access_denied');

    $response->assertRedirect($returnTo.'?connected=0');
    expect(CompanyEmail::count())->toBe(0);
});

it('ignores an attacker-supplied return_to inside the state blob (defense in depth beyond the CSRF check)', function () {
    $user = appReturnToVendorUser();

    $token = 'session-token-for-forged-return-to';
    // Even with a correct CSRF token, a malformed return_to must never be
    // honored — AppReturnTo::isValid() is re-checked at this endpoint too.
    $state = base64_encode(json_encode(['csrf' => $token, 'popup' => false, 'return_to' => 'https://evil.test/phish']));

    $response = $this->actingAs($user)
        ->withSession(['_token' => $token])
        ->get('/company-email/auth-response?state='.urlencode($state).'&error=access_denied');

    // Falls through to the ordinary (non-return_to, non-popup) behavior.
    $response->assertRedirect();
    expect($response->headers->get('Location'))->not->toContain('evil.test');
});

it('AppReturnTo only accepts the app\'s own scheme', function () {
    expect(AppReturnTo::isValid('hivecontractors://manage/emails'))->toBeTrue()
        ->and(AppReturnTo::isValid('hivecontractors://banks/5?return=1'))->toBeTrue()
        ->and(AppReturnTo::isValid('https://hive.contractors/banks/5'))->toBeFalse()
        ->and(AppReturnTo::isValid('//hivecontractors://x'))->toBeFalse()
        ->and(AppReturnTo::isValid('hivecontractors://x?u=https://evil.test'))->toBeFalse()
        ->and(AppReturnTo::isValid(null))->toBeFalse()
        ->and(AppReturnTo::isValid(''))->toBeFalse()
        ->and(AppReturnTo::isValid(123))->toBeFalse();
});
