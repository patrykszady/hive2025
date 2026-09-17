<?php

use App\Jobs\SendLeadReplyJob;
use App\Models\Lead;
use App\Models\User;
use App\Services\CrewLeadEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/lead-intake-fixtures.php';

/**
 * A website enquiry signed with a first name alone. The name is completed
 * from the message, then from the address; when neither says, the sender is
 * asked for it — the same one-shot ask an email enquiry gets for a missing
 * phone or address.
 */
function websiteLeadPayload(array $overrides = []): array
{
    return array_replace([
        'external_id' => 'sub-'.uniqid(),
        'source' => 'gs.construction',
        'name' => 'Katherine',
        'email' => 'kb2020@gmail.com',
        'phone' => '8474041401',
        'address' => '1801 Elm St',
        'city' => 'Park Ridge',
        'state' => 'IL',
        'zip' => '60068',
        'message' => 'Hi! Looking to remodel our master bed and bathroom.',
    ], $overrides);
}

function classifierSaysLead(): void
{
    test()->partialMock(CrewLeadEmailService::class, fn ($mock) => $mock->shouldReceive('classify')->andReturn(['is_lead' => true, 'confidence' => 0.9, 'reason' => 'remodel']));
}

it('completes a first name from the sign-off in the message and asks nothing', function () {
    emailIntakeFixture();
    Queue::fake();
    Http::fake(['*' => Http::response([], 200)]);
    classifierSaysLead();

    $this->postJson('/api/v1/leads', websiteLeadPayload(['message' => "Hi! Looking to remodel our master bath.\n\nThanks,\nKatherine Brown"]))->assertCreated();

    $lead = Lead::withoutGlobalScopes()->latest('id')->firstOrFail();
    expect($lead->lead_data['name'])->toBe('Katherine Brown')
        ->and(User::find($lead->user_id)?->last_name)->toBe('Brown')
        ->and($lead->lead_data['missing_info_requested_at'] ?? null)->toBeNull();
    Queue::assertNotPushed(SendLeadReplyJob::class);
});

it('completes a first name from the address when the message does not sign', function () {
    emailIntakeFixture();
    Queue::fake();
    Http::fake(['*' => Http::response([], 200)]);
    classifierSaysLead();

    $this->postJson('/api/v1/leads', websiteLeadPayload(['email' => 'katherinebrown521@gmail.com']))->assertCreated();

    $lead = Lead::withoutGlobalScopes()->latest('id')->firstOrFail();
    expect($lead->lead_data['name'])->toBe('Katherine Brown')
        ->and(User::find($lead->user_id)?->last_name)->toBe('Brown');
    Queue::assertNotPushed(SendLeadReplyJob::class);
});

it('asks the sender for their last name, once, when neither the message nor the address gives it', function () {
    emailIntakeFixture();
    Queue::fake();
    Http::fake(['*' => Http::response([], 200)]);
    classifierSaysLead();

    $this->postJson('/api/v1/leads', websiteLeadPayload(['name' => 'Bob', 'email' => 'bobby77@gmail.com']))->assertCreated();

    $lead = Lead::withoutGlobalScopes()->latest('id')->firstOrFail();
    expect($lead->lead_data['name'])->toBe('Bob')
        ->and($lead->user_id)->toBeNull()
        ->and($lead->lead_data['missing_info_requested_at'] ?? null)->not->toBeNull();

    Queue::assertPushed(SendLeadReplyJob::class, function ($job) {
        $body = (fn () => $this->body)->call($job);
        $subject = (fn () => $this->subject)->call($job);

        return str_contains($body, 'Hi Bob,')
            && str_contains($body, 'your last name')
            && ! str_contains($body, 'phone number')
            && ! str_contains($body, 'project address')
            && str_starts_with($subject, 'Your project enquiry');
    });
    Queue::assertPushed(SendLeadReplyJob::class, 1);
});

it('names everything missing at once, and never writes to a solicitation', function () {
    emailIntakeFixture();
    Queue::fake();
    Http::fake(['*' => Http::response([], 200)]);
    classifierSaysLead();

    $this->postJson('/api/v1/leads', websiteLeadPayload(['name' => 'Bob', 'email' => 'bobby77@gmail.com', 'phone' => null, 'address' => null, 'city' => null]))->assertCreated();

    Queue::assertPushed(SendLeadReplyJob::class, function ($job) {
        $body = (fn () => $this->body)->call($job);

        return str_contains($body, 'your last name, the project address and the best phone number');
    });

    Queue::fake();
    $this->partialMock(CrewLeadEmailService::class, fn ($mock) => $mock->shouldReceive('classify')->andReturn(['is_lead' => false, 'confidence' => 0.95, 'reason' => 'SEO pitch']));

    $this->postJson('/api/v1/leads', websiteLeadPayload([
        'name' => 'Sam', 'email' => 'sam@seo-agency.example',
        'message' => 'We can rank your construction website on page one of Google within thirty days, guaranteed, with our proven SEO packages starting at ninety-nine dollars a month.',
    ]))->assertCreated();

    Queue::assertNotPushed(SendLeadReplyJob::class);
});
