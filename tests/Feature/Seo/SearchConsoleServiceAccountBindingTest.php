<?php

use App\Models\SeoSyncRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use SsSystems\Platform\Seo\Google\ServiceAccountSearchConsoleClient;
use SsSystems\Platform\Seo\Inspection\Contracts\UrlInspector;
use SsSystems\Platform\Seo\SearchConsoleClient;
use SsSystems\Platform\Seo\SearchConsoleSyncClient;

uses(RefreshDatabase::class);

/**
 * Proves App\Providers\AppServiceProvider's real Search Console binding —
 * this app's own former App\Support\Google\ServiceAccountToken and
 * App\Services\GoogleSearchConsoleService are gone (kit/gsc-kit-client,
 * ported onto ss-systems/platform-kit's Google\ServiceAccountToken and
 * Seo\Google\ServiceAccountSearchConsoleClient — see
 * docs/SEARCH-CONSOLE-SERVICE-ACCOUNT.md in the kit). Every other test
 * that exercises Search Console behaviour keeps testing that behaviour
 * directly (GoogleSearchConsoleReadyAndAuthStandingTest/
 * GoogleSearchConsoleSubmitSitemapTest/ServiceAccountTokenCredentialFormsTest
 * are deleted, not renamed here — the kit's own
 * ServiceAccountSearchConsoleClientTest/ServiceAccountTokenTest cover every
 * one of those cases already, against the exact same class); this file's
 * only job is proving the container resolves the KIT class, built from
 * this app's OWN config keys (services.google.search_console_credentials /
 * services.google.search_console_property, i.e. GSC_CREDENTIALS/
 * GSC_PROPERTY), for all three contracts SearchConsoleClient,
 * SearchConsoleSyncClient and UrlInspector at once — with no separate
 * inspection adapter, unlike an OAuth-flavor site.
 */
it('resolves the kit client, built from this app\'s own config, for every Search Console contract', function () {
    config([
        'services.google.search_console_credentials' => null,
        'services.google.search_console_property' => 'sc-domain:hive.contractors',
    ]);

    $syncClient = app(SearchConsoleSyncClient::class);

    expect($syncClient)->toBeInstanceOf(ServiceAccountSearchConsoleClient::class)
        ->and(app(SearchConsoleClient::class))->toBe($syncClient)
        ->and(app(UrlInspector::class))->toBe($syncClient)
        ->and($syncClient->siteUrl())->toBe('sc-domain:hive.contractors')
        ->and($syncClient->isConfigured())->toBeFalse()
        ->and($syncClient->isReady())->toBeFalse();
});

it('syncs real Search Console data end to end through the container-resolved kit client', function () {
    $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($resource, $pem);

    config([
        'services.google.search_console_credentials' => json_encode([
            'type' => 'service_account',
            'client_email' => 'sa@hive.iam.gserviceaccount.com',
            'private_key' => $pem,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]),
        'services.google.search_console_property' => 'sc-domain:hive.contractors',
    ]);

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.fake', 'expires_in' => 3600]),
        'https://searchconsole.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::sequence()
            ->push(['rows' => [[
                'keys' => [now()->subDay()->toDateString(), 'kitchen remodel', 'https://hive.contractors/', 'usa', 'DESKTOP'],
                'clicks' => 2, 'impressions' => 30, 'ctr' => 0.067, 'position' => 4.2,
            ]]])
            ->push(['rows' => [[
                'keys' => [now()->subDay()->toDateString()],
                'clicks' => 2, 'impressions' => 30, 'ctr' => 0.067, 'position' => 4.2,
            ]]])
            ->push(['rows' => []]),
    ]);

    $this->artisan('seo:gsc-sync', ['--days' => 1])->assertExitCode(0);

    expect(SeoSyncRun::summary('search_console')['status'])->toBe('ok');

    // The JWT really was signed with this fake service account's own
    // identity — the request went through the real kit client, not a stub.
    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://oauth2.googleapis.com/token') {
            return false;
        }

        [, $claims] = explode('.', $request['assertion']);
        $claims = json_decode(base64_decode(strtr($claims, '-_', '+/')), true);

        return $claims['iss'] === 'sa@hive.iam.gserviceaccount.com'
            && $claims['scope'] === ServiceAccountSearchConsoleClient::SCOPE;
    });
});
