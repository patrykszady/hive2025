<?php

use App\Models\Client;
use App\Models\CompanyEmail;
use App\Models\Lead;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ShortLink;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use App\Services\MeetTaskCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates an all-day nylas event when meet task has no time set', function (): void {
    config([
        'nylas.api_key' => 'test-key',
        'nylas.meet.enabled' => true,
        'nylas.meet.dev_recipient' => 'dev@example.test',
    ]);

    $ownerVendor = Vendor::factory()->create([
        'business_name' => 'PMG',
    ]);

    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Framing/Foundation Consult',
        'client_id' => $client->id,
        'address' => '3154 Violet Ln',
        'city' => 'Northbrook',
        'state' => 'IL',
        'zip_code' => '60062',
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]));

    CompanyEmail::withoutGlobalScopes()->create([
        'vendor_id' => $ownerVendor->id,
        'email' => 'calendar@pmg.test',
        'grant_id' => 'grant_123',
    ]);

    $task = Task::withoutEvents(fn () => Task::create([
        'title' => 'Framing/Foundation Consult',
        'type' => 'Meet',
        'order' => 1,
        'project_id' => $project->id,
        'vendor_id' => null,
        'user_ids' => [],
        'notes' => null,
        'belongs_to_vendor_id' => $ownerVendor->id,
        'created_by_user_id' => 1,
        'start_date' => '2026-07-03',
        'end_date' => '2026-07-03',
        'options' => [
            'meeting_participants' => ['external@example.test'],
            'time_settings' => [
                '2026-07-03' => [
                    'use_time' => false,
                    'start_time' => null,
                    'end_time' => null,
                ],
            ],
        ],
    ]));

    Http::fake([
        'https://api.us.nylas.com/v3/grants/grant_123/calendars*' => Http::response([
            'data' => [
                ['id' => 'cal_abc', 'is_primary' => true],
            ],
        ], 200),
        'https://api.us.nylas.com/v3/grants/grant_123/events*' => Http::response([
            'data' => ['id' => 'evt_123'],
        ], 200),
    ]);

    app(MeetTaskCalendarService::class)->createMeetEvent($task);

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/grants/grant_123/events')) {
            return false;
        }

        $body = $request->data();

        return ($body['when']['date'] ?? null) === '2026-07-03'
            && ! isset($body['when']['start_time'])
            && ! isset($body['when']['end_time']);
    });
});

/**
 * A Meet task wired up enough to render an invite description.
 *
 * @return array{task: Task, project: Project, client: Client, vendor: Vendor}
 */
function meetInviteFixture(int $statusCode): array
{
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Basement',
        'client_id' => $client->id,
        'address' => '10 Rugby Rd',
        'city' => 'Lake Zurich',
        'state' => 'IL',
        'zip_code' => '60047',
        'belongs_to_vendor_id' => $vendor->id,
    ]));

    ProjectStatus::withoutEvents(fn () => ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'status_code' => $statusCode,
        'start_date' => now(),
    ]));

    $task = Task::withoutEvents(fn () => Task::create([
        'title' => 'GS/Client Consult',
        'type' => 'Meet',
        'order' => 1,
        'project_id' => $project->id,
        'user_ids' => [],
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => 1,
        'start_date' => '2026-08-25',
        'end_date' => '2026-08-25',
        'options' => ['meeting_location_type' => 'in_person'],
    ]));

    return ['task' => $task, 'project' => $project, 'client' => $client, 'vendor' => $vendor];
}

function meetInviteDescription(Task $task): string
{
    $service = app(MeetTaskCalendarService::class);
    $method = new ReflectionMethod($service, 'buildDescription');
    $method->setAccessible(true);

    return $method->invoke($service, $task->fresh(['project.client', 'project.latestStatus']), collect());
}

function meetInviteLinkDestination(string $description): string
{
    $url = Str::before(Str::after($description, 'Reschedule here: <a href="'), '"');

    return ShortLink::where('code', Str::afterLast($url, '/'))->value('destination') ?? $url;
}

it('links a consult invite to the lead pick-times page instead of asking people to reach out', function (): void {
    $fx = meetInviteFixture(statusCode: 2);

    $contact = User::query()->create([
        'first_name' => 'Zachary',
        'last_name' => 'Wong',
        'email' => 'zach.meet-invite@example.com',
        'cell_phone' => '8478759229',
    ]);
    $fx['client']->users()->attach($contact->id);

    $lead = Lead::create([
        'date' => now(),
        'origin' => 'gs.construction',
        'user_id' => $contact->id,
        'belongs_to_vendor_id' => $fx['vendor']->id,
        'created_by_user_id' => $contact->id,
        'lead_data' => ['name' => 'Zachary Wong', 'message' => 'Basement'],
    ]);

    $description = meetInviteDescription($fx['task']);

    expect($description)
        ->toContain('Need a different time? Reschedule here:')
        ->not->toContain('please reach out to reschedule');

    expect(meetInviteLinkDestination($description))
        ->toContain('/lead/times/'.$lead->id)
        ->toContain('signature=');
});

it('links a non-consult meet invite to the project schedule page', function (): void {
    $fx = meetInviteFixture(statusCode: 6);

    $description = meetInviteDescription($fx['task']);

    expect($description)->toContain('Need a different time? Reschedule here:');

    expect(meetInviteLinkDestination($description))
        ->toContain('/s/'.$fx['project']->fresh()->schedule_token);
});

it('keeps the reach-out wording when there is nothing to link to', function (): void {
    // tasks.project_id is NOT NULL, so the only way to reach the fallback is a
    // task whose project no longer resolves.
    $task = meetInviteFixture(statusCode: 6)['task'];
    $task->setRelation('project', null);

    $service = app(MeetTaskCalendarService::class);
    $method = new ReflectionMethod($service, 'buildDescription');
    $method->setAccessible(true);

    expect($method->invoke($service, $task, collect()))
        ->toContain('Should anything change, please reach out to reschedule.')
        ->not->toContain('Reschedule here:');
});

/**
 * A Meet whose invite is built for real — outside local/testing, where the
 * service otherwise redirects every recipient to the dev address.
 *
 * @return array{task: Task, vendor: Vendor}
 */
function meetRecipientFixture(array $participants): array
{
    config([
        'nylas.api_key' => 'test-key',
        'nylas.meet.enabled' => true,
    ]);

    app()->detectEnvironment(fn () => 'production');

    $vendor = Vendor::factory()->create([
        'business_name' => 'GS Construction',
        'business_email' => 'crew@gs.test',
    ]);

    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Bedroom',
        'client_id' => $client->id,
        'address' => '806 Parkside',
        'city' => 'Lake Zurich',
        'state' => 'IL',
        'zip_code' => '60047',
        'belongs_to_vendor_id' => $vendor->id,
    ]));

    // Two company mailboxes, both with calendar grants — the organizer's and a
    // colleague's.
    CompanyEmail::withoutGlobalScopes()->create([
        'vendor_id' => $vendor->id,
        'email' => 'patryk@gs.test',
        'grant_id' => 'grant_123',
    ]);
    CompanyEmail::withoutGlobalScopes()->create([
        'vendor_id' => $vendor->id,
        'email' => 'greg@gs.test',
        'grant_id' => 'grant_456',
    ]);

    $task = Task::withoutEvents(fn () => Task::create([
        'title' => 'GS Construction | Andersen | Consult',
        'type' => 'Meet',
        'order' => 1,
        'project_id' => $project->id,
        'vendor_id' => null,
        'user_ids' => [],
        'belongs_to_vendor_id' => $vendor->id,
        'created_by_user_id' => 1,
        'start_date' => '2026-08-05',
        'end_date' => '2026-08-05',
        'options' => [
            'meeting_participants' => $participants,
            'time_settings' => ['2026-08-05' => ['use_time' => false]],
        ],
    ]));

    Http::fake([
        'https://api.us.nylas.com/v3/grants/*/calendars*' => Http::response([
            'data' => [['id' => 'cal_abc', 'is_primary' => true]],
        ], 200),
        'https://api.us.nylas.com/v3/grants/*/events*' => Http::response([
            'data' => ['id' => 'evt_123'],
        ], 200),
    ]);

    return ['task' => $task, 'vendor' => $vendor];
}

function sentInviteParticipants(): array
{
    $found = [];

    Http::assertSent(function ($request) use (&$found): bool {
        if (! str_contains($request->url(), '/events')) {
            return false;
        }

        $found = collect($request->data()['participants'] ?? [])
            ->pluck('email')
            ->sort()
            ->values()
            ->all();

        return true;
    });

    return $found;
}

it('invites exactly who is on the participant list, and nobody else', function (): void {
    $fx = meetRecipientFixture(['patryk@gs.test', 'andersensarah924@example.test']);

    app(MeetTaskCalendarService::class)->createMeetEvent($fx['task']);

    // greg@ is a company mailbox on the same vendor. It used to be merged onto
    // every Meet invite regardless of who the task actually listed.
    expect(sentInviteParticipants())->toBe(['andersensarah924@example.test', 'patryk@gs.test']);
});

it('falls back to the company contacts when a Meet lists nobody', function (): void {
    $fx = meetRecipientFixture([]);

    app(MeetTaskCalendarService::class)->createMeetEvent($fx['task']);

    // Nothing to honour, so the old safety net still applies — better a
    // company mailbox than an invite with no one on it. The vendor's own
    // generic address stays out.
    expect(sentInviteParticipants())->toBe(['greg@gs.test', 'patryk@gs.test']);
});
