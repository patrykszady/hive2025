<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * ss.systems' central log viewer reads this app's logs server-to-server with
 * `LOG_VIEWER_HUB_TOKEN` (config log-viewer.hub_token), checked in
 * AppServiceProvider's LogViewer::auth callback beside — never instead of —
 * the production token and the allowed-email branch (2026-09-27). A blank
 * configured hub token must never match a blank bearer.
 */
it('accepts the central admin\'s hub token', function () {
    config(['log-viewer.hosts.production.auth.token' => 'a-real-token', 'log-viewer.hub_token' => 'hub-secret']);

    $this->withToken('hub-secret')
        ->getJson('/log-viewer/api/files')
        ->assertOk();
});

it('refuses the wrong hub token', function () {
    config(['log-viewer.hosts.production.auth.token' => 'a-real-token', 'log-viewer.hub_token' => 'hub-secret']);

    $this->withToken('not-the-hub-token')
        ->getJson('/log-viewer/api/files')
        ->assertForbidden();
});

it('never lets a blank hub token match a missing bearer', function () {
    config(['log-viewer.hosts.production.auth.token' => null, 'log-viewer.hub_token' => '']);

    $this->getJson('/log-viewer/api/files')->assertForbidden();
});

it('keeps the production token working beside the hub token', function () {
    config(['log-viewer.hosts.production.auth.token' => 'a-real-token', 'log-viewer.hub_token' => 'hub-secret']);

    $this->withToken('a-real-token')
        ->getJson('/log-viewer/api/files')
        ->assertOk();
});
