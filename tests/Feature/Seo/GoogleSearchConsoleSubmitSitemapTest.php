<?php

use App\Services\GoogleSearchConsoleService;
use Illuminate\Support\Facades\Http;

/**
 * submitSitemap() — new on this service (kit/search-console-contract):
 * this site had never wired direct sitemap submission before (no
 * seo:gsc-submit-sitemaps command exists here yet), but the kit's
 * SearchConsoleClient contract now requires every implementor to have it.
 * Ported verbatim from gs.construction's/jpeterson-design's identical
 * bodiless-PUT implementation.
 */
function servicAccountPrivateKeyPem(): string
{
    static $pem = null;
    if ($pem === null) {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $pem);
    }

    return $pem;
}

function configureServiceAccount(): void
{
    config([
        'services.google.search_console_credentials' => json_encode([
            'type' => 'service_account',
            'client_email' => 'sa@example.iam.gserviceaccount.com',
            'private_key' => servicAccountPrivateKeyPem(),
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]),
        'services.google.search_console_property' => 'sc-domain:hive.contractors',
    ]);

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'expires_in' => 3600]),
    ]);
}

it('sends a truly bodiless put', function () {
    configureServiceAccount();
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'expires_in' => 3600]),
        'https://searchconsole.googleapis.com/webmasters/v3/sites/*/sitemaps/*' => Http::response('', 204),
    ]);

    $ok = app(GoogleSearchConsoleService::class)->submitSitemap('sc-domain:hive.contractors', 'https://hive.contractors/sitemap.xml');

    expect($ok)->toBeTrue();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/sitemaps/')) {
            return false;
        }

        expect($request->method())->toBe('PUT');
        expect($request->body())->toBe('');

        return true;
    });
});

it('fails with a reason on a real 403', function () {
    configureServiceAccount();
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'expires_in' => 3600]),
        'https://searchconsole.googleapis.com/webmasters/v3/sites/*/sitemaps/*' => Http::response('nope', 403),
    ]);

    $service = app(GoogleSearchConsoleService::class);
    $ok = $service->submitSitemap('sc-domain:hive.contractors', 'https://hive.contractors/sitemap.xml');

    expect($ok)->toBeFalse();
    expect($service->getLastError()['message'])->toContain('must be added as a user');
    expect($service->isAuthStandingCondition($service->getLastError()))->toBeTrue();
});
