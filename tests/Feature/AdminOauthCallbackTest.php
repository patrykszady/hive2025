<?php

use App\Models\OAuthToken;
use App\Services\GoogleBusinessProfileService;
use SsSystems\Platform\Auth\OAuthState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * /admin-oauth/{provider}/callback (routes/web.php) — a sibling path to
 * /admin/{path?}, registered above it so proxy route order never matters.
 * Ported from dawnsellshomes.com's/jpeterson-design's identical route and
 * tests.
 */
it('stores the token and redirects connected on a successful exchange', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response([
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_in' => 3600,
            'scope' => GoogleBusinessProfileService::BUSINESS_SCOPE,
        ], 200),
        'www.googleapis.com/oauth2/v3/userinfo' => Http::response(['email' => 'owner@example.com'], 200),
    ]);

    $state = OAuthState::make('gbp');

    $this->get('/admin-oauth/gbp/callback?'.http_build_query(['code' => 'abc123', 'state' => $state]))
        ->assertRedirect('/admin/hive/platforms?connected=gbp');

    $token = OAuthToken::forProvider(GoogleBusinessProfileService::PROVIDER);
    expect($token)->not->toBeNull();
    expect($token->refresh_token)->toBe('refresh-token');
    expect($token->granted_by_email)->toBe('owner@example.com');
});

it('redirects with an error and exchanges nothing on an invalid or expired state', function () {
    $this->get('/admin-oauth/gbp/callback?'.http_build_query(['code' => 'abc123', 'state' => 'garbage']))
        ->assertRedirect('/admin/hive/platforms?error='.urlencode('Sign-in link expired or was invalid. Try connecting again.'));

    expect(OAuthToken::forProvider(GoogleBusinessProfileService::PROVIDER))->toBeNull();
});

it('redirects with googles own reason on a cancelled consent screen', function () {
    $state = OAuthState::make('gbp');

    $this->get('/admin-oauth/gbp/callback?'.http_build_query(['state' => $state, 'error' => 'access_denied']))
        ->assertRedirect('/admin/hive/platforms?error='.urlencode('access_denied'));
});

it('redirects with googles error on a failed exchange', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Bad code'], 400),
    ]);

    $state = OAuthState::make('gbp');

    $this->get('/admin-oauth/gbp/callback?'.http_build_query(['code' => 'bad', 'state' => $state]))
        ->assertRedirect('/admin/hive/platforms?error='.urlencode('OAuth failed: Bad code'));
});

it('only recognises gbp and meta as providers', function () {
    $this->get('/admin-oauth/gsc/callback?state=x')->assertNotFound();
    $this->get('/admin-oauth/yelp/callback?state=x')->assertNotFound();
    $this->get('/admin-oauth/meta/callback?state=x')
        ->assertRedirect('/admin/hive/platforms?error='.urlencode('Sign-in link expired or was invalid. Try connecting again.'));
});

it('is never swallowed by the admin proxy', function () {
    $state = OAuthState::make('gbp');

    $this->get('/admin-oauth/gbp/callback?'.http_build_query(['state' => $state]))
        ->assertRedirect('/admin/hive/platforms?error='.urlencode('Authorization cancelled or failed — no code returned.'));
});
