<?php

use App\Jobs\SendServiceCallScheduledSmsToClient;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\ClientServiceScheduledNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-07-09 10:00:00', 'America/Chicago'));
});

afterEach(function (): void {
    Carbon\Carbon::setTestNow();
});

/**
 * @return array{project: Project, client: Client, vendor: Vendor, clientUser: User}
 */
function makeServiceCallForScheduledSms(int $statusCode = 8): array
{
    $client = Client::factory()->create(['business_name' => 'Homeowner Household']);
    $vendor = Vendor::factory()->create(['business_name' => 'GS Construction']);

    $clientUser = User::query()->create([
        'first_name' => 'Tom',
        'last_name' => 'Homeowner',
        'email' => 'tom.homeowner-' . uniqid() . '@example.test',
        'cell_phone' => '2245550111',
    ]);
    $client->users()->attach($clientUser->id);

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Service Call Project',
        'client_id' => $client->id,
        'belongs_to_vendor_id' => $vendor->id,
        'address' => '100 Main St',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => '60013',
    ]));

    $project->forceFill(['schedule_token' => 'sched-' . uniqid()])->saveQuietly();

    ProjectStatus::withoutEvents(fn () => ProjectStatus::create([
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $vendor->id,
        'status_code' => $statusCode,
        'start_date' => now(),
    ]));

    return [
        'project' => $project->fresh(),
        'client' => $client,
        'vendor' => $vendor,
        'clientUser' => $clientUser,
    ];
}

it('texts the homeowner the scheduled service-call tasks', function (): void {
    Notification::fake();

    ['project' => $project, 'clientUser' => $clientUser] = makeServiceCallForScheduledSms(8);

    Task::withoutEvents(fn () => Task::create([
        'title' => 'Fix Electrical Outlet',
        'type' => 'task',
        'order' => 1,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
        'created_by_user_id' => 1,
        'start_date' => '2026-07-09',
        'end_date' => '2026-07-09',
        'options' => [
            'dates' => ['2026-07-09'],
            'time_settings' => [
                '2026-07-09' => ['use_time' => true, 'start_time' => '07:00', 'end_time' => '09:00'],
            ],
        ],
    ]));

    (new SendServiceCallScheduledSmsToClient($project->id))->handle();

    Notification::assertSentTo(
        $clientUser,
        ClientServiceScheduledNotification::class,
        function (ClientServiceScheduledNotification $notification) {
            return $notification->tasks->contains(fn (Task $task) => $task->title === 'Fix Electrical Outlet');
        }
    );
});

it('does not text the homeowner for a non service-call project', function (): void {
    Notification::fake();

    ['project' => $project] = makeServiceCallForScheduledSms(6); // Active, not Service Call

    Task::withoutEvents(fn () => Task::create([
        'title' => 'Fix Electrical Outlet',
        'type' => 'task',
        'order' => 1,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
        'created_by_user_id' => 1,
        'start_date' => '2026-07-09',
        'end_date' => '2026-07-09',
    ]));

    (new SendServiceCallScheduledSmsToClient($project->id))->handle();

    Notification::assertNothingSent();
});

it('does not text when there are no scheduled tasks', function (): void {
    Notification::fake();

    ['project' => $project] = makeServiceCallForScheduledSms(8);

    Task::withoutEvents(fn () => Task::create([
        'title' => 'Pending Task',
        'type' => 'task',
        'order' => 1,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
        'created_by_user_id' => 1,
        'vendor_status' => Task::VENDOR_STATUS_REQUESTED,
    ]));

    (new SendServiceCallScheduledSmsToClient($project->id))->handle();

    Notification::assertNothingSent();
});

it('dispatches the batched homeowner SMS job when a task is scheduled', function (): void {
    Bus::fake();

    ['project' => $project, 'vendor' => $vendor] = makeServiceCallForScheduledSms(8);

    $owner = User::query()->create([
        'first_name' => 'Owner',
        'last_name' => 'User',
        'email' => 'owner-' . uniqid() . '@example.test',
        'cell_phone' => '2245550122',
        'primary_vendor_id' => $vendor->id,
    ]);
    $vendor->users()->attach($owner->id, ['is_employed' => true, 'role_id' => 1]);

    $this->actingAs($owner);

    $task = Task::withoutEvents(fn () => Task::create([
        'title' => 'Fix Electrical Outlet',
        'type' => 'task',
        'order' => 1,
        'project_id' => $project->id,
        'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
        'created_by_user_id' => $owner->id,
    ]));

    $task->update([
        'start_date' => '2026-07-16',
        'end_date' => '2026-07-16',
        'options' => ['dates' => ['2026-07-16']],
    ]);

    Bus::assertDispatched(SendServiceCallScheduledSmsToClient::class, function ($job) use ($project) {
        return $job->projectId === $project->id;
    });
});
