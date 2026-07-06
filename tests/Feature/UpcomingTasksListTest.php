<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders pending tasks collapsed when configured', function (): void {
    $html = view('components.upcoming-tasks-list', [
        'groupedTasks' => collect(),
        'laterTasks' => null,
        'taskCount' => 1,
        'showAvatars' => true,
        'clickable' => true,
        'unscheduledTasks' => collect([
            tap(Task::make([
                'title' => 'Unscheduled task',
            ]), function (Task $task): void {
                $task->setRelation('users', collect());
                $task->setRelation('vendor', null);
                $task->setRelation('project', null);
            }),
        ]),
        'showProjectInfo' => false,
        'showVendorInfo' => true,
        'showNotifications' => false,
        'publicView' => false,
        'pendingTasksExpanded' => false,
        'title' => 'Tasks',
        'emptyMessage' => 'No tasks upcoming for this project.',
        'projectId' => null,
        'clientId' => null,
        'showAddTask' => false,
    ])->render();

    expect($html)->toContain('Pending Tasks');
    expect($html)->not->toContain('expanded');
});

it('expands the Later accordion when configured', function (): void {
    $laterDate = Carbon::now()->addDays(10)->startOfDay();

    $task = tap(Task::make([
        'title' => 'Later task',
    ]), function (Task $task): void {
        $task->setRelation('users', collect());
        $task->setRelation('vendor', null);
        $task->setRelation('project', null);
    });

    $laterTasks = collect([
        $laterDate->format('Y-m-d') => collect([$task]),
    ]);

    $expandedHtml = view('components.upcoming-tasks-list-later', [
        'laterTasks' => $laterTasks,
        'showAvatars' => false,
        'clickable' => false,
        'showProjectInfo' => false,
        'showVendorInfo' => false,
        'publicView' => true,
        'expanded' => true,
    ])->render();

    expect($expandedHtml)->toContain('Later');
    expect($expandedHtml)->toContain('open: true');

    $collapsedHtml = view('components.upcoming-tasks-list-later', [
        'laterTasks' => $laterTasks,
        'showAvatars' => false,
        'clickable' => false,
        'showProjectInfo' => false,
        'showVendorInfo' => false,
        'publicView' => true,
        'expanded' => false,
    ])->render();

    expect($collapsedHtml)->toContain('Later');
    expect($collapsedHtml)->toContain('open: false');
    expect($collapsedHtml)->not->toContain('open: true');
});

it('renders grouped task dates without the year', function (): void {
    $date = Carbon::create(2026, 7, 2)->startOfDay();

    $ownerVendor = Vendor::factory()->create([
        'business_name' => 'GS Construction',
        'short_name' => 'GS',
        'options' => '{}',
    ]);

    $subjectVendor = Vendor::factory()->create([
        'business_name' => 'RG Tile',
        'short_name' => 'RG',
        'options' => '{}',
    ]);

    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Test Project',
        'client_id' => $client->id,
        'address' => '239 Perth Rd',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => '60013',
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]));

    $task = Task::withoutEvents(fn () => Task::create([
        'title' => 'Scheduled task',
        'type' => 'task',
        'order' => 1,
        'project_id' => $project->id,
        'vendor_id' => $subjectVendor->id,
        'belongs_to_vendor_id' => $ownerVendor->id,
        'created_by_user_id' => 1,
        'vendor_status' => Task::VENDOR_STATUS_CONFIRMED,
        'start_date' => $date,
        'end_date' => $date,
    ]));

    $task->setRelation('users', collect());
    $task->setRelation('vendor', $subjectVendor);
    $task->setRelation('project', $project);

    $html = view('components.upcoming-tasks-list', [
        'groupedTasks' => collect([
            $date->format('Y-m-d') => collect([
                $task,
            ]),
        ]),
        'laterTasks' => null,
        'taskCount' => 1,
        'showAvatars' => true,
        'clickable' => true,
        'unscheduledTasks' => collect(),
        'showProjectInfo' => false,
        'showVendorInfo' => true,
        'showNotifications' => false,
        'publicView' => true,
        'pendingTasksExpanded' => false,
        'title' => 'Tasks',
        'emptyMessage' => 'No tasks upcoming for this project.',
        'projectId' => null,
        'clientId' => null,
        'showAddTask' => false,
    ])->render();

    expect($html)->toContain($date->format('D, M j'));
    expect($html)->not->toContain($date->format('D, M j, Y'));
});

it('collapses past tasks into a Past Tasks accordion in the non-public view', function (): void {
    $pastDate = Carbon::now()->subDays(3)->startOfDay();

    $ownerVendor = Vendor::factory()->create(['options' => '{}']);
    $subjectVendor = Vendor::factory()->create(['options' => '{}']);
    $client = Client::factory()->create();

    $project = Project::withoutEvents(fn () => Project::create([
        'project_name' => 'Past Project',
        'client_id' => $client->id,
        'address' => '1 Old St',
        'city' => 'Cary',
        'state' => 'IL',
        'zip_code' => '60013',
        'belongs_to_vendor_id' => $ownerVendor->id,
    ]));

    $task = Task::withoutEvents(fn () => Task::create([
        'title' => 'Past task',
        'type' => 'task',
        'order' => 1,
        'project_id' => $project->id,
        'vendor_id' => $subjectVendor->id,
        'belongs_to_vendor_id' => $ownerVendor->id,
        'created_by_user_id' => 1,
        'start_date' => $pastDate,
        'end_date' => $pastDate,
    ]));

    $task->setRelation('users', collect());
    $task->setRelation('vendor', $subjectVendor);
    $task->setRelation('project', $project);

    $html = view('components.upcoming-tasks-list', [
        'groupedTasks' => collect([
            $pastDate->format('Y-m-d') => collect([$task]),
        ]),
        'laterTasks' => null,
        'taskCount' => 1,
        'showAvatars' => true,
        'clickable' => true,
        'unscheduledTasks' => collect(),
        'showProjectInfo' => false,
        'showVendorInfo' => true,
        'showNotifications' => false,
        'publicView' => false,
        'pendingTasksExpanded' => false,
        'title' => 'Tasks',
        'emptyMessage' => 'No tasks upcoming for this project.',
        'projectId' => null,
        'clientId' => null,
        'showAddTask' => false,
    ])->render();

    expect($html)->toContain('Past Tasks')
        ->and($html)->toContain('Past task');
});
