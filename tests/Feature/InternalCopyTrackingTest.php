<?php

use App\Jobs\SendLeadReplyJob;
use App\Models\CompanyEmail;
use App\Models\EmailTracking;
use App\Models\Lead;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * The office copy of a client email must ride its OWN message.
 *
 * Mailtrap injects one open pixel per message and attributes every load of it
 * to the original recipient. While the office copy was a same-envelope CC,
 * opening our own copy fired the client's pixel and the UI showed the message
 * as "opened" — the bug this file pins down from both directions: the send
 * shape, and the webhook's verdict on an office open.
 */
beforeEach(function () {
    config([
        'email_tracking.provider' => 'mailtrap',
        'email_tracking.mailtrap_webhook_token' => 'test-token',
        'email_tracking.mailtrap_mailer' => 'array',
        'mail.default' => 'array',
        // The dev-mail interceptor rewrites To and strips Cc/Bcc, which would
        // blind exactly the assertions this file exists to make. The array
        // transport never delivers, so unhooking it here is safe.
        'mail.to' => null,
        'mail.dev_email' => '',
    ]);
    Mail::forgetMailers();
});

function makeLeadReplyWorld(): array
{
    $vendor = Vendor::factory()->create([
        'business_email' => 'office@gs.construction',
    ]);
    $user = User::factory()->create(['primary_vendor_id' => $vendor->id]);
    $vendor->users()->attach($user->id, ['role_id' => 1]);
    $lead = Lead::create([
        'date' => now(),
        'origin' => 'gs.construction',
        'user_id' => $user->id,
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $user->id,
        'lead_data' => ['name' => 'Client', 'email' => 'client@example.com'],
    ]);
    $companyEmail = CompanyEmail::create([
        'vendor_id' => $vendor->id,
        'email' => 'office@gs.construction',
        'grant_id' => '',
    ]);

    return [$vendor, $user, $lead, $companyEmail];
}

it('sends the client message without an office CC and the office copy as its own message', function () {
    [, $user, $lead, $companyEmail] = makeLeadReplyWorld();

    (new SendLeadReplyJob(
        leadId: $lead->id,
        companyEmailId: $companyEmail->id,
        userId: $user->id,
        recipients: ['client@example.com'],
        fromEmail: 'greg@gs.construction',
        subject: 'Your quote',
        body: '<p>Here it is.</p>',
    ))->handle(app(App\Services\NylasService::class));

    $transport = Mail::mailer('array')->getSymfonyTransport();
    $messages = collect($transport->messages())->map(fn ($m) => $m->getOriginalMessage());

    expect($messages)->toHaveCount(2);

    $clientMessage = $messages->first(fn ($m) => collect($m->getTo())->contains(fn ($a) => $a->getAddress() === 'client@example.com'));
    $copyMessage = $messages->first(fn ($m) => collect($m->getTo())->contains(fn ($a) => $a->getAddress() === 'office@gs.construction'));

    expect($clientMessage)->not->toBeNull()
        ->and($copyMessage)->not->toBeNull();

    // The client's message carries no internal recipient at all.
    expect(collect($clientMessage->getCc()))->toBeEmpty()
        ->and(collect($clientMessage->getBcc())->map(fn ($a) => $a->getAddress())
            ->filter(fn ($e) => str_ends_with($e, '@gs.construction') || str_ends_with($e, '@hive.contractors')))
        ->toBeEmpty();

    // The copy is marked so tracking ignores it entirely.
    expect($copyMessage->getHeaders()->has('X-Hive-Internal-Copy'))->toBeTrue()
        ->and($clientMessage->getHeaders()->has('X-Hive-Internal-Copy'))->toBeFalse();

    // Replies route to the company inbox regardless of who sent the message —
    // that's the mailbox Hive ingests, so client replies land in the CRM.
    expect(collect($clientMessage->getReplyTo())->map(fn ($a) => $a->getAddress())->all())
        ->toBe(['office@gs.construction']);

    // Exactly one tracked send: the client's. The copy created no row.
    $sentRows = EmailTracking::query()->where('event_type', 'sent')->get();
    expect($sentRows)->toHaveCount(1)
        ->and($sentRows->first()->recipient_emails)->toBe(['client@example.com']);
});

it('does not mark the message opened when the office opens its own copy', function () {
    [, $user, $lead, $companyEmail] = makeLeadReplyWorld();

    (new SendLeadReplyJob(
        leadId: $lead->id,
        companyEmailId: $companyEmail->id,
        userId: $user->id,
        recipients: ['client@example.com'],
        fromEmail: 'greg@gs.construction',
        subject: 'Your quote',
        body: '<p>Here it is.</p>',
    ))->handle(app(App\Services\NylasService::class));

    $sent = EmailTracking::query()->where('event_type', 'sent')->firstOrFail();
    $trackingId = $sent->metadata['tracking_id'] ?? null;
    expect($trackingId)->not->toBeNull();

    // The office copy is its own Mailtrap message with its own tracking id —
    // an open on it arrives attributed to the OFFICE address, not the client.
    // Since the copy has no 'sent' row, the event dies as untracked.
    $this->postJson('/webhooks/mailtrap/test-token', [
        'events' => [[
            'event' => 'open',
            'email' => 'office@gs.construction',
            'message_id' => 'copy-message-with-no-sent-row',
            'timestamp' => now()->timestamp,
        ]],
    ])->assertSuccessful();

    expect(EmailTracking::query()->where('event_type', 'opened')->count())->toBe(0);

    // And the genuine article still lands: the client's own open is recorded.
    $this->postJson('/webhooks/mailtrap/test-token', [
        'events' => [[
            'event' => 'open',
            'email' => 'client@example.com',
            'custom_variables' => ['tracking_id' => $trackingId],
            'timestamp' => now()->addMinutes(20)->timestamp,
        ]],
    ])->assertSuccessful();

    expect(EmailTracking::query()->where('event_type', 'opened')->count())->toBe(1);
});
