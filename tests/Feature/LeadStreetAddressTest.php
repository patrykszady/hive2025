<?php

use App\Models\Client;
use App\Models\CompanyEmail;
use App\Models\Lead;
use App\Models\User;
use App\Models\Vendor;
use App\Services\CrewLeadEmailService;
use App\Services\GeoapifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Michael's shape: the enquiry said "in Arlington Heights by Lake Arlington",
 * the classifier filed the landmark as the address, and the street arrived
 * in a reply.
 */
function landmarkLeadFixture(bool $withClient = false): array
{
    config([
        'services.openai.api_key' => null,
        'nylas.crew_leads.internal_domains' => ['gs.construction', 'hive.contractors'],
        'nylas.crew_leads.mailbox' => 'crew@gs.construction',
    ]);

    $vendor = Vendor::factory()->create();
    $vendor->forceFill(['business_type' => 'LLC', 'registration' => ['registered' => true]])->save();

    $admin = new User();
    $admin->forceFill([
        'first_name' => 'Patryk',
        'last_name' => 'Szady',
        'email' => 'street.admin.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
        'registration' => ['registered' => true],
    ]);
    $admin->save();
    $vendor->users()->attach($admin->id, ['role_id' => 1]);

    $lead = Lead::withoutEvents(fn () => Lead::create([
        'date' => now(),
        'origin' => 'Email',
        'lead_data' => [
            'name' => 'Michael DiMarco',
            'email' => 'michael_dimarco@outlook.com',
            'phone' => '312 636 2700',
            'address' => 'by Lake Arlington',
            'city' => 'Arlington Heights',
            'state' => 'IL',
            'zip' => '60004',
            'message' => 'We live in Arlington Heights by Lake Arlington and have a small bathroom we are looking to do some work on.',
        ],
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $admin->id,
    ]));
    $lead->statuses()->create(['title' => 'New', 'belongs_to_vendor_id' => $vendor->id]);

    $client = null;

    if ($withClient) {
        // The contact and client as they were built before landmarks were
        // recognised: the "address" is the landmark, title-cased.
        $contact = User::query()->create([
            'first_name' => 'Michael',
            'last_name' => 'DiMarco',
            'email' => 'michael_dimarco@outlook.com',
            'cell_phone' => '3126362700',
        ]);
        $client = Client::factory()->create([
            'address' => 'By Lake Arlington', 'city' => 'Arlington Heights', 'state' => 'IL', 'zip_code' => '60004',
        ]);
        $client->users()->attach($contact->id);
        $client->vendors()->attach($vendor->id);
        $lead->forceFill(['user_id' => $contact->id])->saveQuietly();
    }

    return compact('vendor', 'admin', 'lead', 'client');
}

it('replaces a landmark address with the street the reply states, on the lead and its client', function () {
    $fx = landmarkLeadFixture(withClient: true);

    $method = new \ReflectionMethod(CrewLeadEmailService::class, 'recordLeadReply');
    $method->setAccessible(true);
    $method->invoke(app(CrewLeadEmailService::class), [
        'from_email' => 'michael_dimarco@outlook.com',
        'from_name' => 'Michael DiMarco',
        'recipients' => ['to' => ['crew@gs.construction'], 'cc' => []],
        'subject' => 'RE: Bathroom quote',
        'message_at' => now(),
    ], "Yes.  Sorry.\n \n1210 East Crabtree Drive, Arlington Heights  60004\n \n312 636 2700\n");

    $fresh = Lead::withoutGlobalScopes()->find($fx['lead']->id);

    expect($fresh->lead_data['address'])->toBe('1210 East Crabtree Drive')
        ->and($fresh->lead_data['city'])->toBe('Arlington Heights')
        ->and($fresh->lead_data['zip'])->toBe('60004')
        // The landmark client is this household, now with its street — not
        // a second client beside it.
        ->and($fx['client']->fresh()->address)->toBe('1210 East Crabtree Drive')
        ->and($fresh->user->clients()->count())->toBe(1);
});

it('asks a fresh lead for the address when what it gave is a landmark', function () {
    Queue::fake();
    $fx = landmarkLeadFixture();
    CompanyEmail::withoutEvents(fn () => CompanyEmail::create([
        'email' => 'support@example.test', 'vendor_id' => $fx['vendor']->id, 'grant_id' => 'grant-1',
    ]));

    $method = new \ReflectionMethod(CrewLeadEmailService::class, 'requestMissingInfo');
    $method->setAccessible(true);
    $method->invoke(app(CrewLeadEmailService::class), $fx['lead']->fresh(), ['subject' => 'Bathroom quote']);

    Queue::assertPushed(\App\Jobs\SendLeadReplyJob::class, function ($job) {
        $body = (fn () => $this->body)->call($job);

        return str_contains($body, 'the project address')
            && ! str_contains($body, 'phone number');
    });
});

it('mines the street out of stored replies through the backfill and fixes the client', function () {
    $this->mock(GeoapifyService::class)
        ->shouldReceive('geocodeAddress')->andReturn([
            'address' => '1210 East Crabtree Drive', 'city' => 'Arlington Heights', 'state' => 'IL', 'zip_code' => '60004',
        ])
        ->shouldReceive('nearbyAddressCandidates')->andReturn([]);

    $fx = landmarkLeadFixture(withClient: true);
    $data = $fx['lead']->lead_data->toArray();
    $data['email_replies'] = [
        ['at' => '2026-08-28 13:12:32', 'subject' => 'RE: Bathroom quote', 'body' => "Good morning.\n\nJust confirming that you have all the information you need now.\n\nRegards,\n\nMichael"],
        ['at' => '2026-08-24 12:41:07', 'subject' => 'RE: Bathroom quote', 'body' => "Yes.  Sorry.\n \n1210 East Crabtree Drive, Arlington Heights  60004\n \n312 636 2700\n"],
    ];
    $fx['lead']->forceFill(['lead_data' => $data])->saveQuietly();

    // No --lead: a client stuck on a landmark counts as incomplete.
    $this->artisan('leads:backfill-contacts')
        ->expectsOutputToContain('address from reply: 1210 East Crabtree Drive')
        ->assertSuccessful();

    expect($fx['lead']->fresh()->lead_data['address'])->toBe('by Lake Arlington')
        ->and($fx['client']->fresh()->address)->toBe('By Lake Arlington');

    $this->artisan('leads:backfill-contacts', ['--apply' => true])->assertSuccessful();

    expect($fx['lead']->fresh()->lead_data['address'])->toBe('1210 East Crabtree Drive')
        ->and($fx['client']->fresh()->address)->toBe('1210 East Crabtree Drive')
        ->and($fx['lead']->fresh()->user->clients()->count())->toBe(1);
});
