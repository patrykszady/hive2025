<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// The bookmarklet page: the platform admin's no-install way to hand an EWCCV
// search session to Hive (2026-10-12), and the minter it loads on ewccv.com.

beforeEach(function () {
    config(['services.ewccv.bridge_token' => 'bridge-secret', 'app.url' => 'https://hive.contractors']);
});

it('shows the admin a bookmarklet that carries the bridge token and loads the minter from this server', function () {
    $admin = User::find(1);
    expect($admin)->not->toBeNull('the seeded platform admin');

    $page = $this->actingAs($admin)->get('/ewccv/bridge')->assertOk();

    $page->assertSee('Send EWCCV session to Hive')
        ->assertSee('javascript:(function(){var T=&quot;bridge-secret&quot;', false)
        ->assertSee('https://hive.contractors/ewccv/minter.js', false)
        ->assertSee('/api/ewccv/session', false)
        ->assertSee('hive-ewccv-bridge', false);
});

it('keeps the page from everyone but the platform admin', function () {
    $this->get('/ewccv/bridge')->assertRedirect();

    $other = User::factory()->create();
    $this->actingAs($other)->get('/ewccv/bridge')->assertForbidden();
});

it('serves the extension minter as plain JavaScript', function () {
    $this->get('/ewccv/minter.js')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
        ->assertSee('grecaptcha.enterprise.execute', false)
        ->assertSee("source: 'hive-ewccv-bridge'", false);
});

it('answers the preflight a bookmarklet on ewccv.com sends before the hand-off', function () {
    $this->withHeaders([
        'Origin' => 'https://www.ewccv.com',
        'Access-Control-Request-Method' => 'POST',
        'Access-Control-Request-Headers' => 'authorization,content-type',
    ])->options('/api/ewccv/session')
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertHeader('Access-Control-Allow-Methods', 'POST');
});
