<?php

namespace App\Services;

use App\Models\OAuthToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Trimmed port of dawnsellshomes.com's/jpeterson-design's
 * app/Services/GoogleBusinessProfileService.php — only what the central
 * admin's Business Profile card and its listing/review import need: OAuth
 * URL building, code exchange + token storage, status reads, disconnect,
 * account/location discovery, the reviews pass-through, and a read-only
 * media listing (for GET platforms/gbp/media — see PlatformsController's
 * docblock for why POST/DELETE/the ledger are refused instead of ported:
 * hive.contractors has no project photos, so nothing here may ever upload
 * to or delete from a listing).
 */
class GoogleBusinessProfileService
{
    protected const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    protected const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    /** Reviews and media live on the v4 media API, same host gsc/jpeterson-design read them from. */
    protected const MEDIA_API_BASE = 'https://mybusiness.googleapis.com/v4';

    /** Account and location discovery live on their own hosts, not the v4 media API. */
    protected const ACCOUNT_API_BASE = 'https://mybusinessaccountmanagement.googleapis.com/v1';

    protected const INFO_API_BASE = 'https://mybusinessbusinessinformation.googleapis.com/v1';

    /** The scope every Business Profile call needs; identity scopes alone are not enough. */
    public const BUSINESS_SCOPE = 'https://www.googleapis.com/auth/business.manage';

    protected const USERINFO_ENDPOINT = 'https://www.googleapis.com/oauth2/v3/userinfo';

    protected const SCOPES = 'https://www.googleapis.com/auth/business.manage openid email';

    public const PROVIDER = 'google_business_profile';

    protected ?array $lastError = null;

    public function isConfigured(): bool
    {
        return $this->hasRefreshToken();
    }

    /** Check if a refresh token exists in DB or .env. */
    public function hasRefreshToken(): bool
    {
        return (bool) $this->getRefreshToken();
    }

    /** Get the refresh token from DB first, then .env fallback. */
    public function getRefreshToken(): ?string
    {
        $dbToken = OAuthToken::forProvider(self::PROVIDER);
        if ($dbToken?->refresh_token) {
            return $dbToken->refresh_token;
        }

        $envToken = config('services.google.business_profile.refresh_token');

        return $envToken ?: null;
    }

    /** Get the DB token record (if any). */
    public function getStoredToken(): ?OAuthToken
    {
        return OAuthToken::forProvider(self::PROVIDER);
    }

    public function getLastError(): ?array
    {
        return $this->lastError;
    }

    /**
     * Generate the Google OAuth consent URL for the admin to authorise.
     * $state carries this app's signed SsSystems\Platform\Auth\OAuthState value — see
     * that class's docblock for why this app needs one (no local admin
     * session to protect the callback with).
     */
    public function getOAuthUrl(string $redirectUri, ?string $state = null): string
    {
        $params = http_build_query(array_filter([
            'client_id' => config('services.google.business_profile.client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline',
            'prompt' => 'consent', // force new refresh token every time
            'state' => $state,
        ]));

        return self::AUTH_ENDPOINT.'?'.$params;
    }

    /**
     * Exchange an OAuth authorisation code for tokens and persist them.
     *
     * @return array{success: bool, error?: string}
     */
    public function exchangeCodeAndStore(string $code, string $redirectUri): array
    {
        $response = Http::asForm()->timeout(20)->post(self::TOKEN_ENDPOINT, [
            'client_id' => config('services.google.business_profile.client_id'),
            'client_secret' => config('services.google.business_profile.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);

        if (! $response->successful()) {
            $error = $response->json();
            $msg = $error['error_description'] ?? $response->body();
            Log::error('GBP: OAuth code exchange failed', ['body' => $response->body()]);

            return ['success' => false, 'error' => $msg];
        }

        $data = $response->json();
        $refreshToken = $data['refresh_token'] ?? null;
        $accessToken = $data['access_token'] ?? null;
        $expiresIn = (int) ($data['expires_in'] ?? 3600);

        if (! $refreshToken) {
            return ['success' => false, 'error' => 'No refresh token returned. Try again with prompt=consent.'];
        }

        $email = null;
        if ($accessToken) {
            try {
                $userInfo = Http::withToken($accessToken)->get(self::USERINFO_ENDPOINT)->json();
                $email = $userInfo['email'] ?? null;
            } catch (\Exception) {
                // non-critical
            }
        }

        OAuthToken::storeTokens(
            provider: self::PROVIDER,
            refreshToken: $refreshToken,
            accessToken: $accessToken,
            expiresIn: $expiresIn,
            email: $email,
            // What Google GRANTED, not what we asked for — a consent screen
            // can come back with fewer scopes than requested.
            scopes: array_values(array_filter(explode(' ', (string) ($data['scope'] ?? self::SCOPES)))),
        );

        Cache::forget('google_business_profile_access_token');

        Log::info('GBP: OAuth tokens stored via web flow', ['email' => $email]);

        return ['success' => true];
    }

    /** Disconnect: remove stored tokens. */
    public function disconnect(): void
    {
        OAuthToken::where('provider', self::PROVIDER)->delete();
        Cache::forget('google_business_profile_access_token');
        Log::info('GBP: Disconnected (tokens removed)');
    }

    /** Cache -> valid DB access token -> refresh via the token endpoint. */
    protected function getAccessToken(): ?string
    {
        $cacheKey = 'google_business_profile_access_token';
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $dbToken = OAuthToken::forProvider(self::PROVIDER);
        if ($dbToken?->hasValidAccessToken()) {
            Cache::put($cacheKey, $dbToken->access_token, $dbToken->access_token_expires_at);

            return $dbToken->access_token;
        }

        $refreshToken = $this->getRefreshToken();
        if (! $refreshToken) {
            $this->lastError = [
                'message' => 'No refresh token available (DB or .env)',
                'reauthorization_required' => true,
            ];

            return null;
        }

        $response = Http::asForm()->timeout(20)->post(self::TOKEN_ENDPOINT, [
            'client_id' => config('services.google.business_profile.client_id'),
            'client_secret' => config('services.google.business_profile.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            $errorPayload = $response->json() ?: [];
            $this->lastError = [
                'message' => 'Token refresh failed',
                'status' => $response->status(),
                'error' => $errorPayload['error'] ?? null,
                'error_description' => $errorPayload['error_description'] ?? null,
            ];
            Log::warning('GBP: Access token refresh failed', $this->lastError);

            return null;
        }

        $data = $response->json();
        $accessToken = $data['access_token'] ?? null;
        $expiresIn = (int) ($data['expires_in'] ?? 3600);

        if ($accessToken) {
            Cache::put($cacheKey, $accessToken, now()->addSeconds(max(60, $expiresIn - 120)));
        }

        if (! empty($data['scope']) && $dbToken) {
            $granted = array_values(array_filter(explode(' ', (string) $data['scope'])));

            if ($granted !== [] && $granted !== (array) $dbToken->scopes) {
                $dbToken->forceFill(['scopes' => $granted])->save();
            }
        }

        return $accessToken;
    }

    /**
     * One listing's reviews, a page at a time, for the central admin's
     * review import. Google pages at 50; nextPageToken continues. The
     * import itself lives in ss.systems — this is a pass-through with this
     * app's grant.
     *
     * @return array{reviews: array, totalReviewCount: int, averageRating: float, nextPageToken: ?string}|null
     */
    public function fetchReviewsFor(string $accountId, string $locationId, ?string $pageToken = null, int $pageSize = 50): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            $this->lastError ??= ['message' => 'No Google authorization on file'];

            return null;
        }

        $params = ['pageSize' => $pageSize];
        if ($pageToken) {
            $params['pageToken'] = $pageToken;
        }

        $response = Http::withToken($accessToken)
            ->timeout(30)
            ->get(self::MEDIA_API_BASE."/accounts/{$accountId}/locations/{$locationId}/reviews", $params);

        if (! $response->successful()) {
            $this->lastError = ['message' => 'Fetch reviews failed', 'status' => $response->status(), 'body' => $response->body()];
            Log::warning('GBP: Failed to fetch reviews', ['status' => $response->status(), 'location_id' => $locationId]);

            return null;
        }

        $this->lastError = null;
        $data = $response->json();

        return [
            'reviews' => $data['reviews'] ?? [],
            'totalReviewCount' => (int) ($data['totalReviewCount'] ?? 0),
            'averageRating' => (float) ($data['averageRating'] ?? 0),
            'nextPageToken' => $data['nextPageToken'] ?? null,
        ];
    }

    /**
     * Every media item Google currently holds for one listing, a page at a
     * time (GET platforms/gbp/media — a read-only pass-through; this app
     * has nothing that uploads or deletes, see this class's docblock).
     *
     * @return array<int, array{name: string, source_url: ?string, google_url: ?string, category: ?string, create_time: ?string}>|null
     */
    public function listMediaFor(string $accountId, string $locationId): ?array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            $this->lastError ??= ['message' => 'No Google authorization on file'];

            return null;
        }

        $items = [];
        $pageToken = null;

        do {
            $response = Http::withToken($accessToken)
                ->timeout(30)
                ->get(self::MEDIA_API_BASE."/accounts/{$accountId}/locations/{$locationId}/media", array_filter([
                    'pageSize' => 100,
                    'pageToken' => $pageToken,
                ]));

            if (! $response->successful()) {
                $this->lastError = ['message' => 'List media failed', 'status' => $response->status(), 'body' => $response->body()];
                Log::warning('GBP: Failed to list media', ['status' => $response->status(), 'location_id' => $locationId]);

                return null;
            }

            $data = $response->json();

            foreach ($data['mediaItems'] ?? [] as $item) {
                $items[] = [
                    'name' => $item['name'] ?? '',
                    'source_url' => $item['sourceUrl'] ?? null,
                    'google_url' => $item['googleUrl'] ?? null,
                    'category' => $item['locationAssociation']['category'] ?? null,
                    'create_time' => $item['createTime'] ?? null,
                ];
            }

            $pageToken = $data['nextPageToken'] ?? null;
        } while ($pageToken);

        $this->lastError = null;

        return $items;
    }

    /**
     * The Business Profile accounts this authorisation can see. Returns []
     * and sets lastError when the call fails — most often 403
     * ACCESS_TOKEN_SCOPE_INSUFFICIENT, which means the consent did not
     * include business.manage (see hasBusinessScope).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAccounts(): array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            $this->lastError ??= ['message' => 'Failed to obtain access token'];

            return [];
        }

        $response = Http::withToken($accessToken)->timeout(20)->get(self::ACCOUNT_API_BASE.'/accounts');

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'List accounts failed',
                'status' => $response->status(),
                'body' => $response->body(),
            ];
            Log::warning('GBP: Failed to list accounts', ['status' => $response->status()]);

            return [];
        }

        $this->lastError = null;

        return $response->json('accounts') ?? [];
    }

    /**
     * The listings under one account. $accountId may be a bare id or the
     * "accounts/123" resource name Google returns.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listLocations(string $accountId): array
    {
        $accessToken = $this->getAccessToken();

        if (! $accessToken) {
            $this->lastError ??= ['message' => 'Failed to obtain access token'];

            return [];
        }

        $accountId = str_replace('accounts/', '', $accountId);

        $response = Http::withToken($accessToken)->timeout(20)
            ->get(self::INFO_API_BASE."/accounts/{$accountId}/locations", [
                // metadata carries the listing's public Maps link and place id.
                'readMask' => 'name,title,storeCode,websiteUri,storefrontAddress,metadata',
                'pageSize' => 100,
            ]);

        if (! $response->successful()) {
            $this->lastError = [
                'message' => 'List locations failed',
                'status' => $response->status(),
                'body' => $response->body(),
                'account_id' => $accountId,
            ];
            Log::warning('GBP: Failed to list locations', ['status' => $response->status()]);

            return [];
        }

        $this->lastError = null;

        return $response->json('locations') ?? [];
    }

    /** The scopes recorded against the stored authorisation. */
    public function grantedScopes(): array
    {
        return (array) (OAuthToken::forProvider(self::PROVIDER)?->scopes ?? []);
    }

    /**
     * Whether the stored authorisation actually carries business.manage.
     * Without it the connection signs the user in and nothing else: every
     * listing/review/media call returns 403.
     */
    public function hasBusinessScope(): bool
    {
        return in_array(self::BUSINESS_SCOPE, $this->grantedScopes(), true);
    }
}
