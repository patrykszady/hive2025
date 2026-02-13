{{-- Task Cards for this date --}}
@if($tasks->isEmpty())
    {{-- Empty day: no tasks scheduled --}}
@else
    @if($showProjectInfo ?? false)
        {{-- Group tasks by project, render project header with nested task cards (planner cards style) --}}
        @php
            $tasksByProject = $tasks->groupBy(fn ($task) => $task->project_id ?? 0);
        @endphp
        <div class="space-y-2 pl-0">
            @foreach($tasksByProject as $projectId => $projectTasks)
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
                                <a
                                    href="{{ $project ? route('projects.show', $project) : '#' }}"
                                    wire:click.stop
                                    class="truncate hover:underline underline-offset-2"
                                >
                                    {{ $project->short_address ?? 'No project' }}
                                </a>
                                @if($latestStatus)
                                    <flux:tooltip content="{{ $latestStatus->title }}">
                                        <flux:badge :color="$latestStatus->badge_color" size="sm" class="!px-0 !size-2 !min-w-0 rounded-full shrink-0" />
                                    </flux:tooltip>
                                @endif
                            </flux:heading>
                            @if($clickable)
                                <x-slot name="actions">
                                    <flux:button
                                        variant="subtle"
                                        icon="plus"
                                        size="sm"
                                        class="shrink-0"
                                        wire:click="$dispatchTo('tasks.task-create', 'addTask', { project_id: {{ $projectId }}, date: '{{ $date }}' })"
                                    />
                                </x-slot>
                            @endif
                            @if($project?->client || $project?->project_name)
                                <x-slot name="subheading">
                                    <span class="block min-w-0 truncate">
                                        {{ $project->client?->last_names }}{{ $project->client?->last_names && $project->project_name ? ' | ' : '' }}{{ $project->project_name }}
                                    </span>
                                </x-slot>
                            @endif
                        </flux:kanban.column.header>
                        <flux:kanban.column.cards>
                            @foreach($projectTasks as $task)
                                <x-task-card :task="$task" :date="$date" :clickable="$clickable" :show-avatars="$showAvatars" :show-vendor-info="$showVendorInfo ?? true" />
                            @endforeach
                        </flux:kanban.column.cards>
                    </flux:kanban.column>
                </flux:kanban>
            @endforeach
        </div>
    @else
    <div class="space-y-2 pl-0">
        @foreach($tasks as $task)
            <x-task-card :task="$task" :date="$date" :clickable="$clickable" :show-avatars="$showAvatars" :show-vendor-info="$showVendorInfo ?? true" />
        @endforeach
    </div>
    @endif
@endif
