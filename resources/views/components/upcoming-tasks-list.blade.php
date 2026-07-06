@props([
    'groupedTasks',
    'laterTasks' => null,
    'taskCount' => 0,
    'showAvatars' => true,
    'clickable' => true,
    'unscheduledTasks' => null,
    'showProjectInfo' => false,
    'showVendorInfo' => true,
    'showNotifications' => true,
    'publicView' => false,
    'pendingTasksExpanded' => true,
    'laterTasksExpanded' => false,
    'title' => 'Tasks',
    'emptyMessage' => 'No tasks upcoming for this project.',
    'projectId' => null,
    'clientId' => null,
    'showAddTask' => false,
])

<x-island-card heading="{{ $title }}">
    <x-slot:badge>
        <flux:badge size="sm" color="zinc">{{ $taskCount }}</flux:badge>
    </x-slot:badge>
    <x-slot:actions>
        <div class="flex items-center gap-2">
            @if($projectId && $clickable)
                <flux:button
                    size="sm"
                    variant="ghost"
                    icon="plus"
                    wire:click="$dispatchTo('tasks.task-create', 'addTask', { project_id: {{ $projectId }} })"
                />
            @elseif($showAddTask && $clickable)
                <flux:button
                    size="sm"
                    variant="ghost"
                    icon="plus"
                    wire:click="$dispatchTo('tasks.task-create', 'addTask', { {{ $clientId ? 'client_id: ' . $clientId . ', ' : '' }}user_ids: [{{ auth()->id() }}] })"
                />
            @endif
            @if($showNotifications)
                @auth
                    <flux:button
                        size="sm"
                        variant="filled"
                        :href="route('users.show', auth()->id())"
                        icon="bell"
                        class="!bg-indigo-500 hover:!bg-indigo-600 !text-white"
                    >
                        Notifications
                    </flux:button>
                @endauth
            @endif
        </div>
    </x-slot:actions>

    @if($groupedTasks->isEmpty() && (!$unscheduledTasks || $unscheduledTasks->isEmpty()))
        <flux:text class="text-zinc-500">{{ $emptyMessage }}</flux:text>
    @else
        <div class="space-y-4">
            @if($unscheduledTasks && $unscheduledTasks->isNotEmpty())
                <flux:accordion transition>
                    <flux:accordion.item :expanded="$pendingTasksExpanded">
                        <flux:accordion.heading>
                            <div class="flex items-center gap-2">
                                <span class="text-orange-600 dark:text-orange-400">Pending Tasks</span>
                                <flux:badge color="amber" size="sm">{{ $unscheduledTasks->count() }}</flux:badge>
                            </div>
                        </flux:accordion.heading>
                        <flux:accordion.content>
                            <div class="space-y-2">
                                @if($showProjectInfo)
                                    @php
                                        $unscheduledByProject = $unscheduledTasks->groupBy(fn ($task) => $task->project_id ?? 0);
                                        $isPublicView = $publicView ?? false;
                                    @endphp
                                    @foreach($unscheduledByProject as $uProjectId => $projectTasks)
                                        @php
                                            $project = $projectTasks->first()->project;
                                            $latestStatus = $project?->latestStatus;
                                        @endphp
                                        <flux:kanban class="w-full [&>div]:w-full [&>div]:min-w-0 [&>div]:flex-1">
                                            <flux:kanban.column class="!w-full !max-w-full bg-white dark:bg-zinc-900 rounded-lg border border-zinc-200 dark:border-zinc-700">
                                                <flux:kanban.column.header
                                                    class="min-w-0 w-full [&>div:first-child>div:first-child]:!min-w-0 [&>div:first-child>div:first-child]:!flex-1 [&>div:first-child>div:first-child]:truncate [&>div:first-child>div:last-child]:!shrink-0 [&_[data-flux-subheading]]:!min-w-0 [&_[data-flux-subheading]]:truncate"
                                                >
                                                    <flux:heading class="min-w-0 truncate flex items-center gap-2">
                                                        @if($isPublicView)
                                                            <span class="truncate">{{ $project->project_name ?? 'Project' }}</span>
                                                        @else
                                                            <a
                                                                href="{{ $project ? route('projects.show', $project) : '#' }}"
                                                                wire:click.stop
                                                                class="truncate hover:underline underline-offset-2"
                                                            >
                                                                {{ $project->short_address ?? 'No project' }}
                                                            </a>
                                                        @endif
                                                        @if($latestStatus)
                                                            <flux:tooltip content="{{ $latestStatus->title }}">
                                                                <flux:badge :color="$latestStatus->badge_color" size="sm" class="!px-0 !size-2 !min-w-0 rounded-full shrink-0" />
                                                            </flux:tooltip>
                                                        @endif
                                                    </flux:heading>
                                                    @if(!$isPublicView && ($project?->client || $project?->project_name))
                                                        <x-slot name="subheading">
                                                            <span class="block min-w-0 truncate">
                                                                {{ $project->client?->last_names }}{{ $project->client?->last_names && $project->project_name ? ' | ' : '' }}{{ $project->project_name }}
                                                            </span>
                                                        </x-slot>
                                                    @endif
                                                </flux:kanban.column.header>
                                                <flux:kanban.column.cards>
                                                    @foreach($projectTasks as $task)
                                                        @php
                                                            $typeUi = $task->type_ui ?? [];
                                                            $taskTypeTextClasses = data_get($typeUi, 'text', '');
                                                            $taskUsers = $task->users ?? collect();
                                                            $taskVendor = $task->vendor ?? null;
                                                        @endphp
                                                        @if($clickable)
                                                            <flux:kanban.card
                                                                as="button"
                                                                class="min-w-0 w-full {{ $task->trashed() ? 'opacity-50' : '' }}"
                                                                data-task-card="{{ $task->id }}"
                                                                wire:key="unscheduled-task-{{ $task->id }}"
                                                                wire:click="$dispatchTo('tasks.task-create', 'editTask', { task: {{ $task->id }} })"
                                                            >
                                                                <div class="flex items-start justify-between gap-2 min-w-0">
                                                                    <div class="flex items-center gap-2 min-w-0">
                                                                        <flux:heading size="sm" class="min-w-0 truncate {{ $taskTypeTextClasses }} {{ $task->trashed() ? 'line-through' : '' }}">
                                                                            {{ $task->title }}
                                                                        </flux:heading>
                                                                    </div>
                                                                    <x-task-preferred-indicator :task="$task" />
                                                                </div>
                                                                @if($showAvatars && ($taskUsers->count() > 0 || $taskVendor))
                                                                    <div class="flex items-center gap-2 mt-2 min-w-0">
                                                                        @if($taskUsers->count() > 0)
                                                                            <flux:avatar.group>
                                                                                @foreach($taskUsers->take(3) as $user)
                                                                                    <flux:avatar circle size="xs" name="{{ $user->full_name }}" color="auto" color:seed="{{ $user->id }}" title="{{ $user->full_name }}" />
                                                                                @endforeach
                                                                                @if($taskUsers->count() > 3)
                                                                                    <flux:avatar circle size="xs">{{ $taskUsers->count() - 3 }}+</flux:avatar>
                                                                                @endif
                                                                            </flux:avatar.group>
                                                                        @endif
                                                                        @if($taskVendor)
                                                                            <flux:avatar circle size="xs" name="{{ $taskVendor->name }}" color="auto" color:seed="{{ $taskVendor->id }}" :title="($showVendorInfo ?? true) ? $taskVendor->name : null" />
                                                                            @if($showVendorInfo ?? true)
                                                                                <span class="flex-1 min-w-0 truncate text-xs text-zinc-600 dark:text-zinc-400">{{ $taskVendor->name }}</span>
                                                                            @endif
                                                                        @endif
                                                                    </div>
                                                                @endif
                                                            </flux:kanban.card>
                                                        @else
                                                            <flux:kanban.card
                                                                class="min-w-0 w-full transition hover:bg-zinc-50 dark:hover:bg-zinc-800 hover:shadow-sm hover:border-zinc-300 dark:hover:border-zinc-600 {{ $task->trashed() ? 'opacity-50' : '' }}"
                                                                data-task-card="{{ $task->id }}"
                                                                wire:key="unscheduled-task-{{ $task->id }}"
                                                            >
                                                                <div class="flex items-start justify-between gap-2 min-w-0">
                                                                    <div class="flex items-center gap-2 min-w-0">
                                                                        <flux:heading size="sm" class="min-w-0 truncate {{ $taskTypeTextClasses }} {{ $task->trashed() ? 'line-through' : '' }}">
                                                                            {{ $task->title }}
                                                                        </flux:heading>
                                                                    </div>
                                                                    <x-task-preferred-indicator :task="$task" />
                                                                </div>
                                                                @if($showAvatars && ($taskUsers->count() > 0 || $taskVendor))
                                                                    <div class="flex items-center gap-2 mt-2 min-w-0">
                                                                        @if($taskUsers->count() > 0)
                                                                            <flux:avatar.group>
                                                                                @foreach($taskUsers->take(3) as $user)
                                                                                    <flux:avatar circle size="xs" name="{{ $user->full_name }}" color="auto" color:seed="{{ $user->id }}" title="{{ $user->full_name }}" />
                                                                                @endforeach
                                                                                @if($taskUsers->count() > 3)
                                                                                    <flux:avatar circle size="xs">{{ $taskUsers->count() - 3 }}+</flux:avatar>
                                                                                @endif
                                                                            </flux:avatar.group>
                                                                        @endif
                                                                        @if($taskVendor)
                                                                            <flux:avatar circle size="xs" name="{{ $taskVendor->name }}" color="auto" color:seed="{{ $taskVendor->id }}" :title="($showVendorInfo ?? true) ? $taskVendor->name : null" />
                                                                            @if($showVendorInfo ?? true)
                                                                                <span class="flex-1 min-w-0 truncate text-xs text-zinc-600 dark:text-zinc-400">{{ $taskVendor->name }}</span>
                                                                            @endif
                                                                        @endif
                                                                    </div>
                                                                @endif
                                                            </flux:kanban.card>
                                                        @endif
                                                    @endforeach
                                                </flux:kanban.column.cards>
                                            </flux:kanban.column>
                                        </flux:kanban>
                                    @endforeach
                                @else
                                    @foreach($unscheduledTasks as $task)
                                        @php
                                            $typeUi = $task->type_ui ?? [];
                                            $taskTypeTextClasses = data_get($typeUi, 'text', '');
                                            $taskUsers = $task->users ?? collect();
                                            $taskVendor = $task->vendor ?? null;
                                        @endphp
                                        @if($clickable)
                                            <flux:kanban.card
                                                as="button"
                                                class="min-w-0 w-full {{ $task->trashed() ? 'opacity-50' : '' }}"
                                                data-task-card="{{ $task->id }}"
                                                wire:key="unscheduled-task-{{ $task->id }}"
                                                wire:click="$dispatchTo('tasks.task-create', 'editTask', { task: {{ $task->id }} })"
                                            >
                                                <div class="flex items-start justify-between gap-2 min-w-0">
                                                    <div class="flex items-center gap-2 min-w-0">
                                                        <flux:heading size="sm" class="min-w-0 truncate {{ $taskTypeTextClasses }} {{ $task->trashed() ? 'line-through' : '' }}">
                                                            {{ $task->title }}
                                                        </flux:heading>
                                                    </div>
                                                    <x-task-preferred-indicator :task="$task" />
                                                </div>
                                                @if($showAvatars && ($taskUsers->count() > 0 || $taskVendor))
                                                    <div class="flex items-center gap-2 mt-2 min-w-0">
                                                        @if($taskUsers->count() > 0)
                                                            <flux:avatar.group>
                                                                @foreach($taskUsers->take(3) as $user)
                                                                    <flux:avatar circle size="xs" name="{{ $user->full_name }}" color="auto" color:seed="{{ $user->id }}" title="{{ $user->full_name }}" />
                                                                @endforeach
                                                                @if($taskUsers->count() > 3)
                                                                    <flux:avatar circle size="xs">{{ $taskUsers->count() - 3 }}+</flux:avatar>
                                                                @endif
                                                            </flux:avatar.group>
                                                        @endif
                                                        @if($taskVendor)
                                                            <flux:avatar circle size="xs" name="{{ $taskVendor->name }}" color="auto" color:seed="{{ $taskVendor->id }}" :title="($showVendorInfo ?? true) ? $taskVendor->name : null" />
                                                            @if($showVendorInfo ?? true)
                                                                <span class="flex-1 min-w-0 truncate text-xs text-zinc-600 dark:text-zinc-400">{{ $taskVendor->name }}</span>
                                                            @endif
                                                        @endif
                                                    </div>
                                                @endif
                                            </flux:kanban.card>
                                        @else
                                            <flux:kanban.card
                                                class="min-w-0 w-full transition hover:bg-zinc-50 dark:hover:bg-zinc-800 hover:shadow-sm hover:border-zinc-300 dark:hover:border-zinc-600 {{ $task->trashed() ? 'opacity-50' : '' }}"
                                                data-task-card="{{ $task->id }}"
                                                wire:key="unscheduled-task-{{ $task->id }}"
                                            >
                                                <div class="flex items-start justify-between gap-2 min-w-0">
                                                    <div class="flex items-center gap-2 min-w-0">
                                                        <flux:heading size="sm" class="min-w-0 truncate {{ $taskTypeTextClasses }} {{ $task->trashed() ? 'line-through' : '' }}">
                                                            {{ $task->title }}
                                                        </flux:heading>
                                                    </div>
                                                    <x-task-preferred-indicator :task="$task" />
                                                </div>
                                                @if($showAvatars && ($taskUsers->count() > 0 || $taskVendor))
                                                    <div class="flex items-center gap-2 mt-2 min-w-0">
                                                        @if($taskUsers->count() > 0)
                                                            <flux:avatar.group>
                                                                @foreach($taskUsers->take(3) as $user)
                                                                    <flux:avatar circle size="xs" name="{{ $user->full_name }}" color="auto" color:seed="{{ $user->id }}" title="{{ $user->full_name }}" />
                                                                @endforeach
                                                                @if($taskUsers->count() > 3)
                                                                    <flux:avatar circle size="xs">{{ $taskUsers->count() - 3 }}+</flux:avatar>
                                                                @endif
                                                            </flux:avatar.group>
                                                        @endif
                                                        @if($taskVendor)
                                                            <flux:avatar circle size="xs" name="{{ $taskVendor->name }}" color="auto" color:seed="{{ $taskVendor->id }}" :title="($showVendorInfo ?? true) ? $taskVendor->name : null" />
                                                            @if($showVendorInfo ?? true)
                                                                <span class="flex-1 min-w-0 truncate text-xs text-zinc-600 dark:text-zinc-400">{{ $taskVendor->name }}</span>
                                                            @endif
                                                        @endif
                                                    </div>
                                                @endif
                                            </flux:kanban.card>
                                        @endif
                                    @endforeach
                                @endif
                            </div>
                        </flux:accordion.content>
                    </flux:accordion.item>
                </flux:accordion>
            @endif

            @if($publicView)
                @php
                    $publicToday = browser_date() ?? now()->format('Y-m-d');
                    $publicPastTasks   = $groupedTasks->filter(fn ($t, $d) => $d < $publicToday && $t->isNotEmpty());
                    $publicUpcoming    = $groupedTasks->filter(fn ($t, $d) => $d >= $publicToday && $t->isNotEmpty());
                    $publicFirstDate   = $publicUpcoming->keys()->first();
                @endphp

                @if($publicPastTasks->isNotEmpty())
                    <flux:accordion transition>
                        <flux:accordion.item>
                            <flux:accordion.heading>
                                <div class="flex items-center gap-2">
                                    <span class="text-zinc-500 dark:text-zinc-400">Past Tasks</span>
                                    <flux:badge color="zinc" size="sm">{{ $publicPastTasks->flatten()->count() }}</flux:badge>
                                </div>
                            </flux:accordion.heading>
                            <flux:accordion.content>
                                <div class="space-y-3 mt-2">
                                    @foreach($publicPastTasks as $date => $tasks)
                                        <x-schedule.day-group :date="$date" :count="$tasks->count()">
                                            @foreach($tasks as $task)
                                                <x-schedule.task-card wire:key="public-past-{{ $date }}-{{ $task->id }}">
                                                    <x-schedule.task-card-body :task="$task" :date="$date" :show-owner="$showAvatars" :show-status="true" :show-address="false" />
                                                </x-schedule.task-card>
                                            @endforeach
                                        </x-schedule.day-group>
                                    @endforeach
                                </div>
                            </flux:accordion.content>
                        </flux:accordion.item>
                    </flux:accordion>
                @endif

                @foreach($publicUpcoming as $date => $tasks)
                    <x-schedule.day-group :date="$date" :count="$tasks->count()" :is-first="$date === $publicFirstDate">
                        @foreach($tasks as $task)
                            <x-schedule.task-card wire:key="public-scheduled-{{ $date }}-{{ $task->id }}">
                                <x-schedule.task-card-body :task="$task" :date="$date" :show-owner="$showAvatars" :show-status="true" :show-address="false" />
                            </x-schedule.task-card>
                        @endforeach
                    </x-schedule.day-group>
                @endforeach
            @endif

            @if(! $publicView)
                @php
                    $nonPublicToday = browser_date() ?? now()->format('Y-m-d');
                    $nonPublicPast = $groupedTasks->filter(fn ($t, $d) => $d < $nonPublicToday && $t->isNotEmpty());
                    $nonPublicCurrent = $groupedTasks->filter(fn ($t, $d) => $d >= $nonPublicToday);
                @endphp

                @if($nonPublicPast->isNotEmpty())
                    <flux:accordion transition>
                        <flux:accordion.item>
                            <flux:accordion.heading>
                                <div class="flex items-center gap-2">
                                    <span class="text-zinc-500 dark:text-zinc-400">Past Tasks</span>
                                    <flux:badge color="zinc" size="sm">{{ $nonPublicPast->flatten()->count() }}</flux:badge>
                                </div>
                            </flux:accordion.heading>
                            <flux:accordion.content>
                                <div class="space-y-4 mt-2">
                                    @foreach($nonPublicPast as $date => $tasks)
                                        @include('components.upcoming-tasks-list-day', [
                                            'date' => $date,
                                            'tasks' => $tasks,
                                            'showAvatars' => $showAvatars,
                                            'clickable' => $clickable,
                                            'showProjectInfo' => $showProjectInfo,
                                            'showVendorInfo' => $showVendorInfo,
                                            'publicView' => $publicView,
                                        ])
                                    @endforeach
                                </div>
                            </flux:accordion.content>
                        </flux:accordion.item>
                    </flux:accordion>
                @endif

                @foreach($nonPublicCurrent as $date => $tasks)
                    @include('components.upcoming-tasks-list-day', [
                        'date' => $date,
                        'tasks' => $tasks,
                        'showAvatars' => $showAvatars,
                        'clickable' => $clickable,
                        'showProjectInfo' => $showProjectInfo,
                        'showVendorInfo' => $showVendorInfo,
                        'publicView' => $publicView,
                    ])
                @endforeach
            @endif

            {{-- Later tasks beyond the displayed week --}}
            @include('components.upcoming-tasks-list-later', [
                'laterTasks' => $laterTasks,
                'showAvatars' => $showAvatars,
                'clickable' => $clickable,
                'showProjectInfo' => $showProjectInfo,
                'showVendorInfo' => $showVendorInfo,
                'publicView' => $publicView,
                'expanded' => $laterTasksExpanded,
            ])
        </div>
    @endif
</x-island-card>


