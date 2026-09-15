<?php

use App\Jobs\SendLeadReplyJob;
use App\Models\CompanyEmail;
use App\Models\CrewEmailIngest;
use App\Models\Lead;
use App\Models\User;
use App\Models\Vendor;
use App\Services\CrewLeadEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Since 2026-09-15 gs.construction reads crew@, patryk@ and greg@ itself and
 * pushes each enquiry here, so that every lead starts on ss.systems. What
 * lands must be indistinguishable from what the crew reader here used to
 * make: origin Email, the files, the extracted detail, the missing-info ask —
 * and never a twin of a lead the crew reader already made from the same email.
 */
function emailIntakeFixture(): array
{
    $vendor = Vendor::factory()->create();
    $vendor->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();
    config(['nylas.crew_leads.vendor_id' => $vendor->id, 'services.gsc.url' => 'https://gs.test']);

    $api = new User();
    $api->forceFill([
        'first_name' => 'Site', 'last_name' => 'Bot',
        'email' => 'site-bot.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $api->save();
    $vendor->users()->attach($api->id, ['role_id' => 1]);

    CompanyEmail::create(['vendor_id' => $vendor->id, 'email' => 'crew@gs.construction', 'grant_id' => 'grant-crew']);

    Sanctum::actingAs($api);

    return compact('vendor', 'api');
}

function emailLeadPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'external_id' => sha1('<caj1234@mail.gmail.com>'),
        'source' => 'crew-email',
        'name' => 'William Johnson',
        'email' => 'willjohn1089@example.test',
        'address' => '7815 Kenton Ave',
        'city' => 'Skokie',
        'state' => 'IL',
        'zip' => '60076',
        'subject' => 'Bathroom remodel',
        'message' => 'Hi, we would like to remodel the hall bathroom at 7815 Kenton Ave, Skokie. Attached is the plan. Thanks, Will',
        'submitted_at' => '2026-09-15T20:20:00Z',
        'attachments' => [['url' => 'https://gs.test/storage/email-leads/9/plan.pdf', 'name' => 'plan.pdf', 'mime' => 'application/pdf', 'size' => 4]],
        'extracted' => ['mailbox' => 'crew@gs.construction', 'project_type' => 'Bathroom remodel', 'scope_summary' => 'Remodel the hall bathroom.', 'cc_emails' => ['partner@example.test'], 'is_lead' => true, 'confidence' => 0.96, 'reason' => 'Homeowner enquiry'],
        'in_reply_to' => '<caj1234@mail.gmail.com>',
    ], $overrides);
}

it('lands an email enquiry the site read as an Email lead with its files and detail, and asks for what is missing', function () {
    emailIntakeFixture();
    Storage::fake('files');
    Queue::fake();
    Http::fake(['gs.test/storage/*' => Http::response('%PDF'), '*' => Http::response([], 200)]);
    // The site already asked the model; asking again is a wasted call.
    $this->partialMock(CrewLeadEmailService::class, fn ($mock) => $mock->shouldNotReceive('classify'));

    $this->postJson('/api/v1/leads', emailLeadPayload())->assertCreated();

    $lead = Lead::withoutGlobalScopes()->where('external_source', 'crew-email')->firstOrFail();
    $data = (array) $lead->lead_data;

    expect($lead->origin)->toBe('Email')
        ->and($lead->external_id)->toBe(sha1('<caj1234@mail.gmail.com>'))
        ->and($lead->notes)->toStartWith('Bathroom remodel — Remodel the hall bathroom.')
        ->and($data['subject'])->toBe('Bathroom remodel')
        ->and($data['project_type'])->toBe('Bathroom remodel')
        ->and($data['source_mailbox'])->toBe('crew@gs.construction')
        ->and($data['cc_emails'])->toBe(['partner@example.test'])
        ->and($data['state'])->toBe('IL')
        ->and($data['zip'])->toBe('60076')
        ->and($data['attachments'])->toHaveCount(1)
        ->and($data['attachments'][0]['name'])->toBe('plan.pdf')
        ->and($data['attachments'][0]['mime'])->toBe('application/pdf')
        ->and($data['attachments'][0]['path'])->toStartWith('leads/'.$lead->id.'/')
        // No phone: the sender is asked, once, in the thread of their own email.
        ->and($data['missing_info_requested_at'] ?? null)->not->toBeNull();

    Storage::disk('files')->assertExists($data['attachments'][0]['path']);
    Queue::assertPushed(SendLeadReplyJob::class, 1);
});

it('provisions a contact for a complete email enquiry without asking the model again', function () {
    emailIntakeFixture();
    Queue::fake();
    Http::fake(['*' => Http::response([], 200)]);
    $this->partialMock(CrewLeadEmailService::class, fn ($mock) => $mock->shouldNotReceive('classify'));

    $this->postJson('/api/v1/leads', emailLeadPayload(['phone' => '8322571204', 'attachments' => []]))->assertCreated();

    $lead = Lead::withoutGlobalScopes()->where('external_source', 'crew-email')->firstOrFail();
    $contact = User::withoutGlobalScopes()->whereRaw('LOWER(email) = ?', ['willjohn1089@example.test'])->first();

    expect($contact)->not->toBeNull()
        ->and($lead->fresh()->user_id)->toBe($contact->id)
        // Nothing was missing, so nobody was emailed.
        ->and(((array) $lead->lead_data)['missing_info_requested_at'] ?? null)->toBeNull();
    Queue::assertNotPushed(SendLeadReplyJob::class);
});

it('only fetches files from the site it takes leads from', function () {
    emailIntakeFixture();
    Storage::fake('files');
    Queue::fake();
    Http::fake(['*' => Http::response('evil', 200)]);

    $this->postJson('/api/v1/leads', emailLeadPayload([
        'attachments' => [['url' => 'https://attacker.example/secret.pdf', 'name' => 'secret.pdf']],
    ]))->assertCreated();

    $lead = Lead::withoutGlobalScopes()->where('external_source', 'crew-email')->firstOrFail();
    expect(((array) $lead->lead_data)['attachments'] ?? [])->toBe([]);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'attacker.example'));
});

it('does not make a twin of the lead the crew reader here already made from the same email', function () {
    $fx = emailIntakeFixture();
    Queue::fake();
    Http::fake(['*' => Http::response([], 200)]);

    $existing = Lead::withoutEvents(fn () => Lead::create([
        'date' => now(), 'origin' => 'Email', 'external_source' => 'crew-email', 'external_id' => sha1('<caj1234@mail.gmail.com>'),
        'lead_data' => ['name' => 'William Johnson', 'email' => 'willjohn1089@example.test'],
        'belongs_to_vendor_id' => $fx['vendor']->id, 'created_by_user_id' => $fx['api']->id,
    ]));

    $this->postJson('/api/v1/leads', emailLeadPayload())
        ->assertOk()
        ->assertJson(['created' => false, 'data' => ['id' => $existing->id]]);

    expect(Lead::withoutGlobalScopes()->where('external_source', 'crew-email')->count())->toBe(1);
    Queue::assertNotPushed(SendLeadReplyJob::class);
});

it('leaves a web-form lead exactly as before', function () {
    emailIntakeFixture();
    Queue::fake();
    Http::fake(['*' => Http::response([], 200)]);

    $this->postJson('/api/v1/leads', [
        'external_id' => '901', 'source' => 'gs.construction', 'name' => 'Jane Doe', 'email' => 'jane@example.test',
        'phone' => '8475550100', 'message' => 'Interior remodel',
    ])->assertCreated();

    $lead = Lead::withoutGlobalScopes()->where('external_id', '901')->firstOrFail();
    expect($lead->origin)->toBe('gs.construction')
        ->and($lead->notes)->toBe('Interior remodel')
        ->and((array) $lead->lead_data)->not->toHaveKeys(['subject', 'attachments', 'source_mailbox']);
    Queue::assertNotPushed(SendLeadReplyJob::class);
});

it('has the crew reader here leave new enquiries to the site once the hand-over is on, while still filing replies', function () {
    $fx = emailIntakeFixture();
    config(['nylas.crew_leads.create_leads' => false, 'nylas.crew_leads.internal_domains' => ['gs.construction'], 'services.openai.api_key' => 'test']);
    Http::fake();

    $service = app(CrewLeadEmailService::class);
    $ingest = new \ReflectionMethod($service, 'ingestMessage');

    $enquiry = $ingest->invoke($service, [
        'id' => 'nylas-new-1', 'from' => [['email' => 'newperson@example.test', 'name' => 'New Person']],
        'subject' => 'Kitchen remodel', 'body' => 'We would like a quote for a full kitchen remodel at 12 Oak St, Palatine.', 'date' => now()->timestamp,
        'headers' => [['name' => 'Message-ID', 'value' => '<new-1@x>']],
    ], 'crew@gs.construction', 'grant-1', false);

    expect($enquiry['status'])->toBe(CrewEmailIngest::STATUS_SKIPPED)
        ->and($enquiry['reason'])->toBe('gsc_reads')
        ->and(CrewEmailIngest::where('nylas_message_id', 'nylas-new-1')->value('skip_reason'))->toBe('gsc_reads')
        ->and(Lead::withoutGlobalScopes()->count())->toBe(0);
    // Not even asked: the site asks the model, and pays for it, once.
    Http::assertNothingSent();

    $reply = $ingest->invoke($service, [
        'id' => 'nylas-reply-1', 'from' => [['email' => 'client@example.test']], 'subject' => 'Re: Your estimate',
        'body' => 'Looks good.', 'date' => now()->timestamp,
        'headers' => [['name' => 'Message-ID', 'value' => '<reply-1@x>'], ['name' => 'In-Reply-To', 'value' => '<est@hive>']],
    ], 'crew@gs.construction', 'grant-1', false);

    expect($reply['reason'])->toBe('reply');
});

it('has the crew reader here skip an email the site already pushed', function () {
    $fx = emailIntakeFixture();
    config(['nylas.crew_leads.internal_domains' => ['gs.construction'], 'services.openai.api_key' => 'test']);
    Http::fake();

    // Pushed by the site a minute ago, under the identity both readers compute.
    $pushed = Lead::withoutEvents(fn () => Lead::create([
        'date' => now(), 'origin' => 'Email', 'external_source' => 'crew-email', 'external_id' => sha1('<same-1@x>'),
        'lead_data' => ['name' => 'Someone Else', 'email' => 'someoneelse@example.test'],
        'belongs_to_vendor_id' => $fx['vendor']->id, 'created_by_user_id' => $fx['api']->id,
    ]));

    $service = app(CrewLeadEmailService::class);
    $result = (new \ReflectionMethod($service, 'ingestMessage'))->invoke($service, [
        'id' => 'nylas-same-1', 'from' => [['email' => 'newperson@example.test', 'name' => 'New Person']],
        'subject' => 'Kitchen remodel', 'body' => 'We would like a quote for a full kitchen remodel at 12 Oak St, Palatine.', 'date' => now()->timestamp,
        'headers' => [['name' => 'Message-ID', 'value' => '<same-1@x>']],
    ], 'crew@gs.construction', 'grant-1', false);

    expect($result['reason'])->toBe('already_ingested')
        ->and($result['lead_id'])->toBe($pushed->id)
        ->and(Lead::withoutGlobalScopes()->count())->toBe(1);
    Http::assertNothingSent();
});
