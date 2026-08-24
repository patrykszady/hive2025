<?php

use App\Models\CrewEmailIngest;
use App\Models\EmailTracking;
use App\Models\Vendor;
use App\Services\CrewLeadEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * End to end through the crew@ ingest: a client answering an estimate is
 * triaged as a reply and badges the estimate thread — even though no lead
 * exists for them anywhere. This is the coverage the first cut of this
 * feature lacked.
 */
it('files a replied event from the crew ingest for a client with no lead', function () {
    config(['nylas.crew_leads.internal_domains' => ['gs.construction', 'hive.contractors']]);

    $vendor = Vendor::factory()->create();

    $sent = EmailTracking::withoutGlobalScopes()->create([
        'belongs_to_vendor_id' => $vendor->id,
        'message_id' => 'provider-msg-1',
        'email_template_name' => 'estimate',
        'event_type' => 'sent',
        'recipient_emails' => ['homeowner@gmail.com'],
        'metadata' => ['rfc_message_id' => 'estimate-42@hive', 'subject' => 'Your bathroom estimate'],
        'event_at' => now()->subDay(),
    ]);

    $service = app(CrewLeadEmailService::class);
    $method = new \ReflectionMethod($service, 'ingestMessage');

    $result = $method->invoke($service, [
        'id' => 'nylas-msg-777',
        'from' => [['email' => 'homeowner@gmail.com', 'name' => 'Home Owner']],
        'to' => [['email' => 'crew@gs.construction']],
        'subject' => 'Re: Your bathroom estimate',
        'thread_id' => 'nylas-thread-crew-1',
        'date' => now()->timestamp,
        'headers' => [
            ['name' => 'In-Reply-To', 'value' => '<estimate-42@hive>'],
            ['name' => 'References', 'value' => '<estimate-42@hive>'],
        ],
        'body' => '<p>Looks great, when can you start?</p>',
    ], 'crew@gs.construction', 'grant-1', false);

    expect($result['status'] ?? null)->toBe(CrewEmailIngest::STATUS_SKIPPED)
        ->and($result['reason'] ?? null)->toBe('reply');

    $replied = EmailTracking::withoutGlobalScopes()->where('event_type', 'replied')->get();

    expect($replied)->toHaveCount(1)
        ->and($replied->first()->message_id)->toBe($sent->message_id)
        ->and($replied->first()->metadata['matched_via'])->toBe('rfc')
        ->and($replied->first()->recipient_emails)->toBe(['homeowner@gmail.com']);

    // The ingest ledger recorded the skip as before — the badge is additive.
    expect(CrewEmailIngest::where('nylas_message_id', 'nylas-msg-777')->value('skip_reason'))->toBe('reply');
});

it('does not write replied events on a dry run', function () {
    config(['nylas.crew_leads.internal_domains' => ['gs.construction']]);

    EmailTracking::withoutGlobalScopes()->create([
        'belongs_to_vendor_id' => Vendor::factory()->create()->id,
        'message_id' => 'provider-msg-2',
        'event_type' => 'sent',
        'recipient_emails' => ['homeowner@gmail.com'],
        'metadata' => ['rfc_message_id' => 'estimate-43@hive'],
        'event_at' => now()->subDay(),
    ]);

    $service = app(CrewLeadEmailService::class);
    $method = new \ReflectionMethod($service, 'ingestMessage');

    $method->invoke($service, [
        'id' => 'nylas-msg-778',
        'from' => [['email' => 'homeowner@gmail.com']],
        'subject' => 'Re: estimate',
        'date' => now()->timestamp,
        'headers' => [['name' => 'In-Reply-To', 'value' => '<estimate-43@hive>']],
    ], 'crew@gs.construction', 'grant-1', true);

    expect(EmailTracking::withoutGlobalScopes()->where('event_type', 'replied')->count())->toBe(0);
});
