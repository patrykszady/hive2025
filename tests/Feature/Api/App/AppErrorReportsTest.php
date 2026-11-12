<?php

/**
 * JavaScript errors the Hive Contractors app caught on the phone: logged in
 * full to app-errors and put on the JS Errors board as `jserr` events
 * (App\Services\Diagnostics\AppErrorReports).
 */

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Vendor::query()->find(1) ?? Vendor::factory()->create(['id' => 1, 'business_name' => 'GS Construction']);
    $this->member = User::factory()->create(['first_name' => 'Patryk', 'primary_vendor_id' => 1]);
});

function appError(array $attributes = []): array
{
    return array_merge([
        'at' => now()->toIso8601String(),
        'kind' => 'error',
        'fatal' => false,
        'message' => "Cannot read properties of undefined (reading 'name')",
        'stack' => "TypeError: Cannot read properties of undefined\n    at UserScreen (users/[id].tsx:42)",
        'screen' => '/users/1',
        'build' => '1.0.0 (7)',
    ], $attributes);
}

it('logs each error in full and puts it on the JS Errors board', function () {
    Sanctum::actingAs($this->member, ['app']);
    $channel = Mockery::spy();
    Log::shouldReceive('channel')->with('app-errors')->andReturn($channel);

    $this->withHeader('User-Agent', 'HiveContractors/7 CFNetwork/3860.700.1 Darwin/25.6.0')
        ->postJson('/api/app/v1/diagnostics/errors', ['errors' => [appError(), appError(['kind' => 'promise', 'message' => 'Network request failed', 'stack' => null])]])
        ->assertCreated()
        ->assertJson(['stored' => 2]);

    $channel->shouldHaveReceived('error')->twice();
    $channel->shouldHaveReceived('error')->with("Cannot read properties of undefined (reading 'name')", Mockery::on(fn (array $context) => $context['user_id'] === $this->member->id
        && $context['screen'] === '/users/1' && $context['build'] === '1.0.0 (7)' && str_contains($context['stack'], 'UserScreen')));

});

it('puts the errors on the JS Errors board as the hive-app browser', function () {
    Sanctum::actingAs($this->member, ['app']);

    $this->withHeader('User-Agent', 'HiveContractors/7 CFNetwork/3860.700.1 Darwin/25.6.0')
        ->postJson('/api/app/v1/diagnostics/errors', ['errors' => [appError(['kind' => 'promise', 'message' => 'Network request failed'])]])
        ->assertCreated();

    $row = DB::table('site_events')->where('event', 'jserr')->sole();

    expect(json_decode($row->meta, true))->toBe(['m' => 'promise: Network request failed', 's' => 'app /users/1', 'b' => 'hive-app', 'mob' => 1])
        ->and($row->path)->toBe('/users/1');
});

it('rejects a malformed batch', function (array $payload) {
    Sanctum::actingAs($this->member, ['app']);

    $this->postJson('/api/app/v1/diagnostics/errors', $payload)->assertUnprocessable();
})->with([
    'no errors' => [['errors' => []]],
    'unknown kind' => [['errors' => [appError(['kind' => 'crash'])]]],
    'message too long' => [['errors' => [appError(['message' => str_repeat('x', 501)])]]],
]);

it('needs a signed-in app user', function () {
    $this->postJson('/api/app/v1/diagnostics/errors', ['errors' => [appError()]])->assertUnauthorized();
});
