<?php

namespace Tests\Feature;

use App\Models\OAuthToken;
use App\Services\GoogleBusinessProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * getAccessToken() (2026-09-27) is now a full port of gsc's — see that
 * method's docblock. Before this, a refreshed access token lived only in
 * cache (never written back to the oauth_tokens row) and a rotated
 * refresh_token from Google was silently dropped; neither gap was covered
 * by a test anywhere in the estate, which is exactly how they went
 * unnoticed. These three tests pin the fix. NEVER calls a real Google
 * endpoint — every refresh goes through Http::fake().
 */
class GoogleBusinessProfileAccessTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'services.google.business_profile.client_id' => 'test-client-id',
            'services.google.business_profile.client_secret' => 'test-client-secret',
        ]);
    }

    public function test_a_refreshed_access_token_is_persisted_to_the_oauth_tokens_row(): void
    {
        $token = OAuthToken::create([
            'provider' => GoogleBusinessProfileService::PROVIDER,
            'refresh_token' => 'refresh-token-one',
            'access_token' => 'stale-access-token',
            'access_token_expires_at' => now()->subHour(), // already expired
            'scopes' => ['https://www.googleapis.com/auth/business.manage'],
        ]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'access_token' => 'fresh-access-token',
                'expires_in' => 3600,
            ]),
            'mybusiness.googleapis.com/*' => Http::response(['reviews' => [], 'totalReviewCount' => 0, 'averageRating' => 0]),
        ]);

        $service = app(GoogleBusinessProfileService::class);
        $service->fetchReviewsFor('accounts/123', 'locations/456');

        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token');

        $token->refresh();
        $this->assertSame('fresh-access-token', $token->access_token);
        $this->assertTrue($token->access_token_expires_at->isFuture());
    }

    public function test_a_rotated_refresh_token_is_persisted_to_the_oauth_tokens_row(): void
    {
        $token = OAuthToken::create([
            'provider' => GoogleBusinessProfileService::PROVIDER,
            'refresh_token' => 'refresh-token-old',
            'access_token' => null,
            'access_token_expires_at' => null,
            'scopes' => ['https://www.googleapis.com/auth/business.manage'],
        ]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'access_token' => 'fresh-access-token',
                'refresh_token' => 'refresh-token-rotated',
                'expires_in' => 3600,
            ]),
            'mybusiness.googleapis.com/*' => Http::response(['reviews' => [], 'totalReviewCount' => 0, 'averageRating' => 0]),
        ]);

        $service = app(GoogleBusinessProfileService::class);
        $service->fetchReviewsFor('accounts/123', 'locations/456');

        $token->refresh();
        $this->assertSame('refresh-token-rotated', $token->refresh_token);
    }

    public function test_invalid_grant_starts_a_cooldown_and_is_not_retried_within_it(): void
    {
        OAuthToken::create([
            'provider' => GoogleBusinessProfileService::PROVIDER,
            'refresh_token' => 'refresh-token-revoked',
            'access_token' => null,
            'access_token_expires_at' => null,
            'scopes' => ['https://www.googleapis.com/auth/business.manage'],
        ]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Token has been expired or revoked.',
            ], 400),
        ]);

        $service = app(GoogleBusinessProfileService::class);

        $first = $service->fetchReviewsFor('accounts/123', 'locations/456');
        $this->assertNull($first);
        $this->assertTrue((bool) ($service->getLastError()['reauthorization_required'] ?? false));

        // A second call, still within the 6-hour cooldown, must not hit
        // Google again — the refresh is already known to be dead.
        $second = $service->fetchReviewsFor('accounts/123', 'locations/456');
        $this->assertNull($second);

        Http::assertSentCount(1);
    }
}
