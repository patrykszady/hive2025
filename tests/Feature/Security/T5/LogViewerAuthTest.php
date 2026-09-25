<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * `bearerToken() === config(...)` used to let an unauthenticated request
 * with no Authorization header in at all whenever the configured token
 * resolved to null — a common state on a fresh/misconfigured environment,
 * since it happens whenever LOG_VIEWER_PRODUCTION_URL is unset.
 */
it('refuses an unauthenticated request when no log-viewer token is configured', function () {
    config(['log-viewer.hosts.production.auth.token' => null]);

    $this->getJson('/log-viewer/api/files')->assertForbidden();
});

it('refuses a request whose bearer token is empty even when a token is configured', function () {
    config(['log-viewer.hosts.production.auth.token' => 'a-real-token']);

    $this->getJson('/log-viewer/api/files')->assertForbidden();
});

it('accepts the correctly configured bearer token', function () {
    config(['log-viewer.hosts.production.auth.token' => 'a-real-token']);

    $this->withToken('a-real-token')
        ->getJson('/log-viewer/api/files')
        ->assertOk();
});

it('refuses the wrong bearer token', function () {
    config(['log-viewer.hosts.production.auth.token' => 'a-real-token']);

    $this->withToken('not-the-right-token')
        ->getJson('/log-viewer/api/files')
        ->assertForbidden();
});
