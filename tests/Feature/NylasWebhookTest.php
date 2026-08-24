<?php

use App\Jobs\ProcessNylasInboundMessage;
use App\Models\EmailTracking;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * The Nylas message.created webhook: reply capture as a push. The payload is
 * a doorbell — grant + message id, signature-checked — and the job re-fetches
 * the message before running the same pipeline the sweep uses.
 */
beforeEach(function () {
    config([
        'nylas.webhook_secret' => 'test-secret',
        'nylas.crew_leads.grant_ids' => ['grant-watched'],
        'nylas.api_key' => 'test-api-key',
    ]);
});

function signedPost(array $payload, ?string $secret = 'test-secret')
{
    $body = json_encode($payload);

    return test()->call('POST', '/webhooks/nylas', [], [], [], array_filter([
        'HTTP_X-Nylas-Signature' => $secret === null ? null : hash_hmac('sha256', $body, $secret),
        'CONTENT_TYPE' => 'application/json',
    ]), $body);
}

it('echoes the challenge on the creation handshake', function () {
    $this->get('/webhooks/nylas?challenge=abc123')
        ->assertOk()
        ->assertSee('abc123', escape: false);
});

it('refuses an unsigned or missigned payload', function () {
    Queue::fake();

    signedPost(['type' => 'message.created'], null)->assertStatus(401);
    signedPost(['type' => 'message.created'], 'wrong-secret')->assertStatus(401);

    Queue::assertNothingPushed();
});

it('dispatches the job for a watched grant', function () {
    Queue::fake();

    signedPost([
        'type' => 'message.created',
        'data' => ['object' => ['grant_id' => 'grant-watched', 'id' => 'msg-1']],
    ])->assertOk();

    Queue::assertPushed(ProcessNylasInboundMessage::class, fn ($job) => $job->grantId === 'grant-watched' && $job->messageId === 'msg-1');
});

it('acknowledges but ignores unwatched grants and other triggers', function () {
    Queue::fake();

    signedPost([
        'type' => 'message.created',
        'data' => ['object' => ['grant_id' => 'grant-company-email', 'id' => 'msg-2']],
    ])->assertOk();

    signedPost(['type' => 'grant.expired', 'data' => ['object' => ['grant_id' => 'grant-watched']]])
        ->assertOk();

    Queue::assertNothingPushed();
});

it('fetches the message and files the replied badge end to end', function () {
    $vendor = Vendor::factory()->create();

    $sent = EmailTracking::withoutGlobalScopes()->create([
        'belongs_to_vendor_id' => $vendor->id,
        'message_id' => 'provider-msg-1',
        'email_template_name' => 'estimate',
        'event_type' => 'sent',
        'recipient_emails' => ['homeowner@gmail.com'],
        'metadata' => ['rfc_message_id' => 'estimate-9@hive', 'subject' => 'Your estimate'],
        'event_at' => now()->subDay(),
    ]);

    Http::fake([
        'https://api.us.nylas.com/v3/grants/grant-watched/messages/msg-9*' => Http::response(['data' => [
            'id' => 'msg-9',
            'from' => [['email' => 'homeowner@gmail.com', 'name' => 'Home Owner']],
            'to' => [['email' => 'greg@gs.construction']],
            'subject' => 'Re: Your estimate',
            'thread_id' => 'nylas-thread-77',
            'date' => now()->timestamp,
            'headers' => [['name' => 'In-Reply-To', 'value' => '<estimate-9@hive>']],
            'body' => '<p>Perfect, go ahead.</p>',
        ]]),
        'https://api.us.nylas.com/v3/grants/grant-watched' => Http::response(['data' => ['email' => 'greg@gs.construction']]),
    ]);

    (new ProcessNylasInboundMessage('grant-watched', 'msg-9'))->handle(app(App\Services\CrewLeadEmailService::class));

    $replied = EmailTracking::withoutGlobalScopes()->where('event_type', 'replied')->get();

    expect($replied)->toHaveCount(1)
        ->and($replied->first()->message_id)->toBe($sent->message_id)
        ->and($replied->first()->metadata['matched_via'])->toBe('rfc')
        ->and($replied->first()->metadata['mailbox'])->toBe('greg@gs.construction');
});

it('authenticates with the cached secret when the env one is absent', function () {
    Queue::fake();
    config(['nylas.webhook_secret' => '']);
    cache()->forever('nylas:webhook-secret', 'cached-secret');

    signedPost([
        'type' => 'message.created',
        'data' => ['object' => ['grant_id' => 'grant-watched', 'id' => 'msg-3']],
    ], 'cached-secret')->assertOk();

    Queue::assertPushed(ProcessNylasInboundMessage::class);

    cache()->forget('nylas:webhook-secret');
});

it('ensure registers when the webhook is missing and caches the secret', function () {
    cache()->forget('nylas:webhook-secret');
    config(['nylas.webhook_secret' => '']);

    Http::fake([
        'https://api.us.nylas.com/v3/webhooks' => Http::sequence()
            ->push(['data' => []]) // list: nothing registered
            ->push(['data' => ['id' => 'wh-1', 'webhook_secret' => 'fresh-secret']]), // create
    ]);

    $this->artisan('nylas:webhooks --ensure')->assertSuccessful();

    expect(cache()->get('nylas:webhook-secret'))->toBe('fresh-secret');
    Http::assertSentCount(2);

    cache()->forget('nylas:webhook-secret');
});

it('ensure rotates a lost secret instead of duplicating the webhook', function () {
    cache()->forget('nylas:webhook-secret');
    config(['nylas.webhook_secret' => '']);

    Http::fake([
        'https://api.us.nylas.com/v3/webhooks/rotate-secret/wh-1' => Http::response(
            ['data' => ['id' => 'wh-1', 'webhook_secret' => 'rotated-secret']]
        ),
        'https://api.us.nylas.com/v3/webhooks' => Http::response(['data' => [[
            'id' => 'wh-1',
            'webhook_url' => route('webhooks.nylas'),
            'trigger_types' => ['message.created'],
        ]]]),
    ]);

    $this->artisan('nylas:webhooks --ensure')->assertSuccessful();

    expect(cache()->get('nylas:webhook-secret'))->toBe('rotated-secret');

    cache()->forget('nylas:webhook-secret');
});

it('ensure is a no-op when the webhook exists and the secret is held', function () {
    cache()->forever('nylas:webhook-secret', 'held-secret');
    config(['nylas.webhook_secret' => '']);

    Http::fake([
        'https://api.us.nylas.com/v3/webhooks' => Http::response(['data' => [[
            'id' => 'wh-1',
            'webhook_url' => route('webhooks.nylas'),
            'trigger_types' => ['message.created'],
        ]]]),
    ]);

    $this->artisan('nylas:webhooks --ensure')->assertSuccessful();

    expect(cache()->get('nylas:webhook-secret'))->toBe('held-secret');
    Http::assertSentCount(1); // list only — no create, no rotate

    cache()->forget('nylas:webhook-secret');
});

it('does nothing for a message deleted before the job ran', function () {
    Http::fake([
        'https://api.us.nylas.com/v3/grants/grant-watched/messages/gone*' => Http::response(null, 404),
        'https://api.us.nylas.com/v3/grants/grant-watched' => Http::response(['data' => ['email' => 'greg@gs.construction']]),
    ]);

    (new ProcessNylasInboundMessage('grant-watched', 'gone'))->handle(app(App\Services\CrewLeadEmailService::class));

    expect(EmailTracking::withoutGlobalScopes()->where('event_type', 'replied')->count())->toBe(0);
});
