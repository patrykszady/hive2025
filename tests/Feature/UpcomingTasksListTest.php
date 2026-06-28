<?php

use App\Models\Task;

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