<?php

use App\Models\OAuthToken;
use SsSystems\Platform\Auth\OAuthState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use SsSystems\Platform\Google\BusinessProfile\Client;

uses(RefreshDatabase::class);

/**
 * /admin-oauth/{provider}/callback (routes/web.php) — a sibling path to
 * /admin/{path?}, registered above it so proxy route order never matters.
 * Since kit 0.14.0 the handling is the kit's Google\Http\OAuthCallback and
 * the Business Profile exchange goes through the ONE shared Google sign-in
 * client (services.google.oauth). Never calls Google: every exchange is
 * faked, and anything unfaked fails the test.
 */
beforeEach(function () {
    config([
        'services.google.oauth.client_id' => '31627704418-shared.apps.googleusercontent.com',
        'services.google.oauth.client_secret' => 'shared-secret',
    ]);
    Http::preventStrayRequests();
});

it('stores the token and redirects connected on a successful exchange', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response([
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_in' => 3600,
            'scope' => Client::BUSINESS_SCOPE,
        ], 200),
        'www.googleapis.com/oauth2/v3/userinfo' => Http::response(['email' => 'owner@example.com'], 200),
    ]);

    $state = OAuthState::make('gbp');

    $this->get('/admin-oauth/gbp/callback?'.http_build_query(['code' => 'abc123', 'state' => $state]))
        ->assertRedirect('/admin/hive/platforms?connected=gbp');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://oauth2.googleapis.com/token'
        && $request['client_id'] === '31627704418-shared.apps.googleusercontent.com'
        && $request['redirect_uri'] === route('admin-oauth.callback', ['provider' => 'gbp']));

    $token = OAuthToken::forProvider(Client::PROVIDER);
    expect($token)->not->toBeNull();
    expect($token->refresh_token)->toBe('refresh-token');
    expect($token->granted_by_email)->toBe('owner@example.com');
    expect($token->scopes)->toBe([Client::BUSINESS_SCOPE]);
    // The grant remembers which client issued it, so a later client change
    // is detected instead of reading "Connected" over a dead grant.
    expect($token->metadata['oauth_client_id'] ?? null)->toBe('31627704418-shared.apps.googleusercontent.com');
});

it('redirects with an error and exchanges nothing on an invalid or expired state', function () {
    Http::fake();

    $this->get('/admin-oauth/gbp/callback?'.http_build_query(['code' => 'abc123', 'state' => 'garbage']))
        ->assertRedirect('/admin/hive/platforms?error='.urlencode('Sign-in link expired or was invalid. Try connecting again.'));

    expect(OAuthToken::forProvider(Client::PROVIDER))->toBeNull();
    Http::assertNothingSent();
});

it('treats a crafted array state as an expired link, never an error page', function () {
    Http::fake();

    $this->get('/admin-oauth/gbp/callback?state[]=x&code[]=y')
        ->assertRedirect('/admin/hive/platforms?error='.urlencode('Sign-in link expired or was invalid. Try connecting again.'));

    Http::assertNothingSent();
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

    expect(OAuthToken::forProvider(Client::PROVIDER))->toBeNull();
});

it('refuses calmly, without asking google, when the server has no sign-in client', function () {
    config([
        'services.google.oauth.client_id' => null,
        'services.google.oauth.client_secret' => null,
    ]);
    Http::fake();

    $state = OAuthState::make('gbp');

    $response = $this->get('/admin-oauth/gbp/callback?'.http_build_query(['code' => 'abc123', 'state' => $state]));

    $response->assertRedirect();
    expect(urldecode((string) $response->headers->get('Location')))->toContain('Google sign-in is not set up on the server yet.');
    expect(OAuthToken::forProvider(Client::PROVIDER))->toBeNull();
    Http::assertNothingSent();
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
