<?php

use App\Models\EmailTracking;
use App\Models\Vendor;
use App\Services\EmailReplyDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * EmailReplyDetector: the producer for the 'replied' event both tracking
 * tables were already built to display. Matching runs strongest evidence
 * first and every event records which layer produced it (matched_via), so a
 * wrong badge is traceable to the guess that made it.
 */
function sentRow(array $overrides = []): EmailTracking
{
    static $vendorId;
    $vendorId ??= Vendor::factory()->create()->id;

    return EmailTracking::withoutGlobalScopes()->create(array_merge([
        'belongs_to_vendor_id' => $vendorId,
        'project_id' => null,
        'lead_id' => 77,
        'message_id' => 'provider-msg-' . fake()->unique()->numberBetween(1, 99999),
        'thread_id' => null,
        'email_template_name' => 'estimate',
        'event_type' => 'sent',
        'recipient_emails' => ['client@example.com'],
        'metadata' => ['rfc_message_id' => 'rfc-abc@hive', 'subject' => 'Your kitchen estimate'],
        'event_at' => now()->subDay(),
    ], $overrides));
}

function detect(array $overrides = []): ?EmailTracking
{
    return app(EmailReplyDetector::class)->record(array_merge([
        'nylas_message_id' => 'ny-' . fake()->unique()->numberBetween(1, 99999),
        'from_email' => 'client@example.com',
        'subject' => 'Re: Your kitchen estimate',
        'thread_id' => null,
        'in_reply_to' => null,
        'references' => null,
        'message_at' => now(),
        'mailbox' => 'crew@gs.construction',
    ], $overrides));
}

it('matches by Nylas thread id first and says so', function () {
    $sent = sentRow(['thread_id' => 'nylas-thread-1']);

    $event = detect(['thread_id' => 'nylas-thread-1', 'subject' => 'totally rewritten subject']);

    expect($event)->not->toBeNull()
        ->and($event->metadata['matched_via'])->toBe('thread')
        ->and($event->message_id)->toBe($sent->message_id)
        ->and($event->thread_id)->toBe('nylas-thread-1');
});

it('matches by RFC References even for a reply to a reply', function () {
    $sent = sentRow();

    // Deep in a thread: References carries the whole chain, our id included.
    $event = detect([
        'subject' => 'Re: Re: Your kitchen estimate',
        'references' => '<later-reply@gmail.com> <rfc-abc@hive> <even-later@gmail.com>',
    ]);

    expect($event)->not->toBeNull()
        ->and($event->metadata['matched_via'])->toBe('rfc')
        ->and($event->metadata['sent_event_id'])->toBe($sent->id);
});

it('matches by In-Reply-To alone', function () {
    sentRow();

    $event = detect(['in_reply_to' => '<rfc-abc@hive>', 'subject' => '']);

    expect($event?->metadata['matched_via'])->toBe('rfc');
});

it('falls back to recipient plus bare subject, surviving stacked prefixes', function () {
    $sent = sentRow(['metadata' => ['subject' => 'Your kitchen estimate']]);

    $event = detect(['subject' => 'RE: FW: Your Kitchen Estimate']);

    expect($event)->not->toBeNull()
        ->and($event->metadata['matched_via'])->toBe('subject')
        ->and($event->metadata['sent_event_id'])->toBe($sent->id);
});

it('falls back to the latest recent send to that address, labelled as a guess', function () {
    $older = sentRow(['event_at' => now()->subDays(10), 'metadata' => ['subject' => 'First contact']]);
    $newer = sentRow(['event_at' => now()->subDays(2), 'metadata' => ['subject' => 'Follow up']]);

    $event = detect(['subject' => 'Re: something they retyped entirely']);

    expect($event)->not->toBeNull()
        ->and($event->metadata['matched_via'])->toBe('recipient')
        ->and($event->metadata['sent_event_id'])->toBe($newer->id);
});

it('refuses the recipient guess outside the window', function () {
    sentRow(['event_at' => now()->subDays(45), 'metadata' => ['subject' => 'Old thread']]);

    expect(detect(['subject' => 'Re: whatever']))->toBeNull();
});

it('does not badge fresh mail that only happens to come from a known recipient', function () {
    sentRow();

    // No Re: prefix, no threading headers — a NEW message, not an answer.
    expect(detect(['subject' => 'Completely new question about decks']))->toBeNull();
});

it('prefers thread over rfc over subject when several layers would hit', function () {
    $byThread = sentRow(['thread_id' => 'thread-9', 'metadata' => ['rfc_message_id' => 'other@hive', 'subject' => 'X']]);
    sentRow(['metadata' => ['rfc_message_id' => 'rfc-abc@hive', 'subject' => 'Your kitchen estimate']]);

    $event = detect([
        'thread_id' => 'thread-9',
        'references' => '<rfc-abc@hive>',
    ]);

    expect($event->metadata['matched_via'])->toBe('thread')
        ->and($event->metadata['sent_event_id'])->toBe($byThread->id);
});

it('files one event per reply message no matter how many sweeps see it', function () {
    sentRow();

    detect(['nylas_message_id' => 'ny-dupe', 'in_reply_to' => '<rfc-abc@hive>']);
    detect(['nylas_message_id' => 'ny-dupe', 'in_reply_to' => '<rfc-abc@hive>']);

    expect(EmailTracking::withoutGlobalScopes()->where('event_type', 'replied')->count())->toBe(1);
});

it('badges replies from clients who are not leads at all', function () {
    // An estimate thread: the recipient is an established client, lead_id null.
    $sent = sentRow(['lead_id' => null, 'email_template_name' => 'estimate']);

    $event = detect(['in_reply_to' => '<rfc-abc@hive>']);

    expect($event)->not->toBeNull()
        ->and($event->lead_id)->toBeNull()
        ->and($event->belongs_to_vendor_id)->toBe($sent->belongs_to_vendor_id)
        ->and($event->recipient_emails)->toBe(['client@example.com']);
});

it('records nothing when no sent row can be tied to the reply', function () {
    expect(detect(['subject' => 'Re: a thread hive never sent']))->toBeNull()
        ->and(EmailTracking::withoutGlobalScopes()->where('event_type', 'replied')->count())->toBe(0);
});
