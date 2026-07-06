<?php

use App\Models\Client;
use App\Models\CompanyEmail;
use App\Models\Project;
use App\Models\Task;
use App\Models\Vendor;
use App\Services\MeetTaskCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

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
