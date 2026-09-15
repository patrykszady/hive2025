<?php

use App\Jobs\MirrorLeadToGsc;
use App\Models\Lead;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function mirrorFixture(): array
{
    config(['services.gsc.url' => 'https://gs.test', 'services.gsc.token' => 'gsc-admin-token']);
    $vendor = Vendor::factory()->create();
    $creator = User::query()->create(['first_name' => 'Crew', 'last_name' => 'Inbox', 'email' => 'crew.' . uniqid() . '@example.test', 'cell_phone' => fake()->unique()->numerify('224555####')]);

    return compact('vendor', 'creator');
}

function hiveBornLead(array $fx, array $overrides = []): Lead
{
    return Lead::create(array_merge([
        'date' => '2026-09-15 20:06:27',
        'origin' => 'Email',
        'external_source' => 'crew-email',
        'external_id' => 'abc',
        'lead_data' => ['name' => 'Josh Simmons', 'email' => 'jsims692@example.test', 'address' => '6 Drake Terrace', 'city' => 'Prospect Heights', 'state' => 'IL', 'zip' => '60070', 'message' => 'I have a basement I need remodeling done.'],
        'belongs_to_vendor_id' => $fx['vendor']->id,
        'created_by_user_id' => $fx['creator']->id,
    ], $overrides));
}

it('pushes a lead born here to gs.construction the moment it is created', function () {
    Queue::fake();
    $fx = mirrorFixture();

    $lead = hiveBornLead($fx);

    Queue::assertPushed(MirrorLeadToGsc::class, fn (MirrorLeadToGsc $job) => $job->leadId === $lead->id);
});

it('does not push a lead that was born on gs.construction or Yelp', function () {
    Queue::fake();
    $fx = mirrorFixture();

    hiveBornLead($fx, ['origin' => 'gs.construction', 'external_source' => 'gs.construction', 'external_id' => '1']);
    hiveBornLead($fx, ['origin' => 'yelp', 'external_source' => 'yelp', 'external_id' => '151']);

    Queue::assertNotPushed(MirrorLeadToGsc::class);
});

it('sends the lead as the site expects, named by its channel', function () {
    $fx = mirrorFixture();
    Queue::fake();
    $lead = hiveBornLead($fx, ['origin' => 'Angi', 'external_source' => null, 'external_id' => null]);

    Http::fake(['gs.test/api/admin/v1/leads' => Http::response(['data' => ['id' => 42]], 201)]);

    (new MirrorLeadToGsc($lead->id))->handle();

    Http::assertSent(function ($request) use ($lead) {
        return $request->url() === 'https://gs.test/api/admin/v1/leads'
            && $request->hasHeader('Authorization', 'Bearer gsc-admin-token')
            && $request['hive_lead_id'] === $lead->id
            && $request['source'] === 'angi'
            && $request['name'] === 'Josh Simmons'
            && $request['zip'] === '60070'
            && str_starts_with((string) $request['received_at'], '2026-09-15T20:06:27');
    });
});

it('retries a server error but not a refusal', function () {
    $fx = mirrorFixture();
    Queue::fake();
    $lead = hiveBornLead($fx);

    // One fake, two answers in order: a second Http::fake() for the same
    // pattern does not replace the first.
    Http::fake(['gs.test/*' => Http::sequence()->push('nope', 422)->push('down', 503)]);

    (new MirrorLeadToGsc($lead->id))->handle(); // 422: ours to fix, not to retry — no exception
    expect(fn () => (new MirrorLeadToGsc($lead->id))->handle())->toThrow(RuntimeException::class); // 503: retry
});

it('stays quiet when the push is not configured', function () {
    Queue::fake();
    config(['services.gsc.url' => null, 'services.gsc.token' => null]);
    $fx = mirrorFixture();
    config(['services.gsc.url' => null, 'services.gsc.token' => null]);

    hiveBornLead($fx);

    Queue::assertNotPushed(MirrorLeadToGsc::class);
});

it('queues a catch-up push for every lead born here, and only those', function () {
    Queue::fake();
    $fx = mirrorFixture();
    $a = hiveBornLead($fx, ['origin' => 'Angi', 'external_source' => null, 'external_id' => null]);
    hiveBornLead($fx, ['origin' => 'gs.construction', 'external_source' => 'gs.construction', 'external_id' => '2']);
    Queue::fake(); // drop the creation-time pushes; count the command's own

    $this->artisan('leads:mirror-to-gsc', ['--days' => 30])->assertSuccessful();

    Queue::assertPushed(MirrorLeadToGsc::class, 1);
    Queue::assertPushed(MirrorLeadToGsc::class, fn (MirrorLeadToGsc $job) => $job->leadId === $a->id);
});
