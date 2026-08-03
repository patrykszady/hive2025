<?php

use App\Models\Client;
use App\Models\CrewEmailIngest;
use App\Models\Lead;
use App\Models\User;
use App\Models\Vendor;
use App\Services\CrewLeadEmailService;
use App\Services\GeoapifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function backfillLead(array $leadData, ?Vendor $vendor = null): Lead
{
    $vendor ??= Vendor::factory()->create();

    $creator = User::query()->create([
        'first_name' => 'Lead',
        'last_name' => 'Creator',
        'email' => 'backfill.creator.'.uniqid().'@example.com',
        'cell_phone' => fake()->unique()->numerify('224777####'),
    ]);

    $lead = Lead::create([
        'date' => now(),
        'origin' => 'Email',
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $creator->id,
        'lead_data' => $leadData,
    ]);
    $lead->statuses()->create(['title' => 'New', 'belongs_to_vendor_id' => $vendor->id]);

    return $lead;
}

it('previews without writing anything', function () {
    $this->mock(GeoapifyService::class)
        ->shouldReceive('geocodeAddress')->andReturn([
            'address' => '960 Danielson Ct', 'city' => 'Gurnee', 'state' => 'IL', 'zip_code' => '60031',
        ])
        ->shouldReceive('nearbyAddressCandidates')->andReturn([]);

    $lead = backfillLead([
        'name' => 'Rob Rothbaum',
        'email' => 'rob.backfill@example.com',
        'phone' => '2245550101',
        'address' => '960 Danielson Ct',
        'city' => 'Gurnee',
    ]);

    $this->artisan('leads:backfill-contacts', ['--lead' => $lead->id])
        ->expectsOutputToContain('DRY RUN')
        ->assertSuccessful();

    expect($lead->fresh()->user_id)->toBeNull()
        ->and($lead->fresh()->lead_data['state'] ?? null)->toBeNull()
        ->and(Client::where('address', '960 Danielson Ct')->exists())->toBeFalse();
});

it('completes the address and creates the contact and client with --apply', function () {
    $this->mock(GeoapifyService::class)
        ->shouldReceive('geocodeAddress')->andReturn([
            'address' => '960 Danielson Ct', 'city' => 'Gurnee', 'state' => 'IL', 'zip_code' => '60031',
        ])
        ->shouldReceive('nearbyAddressCandidates')->andReturn([]);

    $lead = backfillLead([
        'name' => 'Rob Rothbaum',
        'email' => 'rob.backfill@example.com',
        'phone' => '2245550101',
        'address' => '960 Danielson Ct',
        'city' => 'Gurnee',
    ]);

    $this->artisan('leads:backfill-contacts', ['--lead' => $lead->id, '--apply' => true])
        ->assertSuccessful();

    $lead->refresh();
    $contact = User::find($lead->user_id);

    expect($contact)->not->toBeNull()
        ->and($contact->last_name)->toBe('Rothbaum');

    $client = $contact->clients()->firstOrFail();

    expect($client->city)->toBe('Gurnee')
        ->and($client->state)->toBe('IL')
        ->and((string) $client->zip_code)->toBe('60031');
});

it('records the choices instead of guessing when a street matches several towns', function () {
    $this->mock(GeoapifyService::class)
        ->shouldReceive('geocodeAddress')->andReturn(null)
        ->shouldReceive('nearbyAddressCandidates')->andReturn([
            ['address' => '511 Sherwood Drive', 'city' => 'Addison', 'state' => 'IL', 'zip_code' => '60101', 'miles' => 12.0],
            ['address' => '511 Sherwood Drive', 'city' => 'Streamwood', 'state' => 'IL', 'zip_code' => '60107', 'miles' => 13.4],
        ]);

    $lead = backfillLead([
        'name' => 'Michael Flores',
        'email' => 'mike.backfill@example.com',
        'phone' => '6308354185',
        'address' => '511 Sherwood Dr',
    ]);

    $this->artisan('leads:backfill-contacts', ['--lead' => $lead->id, '--apply' => true])
        ->expectsOutputToContain('needs a human to choose')
        ->assertSuccessful();

    $data = $lead->fresh()->lead_data;

    expect($data['address_candidates'])->toHaveCount(2)
        // No city was invented, and no client built from a half address.
        ->and($data['city'] ?? null)->toBeNull()
        ->and(Client::where('address', 'like', '511 Sherwood%')->exists())->toBeFalse();
});

it('recovers the partner address from the mailbox row', function () {
    $this->mock(GeoapifyService::class)
        ->shouldReceive('geocodeAddress')->andReturn([
            'address' => '5647 N Magnolia Ave', 'city' => 'Chicago', 'state' => 'IL', 'zip_code' => '60660',
        ])
        ->shouldReceive('nearbyAddressCandidates')->andReturn([]);

    $lead = backfillLead([
        'name' => 'Amy Dusto and Chris Ecker',
        'email' => 'amy.backfill@example.com',
        'phone' => '443-333-0697 / 339-222-0798',
        'address' => '5647 N Magnolia Ave',
        'city' => 'Chicago',
    ]);

    CrewEmailIngest::create([
        'nylas_message_id' => 'msg-'.uniqid(),
        'grant_id' => 'grant-test',
        'mailbox' => 'crew@gs.construction',
        'lead_id' => $lead->id,
        'from_email' => 'amy.backfill@example.com',
        'recipients' => ['to' => ['crew@gs.construction'], 'cc' => ['ecker.chris.r@example.com']],
        'status' => CrewEmailIngest::STATUS_LEAD,
        'is_lead' => true,
    ]);

    $this->artisan('leads:backfill-contacts', ['--lead' => $lead->id, '--apply' => true])
        ->assertSuccessful();

    $client = User::find($lead->fresh()->user_id)->clients()->firstOrFail();
    $chris = $client->users()->where('first_name', 'Chris')->first();

    expect($chris)->not->toBeNull()
        ->and($chris->email)->toBe('ecker.chris.r@example.com')
        ->and($chris->cell_phone)->toBe('3392220798');
});

it('is safe to run twice and never overwrites what is already on file', function () {
    $this->mock(GeoapifyService::class)
        ->shouldReceive('geocodeAddress')->andReturn([
            'address' => '960 Danielson Ct', 'city' => 'Gurnee', 'state' => 'IL', 'zip_code' => '60031',
        ])
        ->shouldReceive('nearbyAddressCandidates')->andReturn([]);

    $lead = backfillLead([
        'name' => 'Rob Rothbaum',
        'email' => 'rob.backfill@example.com',
        'phone' => '2245550101',
        'address' => '960 Danielson Ct',
        'city' => 'Gurnee',
        // A ZIP already on file must survive the geocoder's differing answer.
        'zip' => '60099',
    ]);

    $this->artisan('leads:backfill-contacts', ['--lead' => $lead->id, '--apply' => true])->assertSuccessful();

    $contact = User::find($lead->fresh()->user_id);
    $client = $contact->clients()->firstOrFail();

    expect((string) $client->zip_code)->toBe('60099');

    // Second pass: no duplicate contact, no duplicate client, nothing changed.
    $this->artisan('leads:backfill-contacts', ['--lead' => $lead->id, '--apply' => true])->assertSuccessful();

    expect(User::where('email', 'rob.backfill@example.com')->count())->toBe(1)
        ->and($contact->fresh()->clients()->count())->toBe(1)
        ->and((string) $client->fresh()->zip_code)->toBe('60099');
});

it('skips solicitations instead of building contacts for them', function () {
    $this->mock(GeoapifyService::class)
        ->shouldReceive('geocodeAddress')->andReturn(null)
        ->shouldReceive('nearbyAddressCandidates')->andReturn([]);

    $this->mock(CrewLeadEmailService::class)
        ->shouldReceive('classify')
        ->andReturn(['is_lead' => false, 'confidence' => 1.0, 'reason' => 'Selling a domain name', 'extraction_status' => 'ok', 'fields' => []]);

    $lead = backfillLead([
        'name' => 'Godzilla dn',
        'email' => 'inquiry@godzilladn.com',
        'phone' => '6036198928',
        'message' => "Hi, I noticed your business and thought I'd reach out because I own ChicagoRemodelers.com and it is currently available for purchase by a local remodeling company.",
    ]);

    $this->artisan('leads:backfill-contacts', ['--lead' => $lead->id, '--apply' => true])
        ->expectsOutputToContain('solicitation')
        ->assertSuccessful();

    expect($lead->fresh()->user_id)->toBeNull()
        ->and(User::where('email', 'inquiry@godzilladn.com')->exists())->toBeFalse();
});

it('never judges a message too short to judge', function () {
    // "Interior remodel" is a real homeowner enquiry; the classifier reads
    // brevity as a pitch, so short messages are provisioned without asking.
    $this->mock(GeoapifyService::class)
        ->shouldReceive('geocodeAddress')->andReturn([
            'address' => '117 Bluff Ave', 'city' => 'Grayslake', 'state' => 'IL', 'zip_code' => '60030',
        ])
        ->shouldReceive('nearbyAddressCandidates')->andReturn([]);

    $this->mock(CrewLeadEmailService::class)->shouldNotReceive('classify');

    $lead = backfillLead([
        'name' => 'Bill Stranberg',
        'email' => 'bill@stranberg.example.com',
        'phone' => '2245550188',
        'address' => '117 Bluff Ave',
        'city' => 'Grayslake',
        'message' => 'Interior remodel',
    ]);

    $this->artisan('leads:backfill-contacts', ['--lead' => $lead->id, '--apply' => true])->assertSuccessful();

    expect($lead->fresh()->user_id)->not->toBeNull();
});

it('leaves a lead alone once somebody has triaged it', function () {
    $this->mock(GeoapifyService::class)
        ->shouldReceive('geocodeAddress')->andReturn(null)
        ->shouldReceive('nearbyAddressCandidates')->andReturn([]);

    // Already judged by a human — no need to ask the classifier at all.
    $this->mock(CrewLeadEmailService::class)->shouldNotReceive('classify');

    $vendor = Vendor::factory()->create();
    $lead = backfillLead([
        'name' => 'Kevin McHue',
        'email' => 'admin@illinoisaiconsultant.example.com',
        'phone' => '2245550199',
        'message' => 'We help Illinois remodeling company owners implement AI and automation in their daily workflows.',
    ], $vendor);
    $lead->statuses()->create(['title' => 'Not a Fit', 'belongs_to_vendor_id' => $vendor->id]);

    $this->artisan('leads:backfill-contacts', ['--lead' => $lead->id, '--apply' => true])
        ->expectsOutputToContain('already triaged as Not a Fit')
        ->assertSuccessful();

    expect($lead->fresh()->user_id)->toBeNull();
});

it('provisions a solicitation anyway when asked to', function () {
    $this->mock(GeoapifyService::class)
        ->shouldReceive('geocodeAddress')->andReturn(null)
        ->shouldReceive('nearbyAddressCandidates')->andReturn([]);

    $this->mock(CrewLeadEmailService::class)->shouldNotReceive('classify');

    $lead = backfillLead([
        'name' => 'Jason Taken',
        'email' => 'jason.taken@hedgestone.example.com',
        'phone' => '2242493213',
        'message' => 'Hello, I have several buyers interested in purchasing a kitchen remodeling company in the area and I wanted to reach out.',
    ]);

    $this->artisan('leads:backfill-contacts', ['--lead' => $lead->id, '--apply' => true, '--include-junk' => true])
        ->assertSuccessful();

    expect($lead->fresh()->user_id)->not->toBeNull();
});
