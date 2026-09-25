<?php

use App\Models\CompanyEmail;
use App\Models\User;
use App\Models\Vendor;
use App\Services\NylasService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function sec5NylasVendorUser(): User
{
    $vendor = Vendor::factory()->create();

    $user = new User();
    $user->forceFill([
        'first_name' => 'Nylas',
        'last_name' => 'Owner',
        'email' => 'nylas-owner-'.uniqid().'@example.test',
        'cell_phone' => (string) random_int(2000000000, 9999999999),
        'password' => null,
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->save();
    $vendor->users()->attach($user->id, ['role_id' => 1]);

    return $user;
}

it('requires auth for the nylas oauth routes', function () {
    $this->get('/company-email/login')->assertRedirect(route('login'));
    $this->get('/company-email/auth-response')->assertRedirect(route('login'));
});

it('rejects a Nylas auth-response whose state does not carry this session\'s CSRF token', function () {
    $user = sec5NylasVendorUser();

    $badState = base64_encode(json_encode(['csrf' => 'attacker-controlled-token', 'popup' => false]));

    $this->actingAs($user)
        ->withSession(['_token' => 'the-real-session-token'])
        ->get('/company-email/auth-response?state='.urlencode($badState).'&code=attacker-code')
        ->assertRedirect();

    expect(CompanyEmail::count())->toBe(0);
});

it('rejects a Nylas auth-response with no state at all', function () {
    $user = sec5NylasVendorUser();

    $this->actingAs($user)
        ->withSession(['_token' => 'the-real-session-token'])
        ->get('/company-email/auth-response?code=attacker-code')
        ->assertRedirect();

    expect(CompanyEmail::count())->toBe(0);
});

it('accepts a state carrying this session\'s own CSRF token and saves the account', function () {
    $user = sec5NylasVendorUser();

    $this->mock(NylasService::class, function ($mock) {
        $mock->shouldReceive('exchangeAuthCodeForToken')
            ->once()
            ->andReturn([
                'email' => 'new-inbox-'.uniqid().'@example.test',
                'grant_id' => 'grant-'.uniqid(),
            ]);
    });

    $token = 'the-real-session-token';
    $state = base64_encode(json_encode(['csrf' => $token, 'popup' => false]));

    $this->actingAs($user)
        ->withSession(['_token' => $token])
        ->get('/company-email/auth-response?state='.urlencode($state).'&code=legit-code')
        ->assertRedirect(route('company_emails.index'));

    expect(CompanyEmail::where('vendor_id', $user->vendor->id)->count())->toBe(1);
});
