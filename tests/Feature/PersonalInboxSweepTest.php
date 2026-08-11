<?php

use App\Models\CrewEmailIngest;
use App\Models\Lead;
use App\Models\User;
use App\Models\Vendor;
use App\Services\CrewLeadEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Leads reply to whichever address last emailed them — Kathy's phone number
 * sat in patryk@'s inbox for days. The sweep reads the team's own inboxes
 * for replies from known lead senders and runs them through the reply
 * pipeline. It never creates leads and never touches team/system mail.
 */
function sweepFixture(array $messages): array
{
    Cache::flush();

    Config::set('nylas.crew_leads.grant_ids', ['grant-patryk']);
    Config::set('nylas.api_key', 'test-key');
    Config::set('nylas.api_uri', 'https://api.us.nylas.com');
    Config::set('services.openai.api_key', ''); // contact mining stays inert

    $vendor = Vendor::factory()->create();
    $user = new User();
    $user->forceFill([
        'first_name' => 'Crew', 'last_name' => 'Member',
        'email' => 'sweep.'.uniqid().'@example.test',
        'cell_phone' => fake()->unique()->numerify('224555####'),
        'primary_vendor_id' => $vendor->id,
    ]);
    $user->save();

    $lead = Lead::withoutEvents(fn () => Lead::create([
        'date' => now(),
        'origin' => 'Email',
        'lead_data' => ['name' => 'Kathy Moseler', 'email' => 'kathy@example.test'],
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => $user->id,
    ]));

    Http::fake([
        'api.us.nylas.com/v3/grants/grant-patryk/folders*' => Http::response([
            'data' => [['id' => 'folder-inbox', 'name' => 'Inbox', 'attributes' => ['\\Inbox']]],
        ]),
        'api.us.nylas.com/v3/grants/grant-patryk/messages*' => Http::response(['data' => $messages]),
        'api.us.nylas.com/v3/grants/grant-patryk' => Http::response(['data' => ['email' => 'patryk@gs.construction']]),
    ]);

    return ['lead' => $lead];
}

it('files a personal-inbox reply from a known lead and hands the ball back', function () {
    $fx = sweepFixture([[
        'id' => 'msg-kathy-1',
        'from' => [['email' => 'kathy@example.test', 'name' => 'Kathy Moseler']],
        'to' => [['email' => 'patryk@gs.construction']],
        'subject' => 'Re: Termite Repair Bid Request',
        'date' => now()->timestamp,
        'body' => '<p>My cell phone number is 847-222-3351.</p>',
    ]]);

    $fx['lead']->setStatus('Replied');

    $out = app(CrewLeadEmailService::class)->sweepPersonalInboxes();

    expect($out['replies'])->toBe(1);

    $lead = $fx['lead']->fresh();
    expect($lead->lead_data['email_replies'][0]['body'])->toContain('847-222-3351')
        ->and($lead->last_status->title)->toBe('New');

    expect(CrewEmailIngest::where('nylas_message_id', 'msg-kathy-1')->value('lead_id'))->toBe($lead->id);
});

it('ignores unknown senders, team mail, and already-captured messages', function () {
    $fx = sweepFixture([
        [
            'id' => 'msg-stranger',
            'from' => [['email' => 'stranger@example.test']],
            'subject' => 'Hello', 'date' => now()->timestamp, 'body' => 'hi',
        ],
        [
            'id' => 'msg-team',
            'from' => [['email' => 'greg@gs.construction']],
            'subject' => 'Internal', 'date' => now()->timestamp, 'body' => 'yo',
        ],
        [
            'id' => 'msg-dupe',
            'from' => [['email' => 'kathy@example.test']],
            'subject' => 'Re: again', 'date' => now()->timestamp, 'body' => 'same one',
        ],
    ]);

    CrewEmailIngest::create([
        'nylas_message_id' => 'msg-dupe',
        'grant_id' => 'grant-crew',
        'mailbox' => 'crew@gs.construction',
        'status' => CrewEmailIngest::STATUS_SKIPPED,
        'skip_reason' => 'reply',
        'is_lead' => false,
    ]);

    $out = app(CrewLeadEmailService::class)->sweepPersonalInboxes();

    expect($out['replies'])->toBe(0)
        ->and(Lead::withoutGlobalScopes()->count())->toBe(1)
        ->and(count((array) ($fx['lead']->fresh()->lead_data['email_replies'] ?? [])))->toBe(0);
});
