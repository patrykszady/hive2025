<?php

use App\Models\Lead;
use App\Models\User;
use App\Models\Vendor;
use App\Services\CrewLeadEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * The crew@ ingest configured against a fresh vendor, with the classifier
 * and reply-mining model switched off (no API key) so only the deterministic
 * name pairing is under test.
 */
function senderNameFixture(): array
{
    Storage::fake('files');
    config(['services.openai.api_key' => null]);

    $vendor = Vendor::factory()->create();
    $vendor->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();

    $creator = new User();
    $creator->forceFill([
        'first_name' => 'Crew',
        'last_name' => 'Inbox',
        'email' => 'crew.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ]);
    $creator->save();
    $vendor->users()->attach($creator->id, ['role_id' => 1]);

    config(['nylas.crew_leads' => array_merge((array) config('nylas.crew_leads'), [
        'vendor_id' => $vendor->id,
        'created_by_user_id' => $creator->id,
        'external_source' => 'crew-email',
        'mailbox' => 'crew@gs.construction',
        'internal_domains' => ['gs.construction', 'hive.contractors'],
    ])]);

    return compact('vendor', 'creator');
}

/** Drive createLead() the way the sweep does, with the classifier's verdict given. */
function ingestedLead(array $fromHeader, array $fields): Lead
{
    $body = "Hi —\n\nLooking to convert a basement half bath to a full bath.\n\nThanks,\n".explode(' ', (string) ($fields['name'] ?? 'there'))[0];
    $message = [
        'id' => 'msg-'.uniqid(),
        'from' => [$fromHeader],
        'subject' => 'Basement bathroom project',
        'date' => now()->timestamp,
        'attachments' => [],
    ];
    $base = [
        'nylas_message_id' => $message['id'],
        'grant_id' => 'grant-1',
        'mailbox' => 'crew@gs.test',
        'thread_id' => null,
        'from_email' => strtolower($fromHeader['email']),
        'from_name' => $fromHeader['name'] ?? null,
        'recipients' => ['to' => ['crew@gs.test'], 'cc' => []],
        'subject' => 'Basement bathroom project',
        'message_at' => now(),
        'body_snippet' => $body,
    ];
    $verdict = ['is_lead' => true, 'confidence' => 0.95, 'reason' => 'enquiry', 'extraction_status' => 'ok', 'fields' => $fields];

    $method = new \ReflectionMethod(CrewLeadEmailService::class, 'createLead');
    $method->setAccessible(true);

    return $method->invoke(app(CrewLeadEmailService::class), $message, $base, $body, $verdict);
}

it('names the lead from the From header when the message only signs a first name', function () {
    senderNameFixture();

    $lead = ingestedLead(
        ['email' => 'willjohn1089@gmail.com', 'name' => 'William Johnson89 wa'],
        ['name' => 'Will', 'phone' => '(832) 257-1204', 'address' => '7815 Kenton Ave', 'city' => 'Skokie', 'zip' => '60076'],
    );

    $fresh = Lead::withoutGlobalScopes()->find($lead->id);

    expect($fresh->lead_data['name'])->toBe('William Johnson')
        // …which is what lets the provisioner create the contact at all.
        ->and($fresh->user)->not->toBeNull()
        ->and($fresh->user->first_name)->toBe('William')
        ->and($fresh->user->last_name)->toBe('Johnson')
        ->and($fresh->user->email)->toBe('willjohn1089@gmail.com');
});

it('keeps the sign-off when the header names someone else', function () {
    senderNameFixture();

    $lead = ingestedLead(
        ['email' => 'mary.johnson@example.com', 'name' => 'Mary Johnson'],
        ['name' => 'Mark', 'phone' => '(832) 257-1204'],
    );

    expect(Lead::withoutGlobalScopes()->find($lead->id)->lead_data['name'])->toBe('Mark');
});

it('keeps a name the message wrote out in full', function () {
    senderNameFixture();

    $lead = ingestedLead(
        ['email' => 'amy@example.com', 'name' => 'Amy Dusto'],
        ['name' => 'Amy Dusto and Chris Ecker', 'phone' => '443-333-0697 / 339-222-0798'],
    );

    expect(Lead::withoutGlobalScopes()->find($lead->id)->lead_data['name'])->toBe('Amy Dusto and Chris Ecker');
});

it('falls back to the header name when the message signs nothing', function () {
    senderNameFixture();

    $lead = ingestedLead(
        ['email' => 'willjohn1089@gmail.com', 'name' => 'William Johnson89 wa'],
        ['name' => null, 'phone' => '(832) 257-1204'],
    );

    expect(Lead::withoutGlobalScopes()->find($lead->id)->lead_data['name'])->toBe('William Johnson');
});

it('completes a first-name-only lead from the header of their reply', function () {
    $fx = senderNameFixture();

    $lead = Lead::withoutEvents(fn () => Lead::create([
        'date' => now(),
        'origin' => 'Email',
        'lead_data' => [
            'name' => 'Will',
            'email' => 'willjohn1089@gmail.com',
            'phone' => '(832) 257-1204',
            'address' => '7815 Kenton Ave',
            'city' => 'Skokie',
            'state' => 'IL',
            'zip' => '60076',
        ],
        'belongs_to_vendor_id' => $fx['vendor']->id,
        'created_by_user_id' => $fx['creator']->id,
    ]));
    $lead->statuses()->create(['title' => 'Replied', 'belongs_to_vendor_id' => $fx['vendor']->id]);

    $method = new \ReflectionMethod(CrewLeadEmailService::class, 'recordLeadReply');
    $method->setAccessible(true);
    $method->invoke(app(CrewLeadEmailService::class), [
        'from_email' => 'willjohn1089@gmail.com',
        'from_name' => 'William Johnson89 wa',
        'subject' => 'Re: Basement bathroom project',
        'message_at' => now(),
    ], "Sure thing - info as follows:\n\n7815 Kenton Ave\nSkokie, IL 60076\n\nThanks,\nWill");

    $fresh = Lead::withoutGlobalScopes()->find($lead->id);

    expect($fresh->lead_data['name'])->toBe('William Johnson')
        ->and($fresh->user?->first_name)->toBe('William')
        ->and($fresh->user?->last_name)->toBe('Johnson');
});

it('leaves a lead that already has two names alone on reply', function () {
    $fx = senderNameFixture();

    $lead = Lead::withoutEvents(fn () => Lead::create([
        'date' => now(),
        'origin' => 'Email',
        'lead_data' => ['name' => 'Will Johnson', 'email' => 'willjohn1089@gmail.com', 'phone' => '(832) 257-1204'],
        'belongs_to_vendor_id' => $fx['vendor']->id,
        'created_by_user_id' => $fx['creator']->id,
    ]));

    $method = new \ReflectionMethod(CrewLeadEmailService::class, 'recordLeadReply');
    $method->setAccessible(true);
    $method->invoke(app(CrewLeadEmailService::class), [
        'from_email' => 'willjohn1089@gmail.com',
        'from_name' => 'William Johnson89 wa',
        'subject' => 'Re: Basement bathroom project',
        'message_at' => now(),
    ], "Thanks,\nWill");

    expect(Lead::withoutGlobalScopes()->find($lead->id)->lead_data['name'])->toBe('Will Johnson');
});

it('takes the surname from the address when the header is a pet name', function () {
    senderNameFixture();

    $lead = ingestedLead(
        ['email' => 'michael_dimarco@outlook.com', 'name' => 'MiMi DiDi'],
        ['name' => 'Michael', 'phone' => '312 636 2700'],
    );

    $fresh = Lead::withoutGlobalScopes()->find($lead->id);

    expect($fresh->lead_data['name'])->toBe('Michael Dimarco')
        ->and($fresh->user?->last_name)->toBe('Dimarco');
});

it('names a lead from the address when the header is a first name and a stray number', function () {
    senderNameFixture();

    // The classifier found no name in the message; the old fallback would
    // have stored the header as-is.
    $lead = ingestedLead(
        ['email' => 'toby.daisy112148@gmail.com', 'name' => 'Toby 312'],
        ['name' => null, 'phone' => '(312) 555-0142'],
    );

    $fresh = Lead::withoutGlobalScopes()->find($lead->id);

    expect($fresh->lead_data['name'])->toBe('Toby Daisy')
        ->and($fresh->user?->first_name)->toBe('Toby')
        ->and($fresh->user?->last_name)->toBe('Daisy');
});

it('links a lead from a sender already on file to that contact', function () {
    senderNameFixture();
    $josh = User::query()->create([
        'first_name' => 'Joshua', 'last_name' => 'Simmons', 'email' => 'jsims692@example.test', 'cell_phone' => '8475550109',
    ]);

    $lead = ingestedLead(
        ['email' => 'jsims692@example.test', 'name' => 'Josh Simmons'],
        ['name' => 'Josh Simmons', 'address' => '6 Drake Terrace', 'city' => 'Prospect Heights', 'zip' => '60070'],
    );

    expect(Lead::withoutGlobalScopes()->find($lead->id)->user_id)->toBe($josh->id);
});

function dimarcoLead(array $fx): Lead
{
    return Lead::withoutEvents(fn () => Lead::create([
        'date' => now(),
        'origin' => 'Email',
        'lead_data' => ['name' => 'Michael DiMarco', 'email' => 'michael_dimarco@outlook.com', 'phone' => '312 636 2700'],
        'belongs_to_vendor_id' => $fx['vendor']->id,
        'created_by_user_id' => $fx['creator']->id,
    ]));
}

function fileReply(array $base, string $body = "Please give me a call when you have time.\n\nMichael 312 636 2700"): ?int
{
    $method = new \ReflectionMethod(CrewLeadEmailService::class, 'recordLeadReply');
    $method->setAccessible(true);

    return $method->invoke(app(CrewLeadEmailService::class), $base + ['subject' => 'RE: Bathroom quote', 'message_at' => now()], $body);
}

it('files a reply sent from a second address that CCs the one we know, and remembers the address', function () {
    $fx = senderNameFixture();
    $lead = dimarcoLead($fx);
    $lead->statuses()->create(['title' => 'Replied', 'belongs_to_vendor_id' => $fx['vendor']->id]);

    $leadId = fileReply([
        'from_email' => 'mdimarco71@hotmail.com',
        'from_name' => 'MiMi DiDi',
        'recipients' => ['to' => ['crew@gs.construction'], 'cc' => ['michael_dimarco@outlook.com']],
    ]);

    $fresh = Lead::withoutGlobalScopes()->find($lead->id);

    expect($leadId)->toBe($lead->id)
        ->and($fresh->lead_data['email_replies'])->toHaveCount(1)
        ->and($fresh->lead_data['alt_emails'])->toBe(['mdimarco71@hotmail.com'])
        // The pet name in the header never touches a name we already have.
        ->and($fresh->lead_data['name'])->toBe('Michael DiMarco')
        ->and($fresh->last_status->title)->toBe('New');

    // The next message from that address needs no CC.
    fileReply([
        'from_email' => 'mdimarco71@hotmail.com',
        'from_name' => 'MiMi DiDi',
        'recipients' => ['to' => ['crew@gs.construction'], 'cc' => []],
    ], 'Any update?');

    $fresh = Lead::withoutGlobalScopes()->find($lead->id);

    expect($fresh->lead_data['email_replies'])->toHaveCount(2)
        ->and($fresh->lead_data['alt_emails'])->toBe(['mdimarco71@hotmail.com']);
});

it('never files a stranger onto a lead through our own addresses', function () {
    $fx = senderNameFixture();
    $lead = dimarcoLead($fx);

    $leadId = fileReply([
        'from_email' => 'stranger@example.com',
        'from_name' => 'A Stranger',
        'recipients' => ['to' => ['crew@gs.construction'], 'cc' => ['patryk@gs.construction']],
    ], 'Hello');

    expect($leadId)->toBeNull()
        ->and(Lead::withoutGlobalScopes()->find($lead->id)->lead_data['email_replies'] ?? [])->toBe([]);
});

it('relinks a skipped reply onto its lead through the command', function () {
    $fx = senderNameFixture();
    $lead = dimarcoLead($fx);

    $row = \App\Models\CrewEmailIngest::create([
        'nylas_message_id' => 'msg-'.uniqid(),
        'grant_id' => 'grant-test',
        'mailbox' => 'crew@gs.construction',
        'from_email' => 'mdimarco71@hotmail.com',
        'from_name' => 'MiMi DiDi',
        'recipients' => ['to' => ['crew@gs.construction'], 'cc' => ['michael_dimarco@outlook.com']],
        'subject' => 'RE: Bathroom quote',
        'message_at' => now(),
        'body_snippet' => "Morning Patryk.\n\nPer my original email...\n\nMichael 312 636 2700",
        'status' => \App\Models\CrewEmailIngest::STATUS_SKIPPED,
        'skip_reason' => 'reply',
        'is_lead' => false,
    ]);

    $this->artisan('leads:relink-replies')
        ->expectsOutputToContain("lead {$lead->id}")
        ->expectsOutputToContain('Nothing was written')
        ->assertSuccessful();

    expect($row->fresh()->lead_id)->toBeNull();

    $this->artisan('leads:relink-replies', ['--apply' => true])->assertSuccessful();

    $fresh = Lead::withoutGlobalScopes()->find($lead->id);

    expect($row->fresh()->lead_id)->toBe($lead->id)
        ->and($fresh->lead_data['email_replies'][0]['body'])->toContain('Per my original email')
        ->and($fresh->lead_data['alt_emails'])->toBe(['mdimarco71@hotmail.com']);

    // A filed row is not filed twice.
    $this->artisan('leads:relink-replies', ['--apply' => true])->assertSuccessful();
    expect(Lead::withoutGlobalScopes()->find($lead->id)->lead_data['email_replies'])->toHaveCount(1);
});
