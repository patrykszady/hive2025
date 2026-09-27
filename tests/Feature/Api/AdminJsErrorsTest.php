<?php

use App\Models\JsErrorState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * App\Http\Controllers\Api\Admin\V1\JsErrorController — the JS Errors board
 * ss-systems' JsErrorsBoard/PlatformJsErrors read. This app has no
 * dedicated ingest table; every fixture below is a `jserr` site_events row,
 * exactly how SsSystems\Platform\Pulse\BeaconScript's beacon writes one,
 * grouped live by App\Support\JsErrorGroups.
 */
beforeEach(function () {
    config(['services.admin_api.token' => 'test-admin-api-token']);
});

function seedJsError(string $message, string $source, array $overrides = []): void
{
    DB::table('site_events')->insert(array_merge([
        'event' => 'jserr',
        'path' => '/listings/123',
        'meta' => json_encode(['m' => $message, 's' => $source, 'b' => 'chrome', 'mob' => 0]),
        'vhash' => 'v-'.uniqid('', true),
        'city' => null,
        'mobile' => false,
        'created_at' => now(),
    ], $overrides));
}

it('requires a bearer token', function () {
    $this->getJson('/api/admin/v1/js-errors')->assertStatus(401);
});

it('groups by message and source and defaults to open', function () {
    seedJsError('TypeError: boom', '/js/app.js:42');
    seedJsError('TypeError: boom', '/js/app.js:42');
    seedJsError('TypeError: boom', '/js/app.js:42');
    seedJsError('ReferenceError: x is not defined', '/js/other.js:7');

    $data = $this->getJson('/api/admin/v1/js-errors', adminApiHeaders())
        ->assertOk()
        ->json('data');

    expect($data)->toHaveCount(2);

    $boom = collect($data)->firstWhere('message', 'TypeError: boom');
    expect($boom['occurrences'])->toBe(3);
    expect($boom['source'])->toBe('/js/app.js');
    expect($boom['line'])->toBe(42);
    expect($boom['kind'])->toBe('error');
    expect($boom['is_resolved'])->toBeFalse();
    expect($boom['browsers'])->toBe(['chrome']);
    expect($boom['pages'])->toBe(['/listings/123']);
    expect($boom['first_seen_at'])->not->toBeNull();
    expect($boom['last_seen_at'])->not->toBeNull();
});

it('classifies promise rejections by kind', function () {
    seedJsError('promise: something rejected', '');

    $data = $this->getJson('/api/admin/v1/js-errors', adminApiHeaders())->json('data');

    expect($data[0]['kind'])->toBe('promise');
    expect($data[0]['source'])->toBeNull();
    expect($data[0]['line'])->toBeNull();
});

it('filters the index by status and kind', function () {
    seedJsError('Resolved error', '/a.js:1');
    seedJsError('promise: open one', '');

    $resolvedId = collect($this->getJson('/api/admin/v1/js-errors?status=all', adminApiHeaders())->json('data'))
        ->firstWhere('message', 'Resolved error')['id'];
    $this->patchJson("/api/admin/v1/js-errors/{$resolvedId}/resolve", [], adminApiHeaders())->assertOk();

    $resolved = $this->getJson('/api/admin/v1/js-errors?status=resolved', adminApiHeaders())->json('data');
    expect($resolved)->toHaveCount(1);
    expect($resolved[0]['message'])->toBe('Resolved error');

    $promises = $this->getJson('/api/admin/v1/js-errors?status=all&kind=promise', adminApiHeaders())->json('data');
    expect($promises)->toHaveCount(1);
    expect($promises[0]['message'])->toBe('promise: open one');
});

it('summarizes open, occurrences, last_24h and resolved counts', function () {
    seedJsError('Error A', '/a.js:1', ['created_at' => now()]);
    seedJsError('Error A', '/a.js:1', ['created_at' => now()]);
    seedJsError('Error B', '/b.js:2', ['created_at' => now()]);
    seedJsError('Old error', '/c.js:3', ['created_at' => now()->subDays(5)]);

    $bId = collect($this->getJson('/api/admin/v1/js-errors?status=all', adminApiHeaders())->json('data'))
        ->firstWhere('message', 'Error B')['id'];
    $this->patchJson("/api/admin/v1/js-errors/{$bId}/resolve", [], adminApiHeaders())->assertOk();

    $data = $this->getJson('/api/admin/v1/js-errors/summary', adminApiHeaders())
        ->assertOk()
        ->json('data');

    // Open: Error A (2 occurrences) + Old error (1) = 2 open groups, 3 open occurrences.
    expect($data['open'])->toBe(2);
    expect($data['occurrences'])->toBe(3);
    expect($data['resolved'])->toBe(1);
    // last_24h counts every group (open or resolved) seen in the last day: A + B, not the 5-day-old one.
    expect($data['last_24h'])->toBe(2);
});

it('toggles resolved_at via resolve and unresolve', function () {
    seedJsError('Toggle me', '/a.js:1');
    $id = $this->getJson('/api/admin/v1/js-errors', adminApiHeaders())->json('data.0.id');

    $this->patchJson("/api/admin/v1/js-errors/{$id}/resolve", [], adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.is_resolved', true);

    $this->patchJson("/api/admin/v1/js-errors/{$id}/unresolve", [], adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.is_resolved', false);
});

it('reopens a group when a fresh occurrence lands after it was resolved', function () {
    seedJsError('Reopens', '/a.js:1', ['created_at' => now()->subMinute()]);
    $id = $this->getJson('/api/admin/v1/js-errors', adminApiHeaders())->json('data.0.id');

    $this->patchJson("/api/admin/v1/js-errors/{$id}/resolve", [], adminApiHeaders())
        ->assertJsonPath('data.is_resolved', true);

    seedJsError('Reopens', '/a.js:1', ['created_at' => now()->addMinute()]);

    $data = $this->getJson('/api/admin/v1/js-errors?status=all', adminApiHeaders())->json('data');
    $reopened = collect($data)->firstWhere('message', 'Reopens');
    expect($reopened['is_resolved'])->toBeFalse();
    expect($reopened['occurrences'])->toBe(2);
});

it('resolves every open group at once', function () {
    seedJsError('One', '/a.js:1');
    seedJsError('Two', '/b.js:2');

    $this->patchJson('/api/admin/v1/js-errors/resolve-all', [], adminApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.resolved_count', 2);

    $open = $this->getJson('/api/admin/v1/js-errors', adminApiHeaders())->json('data');
    expect($open)->toHaveCount(0);
});

it('hides a deleted group until it recurs', function () {
    seedJsError('Gone', '/a.js:1');
    $id = $this->getJson('/api/admin/v1/js-errors', adminApiHeaders())->json('data.0.id');

    $this->deleteJson("/api/admin/v1/js-errors/{$id}", [], adminApiHeaders())->assertNoContent();

    expect($this->getJson('/api/admin/v1/js-errors?status=all', adminApiHeaders())->json('data'))->toBe([]);

    seedJsError('Gone', '/a.js:1', ['created_at' => now()->addMinute()]);
    $fresh = $this->getJson('/api/admin/v1/js-errors', adminApiHeaders())->json('data');
    expect($fresh)->toHaveCount(1);
    expect($fresh[0]['occurrences'])->toBe(1);

    $this->assertDatabaseCount('js_error_states', 1);
});

it('404s resolving an unknown id', function () {
    $this->patchJson('/api/admin/v1/js-errors/999/resolve', [], adminApiHeaders())
        ->assertStatus(404);
});

it('never surfaces non-jserr events', function () {
    DB::table('site_events')->insert([
        'event' => 'page',
        'path' => '/',
        'meta' => null,
        'vhash' => 'v-'.uniqid('', true),
        'city' => null,
        'mobile' => false,
        'created_at' => now(),
    ]);

    $this->getJson('/api/admin/v1/js-errors?status=all', adminApiHeaders())
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->assertDatabaseCount('js_error_states', 0);
});

it('creates js_error_state rows lazily', function () {
    $this->assertDatabaseCount('js_error_states', 0);

    seedJsError('Lazy', '/a.js:1');
    $this->getJson('/api/admin/v1/js-errors', adminApiHeaders())->assertOk();

    $this->assertDatabaseCount('js_error_states', 1);
    expect(JsErrorState::first())->not->toBeNull();
});
