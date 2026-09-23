<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * `nylas:webhooks --ensure` converges the subscription unattended: the
 * existing webhook gains any trigger the controller has learned (event.updated
 * for Meets moved on the calendar) without losing the ones it has.
 */
beforeEach(function () {
    config([
        'nylas.api_uri' => 'https://api.us.nylas.com',
        'nylas.api_key' => 'test-api-key',
        'nylas.webhook_secret' => 'pinned-secret',
    ]);

    Http::preventStrayRequests();
});

/**
 * @param  array<int, string>  $triggers
 * @return array<string, mixed>
 */
function ensureWebhookListing(array $triggers, string $status = 'active'): array
{
    return ['data' => [[
        'id' => 'wh_1',
        'status' => $status,
        'trigger_types' => $triggers,
        'webhook_url' => route('webhooks.nylas'),
    ]]];
}

it('adds event.updated to the existing webhook and keeps its other triggers', function () {
    Http::fake([
        'https://api.us.nylas.com/v3/webhooks' => Http::response(ensureWebhookListing(['message.created', 'message.opened'])),
        'https://api.us.nylas.com/v3/webhooks/wh_1' => Http::response(['data' => ['id' => 'wh_1']]),
    ]);

    $this->artisan('nylas:webhooks', ['--ensure' => true])
        ->expectsOutputToContain('Webhook triggers added: event.updated')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/v3/webhooks/wh_1')
        && $request->data() === ['trigger_types' => ['message.created', 'message.opened', 'event.updated']]);
});

it('changes nothing when the webhook already has every trigger', function () {
    Http::fake([
        'https://api.us.nylas.com/v3/webhooks' => Http::response(ensureWebhookListing(['message.created', 'event.updated'])),
    ]);

    $this->artisan('nylas:webhooks', ['--ensure' => true])
        ->expectsOutputToContain('nothing to do')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
});

it('fails the run when Nylas refuses the new trigger', function () {
    Http::fake([
        'https://api.us.nylas.com/v3/webhooks' => Http::response(ensureWebhookListing(['message.created'])),
        'https://api.us.nylas.com/v3/webhooks/wh_1' => Http::response(['error' => 'nope'], 400),
    ]);

    $this->artisan('nylas:webhooks', ['--ensure' => true])
        ->expectsOutputToContain('Adding triggers failed: HTTP 400')
        ->assertFailed();
});

it('says so when Nylas has the webhook failing', function () {
    Http::fake([
        'https://api.us.nylas.com/v3/webhooks' => Http::response(ensureWebhookListing(['message.created', 'event.updated'], 'failing')),
    ]);

    $this->artisan('nylas:webhooks', ['--ensure' => true])
        ->expectsOutputToContain('Webhook status is failing')
        ->assertSuccessful();
});

it('registers a new webhook with both triggers', function () {
    Http::fake([
        'https://api.us.nylas.com/v3/webhooks' => Http::sequence()
            ->push(['data' => []])
            ->push(['data' => ['id' => 'wh_new', 'webhook_secret' => 'new-secret']]),
    ]);

    $this->artisan('nylas:webhooks', ['--ensure' => true])->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request['trigger_types'] === ['message.created', 'event.updated']);
});
