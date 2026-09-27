<?php

namespace App\Services;

use SsSystems\Platform\Social\Contracts\MetaCredentialStore;
use SsSystems\Platform\Social\MetaGraphClient;

/**
 * hive.contractors' Meta Graph API surface — a thin subclass of the kit's
 * `SsSystems\Platform\Social\MetaGraphClient` (2026-09-27; see
 * ss-platform-kit/docs/audit-2026-09-27/social-posting-and-automation.md
 * unit 9, and CONSOLIDATION-PLAN.md's Kit 0.13.0 entry). This app has no
 * social-posting pipeline of any kind (no listings, no project photos), so
 * it only ever uses the half the Platforms card needs — the admin OAuth
 * dance (getOAuthUrl/exchangeCodeAndStore/disconnect) and the credential
 * reads the status/test-connection endpoints use — all of which now live
 * entirely in the kit base class. `publishToInstagram()`/
 * `publishToFacebook()` are inherited but never called from anywhere in
 * this app (same as before the port: the pre-port class simply never
 * declared them).
 *
 * Credential storage: `MetaCredentialStore` is bound (AppServiceProvider)
 * to `SsSystems\Platform\Social\Adapters\PlatformSettingCredentialStore`
 * wrapping this app's own `App\Models\PlatformSetting` — individual
 * encrypted rows, one per field, exactly as before (this app's
 * `oauth_tokens` table exists only for Google Business Profile — see
 * `App\Models\OAuthToken`'s docblock — and introducing a second storage
 * mechanism just for Meta would duplicate what this app already has).
 *
 * NEVER call exchangeCodeAndStore() against live Meta outside phpunit +
 * Http::fake — it hits the real Graph API once app_id/app_secret are
 * configured.
 */
class MetaSocialService extends MetaGraphClient
{
    /**
     * Scopes requested for a Facebook Page + linked Instagram Business
     * account. This app never publishes (no `instagram_content_publish`,
     * unlike gsc/jpeterson's set) but does read engagement data (adds
     * `pages_read_engagement`, which they omit) — hive's OWN distinct
     * 4-scope set, not a drift from gsc/jpeterson's to reconcile (see
     * `MetaGraphClient::getOAuthUrl()`'s docblock). The Meta app must be
     * through App Review for these scopes in production.
     *
     * Public (not protected): `MetaGraphClient::getOAuthUrl()`/
     * `exchangeCodeAndStore()` take `$scopes` as a call-time argument and
     * are NOT re-declared here at a narrower arity — PHP's method-override
     * compatibility rules refuse a child signature with fewer parameters
     * than the parent's, defaults notwithstanding (see MetaGraphClient::
     * getOAuthUrl()'s docblock). Every call site passes this constant
     * explicitly instead.
     */
    public const OAUTH_SCOPES = [
        'pages_show_list',
        'pages_read_engagement',
        'business_management',
        'instagram_basic',
    ];

    public function __construct(MetaCredentialStore $credentials)
    {
        parent::__construct(
            $credentials,
            appId: config('services.meta.app_id'),
            appSecret: config('services.meta.app_secret'),
            publishingEnabled: (bool) (config('services.meta.enabled') ?? false),
        );
    }
}
