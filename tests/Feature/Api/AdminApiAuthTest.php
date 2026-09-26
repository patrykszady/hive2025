<?php

/**
 * Bearer-token guard for /api/admin/v1 — mirrors gsc's/dawnsellshomes'
 * AuthenticateAdminApi tests. One representative route (ping) stands in
 * for the whole group, since every route in it shares the same middleware.
 */
it('401s with no bearer token', function () {
    config(['services.admin_api.token' => 'test-admin-api-token']);

    $this->getJson('/api/admin/v1/ping')->assertUnauthorized();
});

it('401s with the wrong bearer token', function () {
    config(['services.admin_api.token' => 'test-admin-api-token']);

    $this->getJson('/api/admin/v1/ping', ['Authorization' => 'Bearer wrong'])
        ->assertUnauthorized();
});

it('503s when no token is configured on this server', function () {
    config(['services.admin_api.token' => '']);

    $this->getJson('/api/admin/v1/ping', ['Authorization' => 'Bearer anything'])
        ->assertStatus(503);
});

it('lets the right bearer token through', function () {
    config(['services.admin_api.token' => 'test-admin-api-token']);

    $this->getJson('/api/admin/v1/ping', ['Authorization' => 'Bearer test-admin-api-token'])
        ->assertOk();
});
