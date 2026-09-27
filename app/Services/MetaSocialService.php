<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Trimmed port of dawnsellshomes.com's/jpeterson-design's
 * app/Services/MetaSocialService.php — this app has no social-posting
 * pipeline of any kind (no listings, no project photos), so only the half
 * ss.systems' Platforms card actually needs is ported: the admin OAuth
 * dance (getOAuthUrl/exchangeCodeAndStore/disconnect) and the credential
 * reads the status/test-connection endpoints use. Deliberately NOT
 * ported: publishToInstagram()/publishToFacebook() and their container/
 * permalink helpers — there is nothing on this app that would ever call
 * them.
 *
 * Like dawnsellshomes.com, the resulting OAuth grant is stored as
 * individual encrypted `platform_settings` rows (App\Models\PlatformSetting)
 * rather than a dedicated `oauth_tokens` row — this app's oauth_tokens
 * table exists only for Google Business Profile (see App\Models\OAuthToken's
 * docblock), and introducing a second storage mechanism just for Meta would
 * duplicate what this app already has for exactly this need.
 *
 * NEVER call exchangeCodeAndStore() against live Meta outside phpunit +
 * Http::fake — it hits the real Graph API once app_id/app_secret are
 * configured.
 */
class MetaSocialService
{
    protected const GRAPH_BASE = 'https://graph.facebook.com/v25.0';

    /** platform_settings keys the OAuth grant is spread across — see this class's docblock. */
    protected const SETTING_ACCESS_TOKEN = 'meta.access_token';

    protected const SETTING_REFRESH_TOKEN = 'meta.refresh_token';

    protected const SETTING_PAGE_ID = 'meta.page_id';

    protected const SETTING_PAGE_NAME = 'meta.page_name';

    protected const SETTING_IG_ID = 'meta.ig_id';

    protected const SETTING_IG_USERNAME = 'meta.ig_username';

    protected const SETTING_GRANTED_BY = 'meta.granted_by_email';

    /**
     * Scopes requested for a Facebook Page + linked Instagram Business
     * account. The Meta app must be through App Review for these scopes in
     * production.
     */
    protected const OAUTH_SCOPES = [
        'pages_show_list',
        'pages_read_engagement',
        'business_management',
        'instagram_basic',
    ];

    protected ?array $lastError = null;

    /**
     * @return array{token: ?string, page_id: ?string, ig_id: ?string, page_name: ?string, ig_username: ?string, source: 'oauth'|'env'|null}
     */
    public function getCredentials(): array
    {
        $token = PlatformSetting::get(self::SETTING_ACCESS_TOKEN);
        if ($token !== null) {
            return [
                'token' => $token,
                'page_id' => PlatformSetting::get(self::SETTING_PAGE_ID),
                'ig_id' => PlatformSetting::get(self::SETTING_IG_ID),
                'page_name' => PlatformSetting::get(self::SETTING_PAGE_NAME),
                'ig_username' => PlatformSetting::get(self::SETTING_IG_USERNAME),
                'source' => 'oauth',
            ];
        }

        $cfg = config('services.meta');
        $envToken = trim((string) ($cfg['page_access_token'] ?? ''));
        if ($envToken === '') {
            return ['token' => null, 'page_id' => null, 'ig_id' => null, 'page_name' => null, 'ig_username' => null, 'source' => null];
        }

        return [
            'token' => $envToken,
            'page_id' => trim((string) ($cfg['facebook_page_id'] ?? '')) ?: null,
            'ig_id' => trim((string) ($cfg['instagram_account_id'] ?? '')) ?: null,
            'page_name' => null,
            'ig_username' => null,
            'source' => 'env',
        ];
    }

    public function isConnected(): bool
    {
        return $this->getCredentials()['token'] !== null;
    }

    public function isPublishingEnabled(): bool
    {
        return (bool) (config('services.meta.enabled') ?? false);
    }

    public function isInstagramConfigured(): bool
    {
        return $this->isPublishingEnabled() && $this->isInstagramConnected();
    }

    public function isInstagramConnected(): bool
    {
        $c = $this->getCredentials();

        return $c['token'] !== null && ! empty($c['ig_id']);
    }

    public function isFacebookConfigured(): bool
    {
        return $this->isPublishingEnabled() && $this->isFacebookConnected();
    }

    public function isFacebookConnected(): bool
    {
        $c = $this->getCredentials();

        return $c['token'] !== null && ! empty($c['page_id']);
    }

    public function getLastError(): ?array
    {
        return $this->lastError;
    }

    /* ------------------------------------------------------------------ */
    /*  Admin OAuth (Facebook Login flow) */
    /* ------------------------------------------------------------------ */

    public function getOAuthUrl(string $redirectUri, ?string $state = null): string
    {
        $params = http_build_query([
            'client_id' => config('services.meta.app_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(',', self::OAUTH_SCOPES),
            'state' => $state ?: bin2hex(random_bytes(8)),
            'auth_type' => 'rerequest',
        ]);

        return 'https://www.facebook.com/v25.0/dialog/oauth?'.$params;
    }

    /**
     * Exchange the OAuth code for a long-lived Page Access Token,
     * auto-discover the FB Page + linked Instagram Business account, and
     * persist everything into platform_settings. Verbatim port of
     * dawnsellshomes.com's MetaSocialService::exchangeCodeAndStore().
     *
     * @return array{success: bool, error?: string, page_name?: string, ig_username?: ?string}
     */
    public function exchangeCodeAndStore(string $code, string $redirectUri): array
    {
        $appId = config('services.meta.app_id');
        $appSecret = config('services.meta.app_secret');
        if (! $appId || ! $appSecret) {
            return ['success' => false, 'error' => 'META_APP_ID / META_APP_SECRET not configured in .env'];
        }

        $tokenResp = Http::timeout(20)->get(self::GRAPH_BASE.'/oauth/access_token', [
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);
        if (! $tokenResp->successful()) {
            return ['success' => false, 'error' => 'Code exchange failed: '.($tokenResp->json('error.message') ?? $tokenResp->body())];
        }
        $shortLivedUserToken = $tokenResp->json('access_token');

        $longResp = Http::timeout(20)->get(self::GRAPH_BASE.'/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'fb_exchange_token' => $shortLivedUserToken,
        ]);
        if (! $longResp->successful()) {
            return ['success' => false, 'error' => 'Long-lived token exchange failed: '.($longResp->json('error.message') ?? $longResp->body())];
        }
        $longLivedUserToken = $longResp->json('access_token');

        $pagesResp = Http::timeout(20)->get(self::GRAPH_BASE.'/me/accounts', [
            'fields' => 'id,name,access_token,instagram_business_account{id,username}',
            'access_token' => $longLivedUserToken,
        ]);
        if (! $pagesResp->successful()) {
            return ['success' => false, 'error' => 'Failed to list pages: '.($pagesResp->json('error.message') ?? $pagesResp->body())];
        }

        $pages = $pagesResp->json('data', []);
        if (empty($pages)) {
            return ['success' => false, 'error' => 'This account does not manage any Facebook Pages. Make sure the page admin authorises the app.'];
        }

        $page = null;
        foreach ($pages as $p) {
            if (! empty($p['instagram_business_account']['id'] ?? null)) {
                $page = $p;
                break;
            }
        }
        $page = $page ?? $pages[0];

        $meResp = Http::timeout(10)->get(self::GRAPH_BASE.'/me', [
            'fields' => 'id,name,email',
            'access_token' => $longLivedUserToken,
        ]);
        $email = $meResp->successful() ? ($meResp->json('email') ?? $meResp->json('name')) : null;

        PlatformSetting::put(self::SETTING_ACCESS_TOKEN, $page['access_token']);
        PlatformSetting::put(self::SETTING_REFRESH_TOKEN, $longLivedUserToken);
        PlatformSetting::put(self::SETTING_PAGE_ID, $page['id']);
        PlatformSetting::put(self::SETTING_PAGE_NAME, $page['name'] ?? null);
        PlatformSetting::put(self::SETTING_IG_ID, $page['instagram_business_account']['id'] ?? null);
        PlatformSetting::put(self::SETTING_IG_USERNAME, $page['instagram_business_account']['username'] ?? null);
        PlatformSetting::put(self::SETTING_GRANTED_BY, $email);

        return [
            'success' => true,
            'page_name' => $page['name'] ?? '',
            'ig_username' => $page['instagram_business_account']['username'] ?? null,
        ];
    }

    public function disconnect(): void
    {
        foreach ([
            self::SETTING_ACCESS_TOKEN, self::SETTING_REFRESH_TOKEN, self::SETTING_PAGE_ID,
            self::SETTING_PAGE_NAME, self::SETTING_IG_ID, self::SETTING_IG_USERNAME, self::SETTING_GRANTED_BY,
        ] as $key) {
            PlatformSetting::put($key, null);
        }
    }

    /** Who last completed the Meta OAuth connect, for the Platforms card. */
    public function grantedByEmail(): ?string
    {
        return PlatformSetting::get(self::SETTING_GRANTED_BY);
    }

    /**
     * When the current grant was first stored / last refreshed — the
     * access-token setting row's own created_at, since there is no
     * dedicated column for it the way oauth_tokens' granted_at is for GBP.
     */
    public function grantedAt(): ?Carbon
    {
        return PlatformSetting::where('key', self::SETTING_ACCESS_TOKEN)->first()?->created_at;
    }

    public function credentialsUpdatedAt(): ?Carbon
    {
        return PlatformSetting::where('key', self::SETTING_ACCESS_TOKEN)->first()?->updated_at;
    }
}
