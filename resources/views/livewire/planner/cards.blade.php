@php
    $firstDayIsWeekend = (bool) data_get($kanbanColumns->first(), 'isWeekend', false);
@endphp

<div class="flex flex-col h-full min-h-0">
    <div class="flex-1 flex flex-col min-h-0 overflow-hidden bg-zinc-100 dark:bg-zinc-800">
        {{-- Filter Bar - Desktop (horizontal) --}}
        <div class="hidden shrink-0 px-4 py-3 bg-white dark:bg-zinc-900 border-b border-zinc-200 dark:border-zinc-700">
        <div class="flex items-center gap-3">
            {{-- Project Filter (Multi-select) --}}
            <flux:select
                wire:model.live="filterProjectIds"
                placeholder="All Projects"
                variant="listbox"
                searchable
                multiple
                size="sm"
                class="min-w-48"
            >
                @foreach($projects as $project)
                    <flux:select.option value="{{ $project->id }}">
                        <div class="flex items-center gap-2 w-full">
                            <span class="flex-1 min-w-0 truncate">{{ $project->short_address }}</span>
                            @if($project->latestStatus)
                                <flux:badge size="sm" :color="$project->latestStatus->badge_color" inset="top bottom" class="shrink-0">
                                    {{ $project->latestStatus->title }}
                                </flux:badge>
                            @endif
                        </div>
                    </flux:select.option>
                @endforeach
            </flux:select>

            {{-- Status Filter (Multi-select with badges) --}}
            <flux:select
                wire:model.live="filterStatusCodes"
                placeholder="All Statuses"
                variant="listbox"
                multiple
                size="sm"
                class="min-w-40"
            >
                @foreach($this->statusOptions as $status)
                    <flux:select.option value="{{ $status['code'] }}">
                        <flux:badge size="sm" inset="top bottom" :color="$status['color']">
                            {{ $status['label'] }}
                        </flux:badge>
                    </flux:select.option>
                @endforeach
            </flux:select>

            {{-- Vendor Filter (with avatars) --}}
            <flux:select
                wire:model.live="filterVendorId"
                placeholder="All Vendors"
                variant="listbox"
                searchable
                clearable
                size="sm"
                class="min-w-48"
            >
                @foreach($vendors as $vendor)
                    <flux:select.option value="{{ $vendor->id }}">
                        <div class="flex items-center gap-2 whitespace-nowrap">
                            <flux:avatar size="xs" name="{{ $vendor->name }}" color="auto" color:seed="{{ $vendor->id }}" />
                            {{ $vendor->name }}
                        </div>
                    </flux:select.option>
                @endforeach
            </flux:select>

            {{-- Team Member Filter (Multi-select with avatars) --}}
            <flux:select
                wire:model.live="filterUserIds"
                placeholder="All Team Members"
                variant="listbox"
                searchable
                multiple
                size="sm"
                class="min-w-48"
            >
                @foreach($employees as $employee)
                    <flux:select.option value="{{ $employee->id }}">
                        <div class="flex items-center gap-2 whitespace-nowrap">
                            <flux:avatar size="xs" name="{{ $employee->full_name }}" color="auto" color:seed="{{ $employee->id }}" />
                            {{ $employee->full_name }}
                        </div>
                    </flux:select.option>
                @endforeach
            </flux:select>

            {{-- Clear Filters Button --}}
            @if($this->hasActiveFilters)
                <flux:button
                    wire:click="clearFilters"
                    variant="subtle"
                    size="sm"
                    icon="x-mark"
                >
                    Clear Filters
                </flux:button>
            @endif
        </div>
    </div>

    {{-- Floating Filter Button --}}
    <div class="fixed top-3 right-3 z-60">
        <flux:button
            wire:click="$toggle('showMobileFilters')"
            variant="subtle"
            square
            inset="right"
            icon="funnel"
            class="bg-white/60 dark:bg-zinc-900/50 backdrop-blur-[2px] border border-zinc-200/60 dark:border-zinc-700/60 shadow-sm rounded-lg"
            aria-label="Filters"
        />
    </div>

    {{-- Filters Modal --}}
    <flux:modal wire:model="showMobileFilters" name="mobile-filters" class="max-w-sm">
        <div class="space-y-6">
            <flux:heading size="lg">Filters</flux:heading>

            {{-- Project Filter --}}
            <flux:field>
                <flux:label>Projects</flux:label>
                <flux:select
                    wire:model.live="filterProjectIds"
                    placeholder="All Projects"
                    variant="listbox"
                    searchable
                    multiple
                >
                    @foreach($projects as $project)
                        <flux:select.option value="{{ $project->id }}">
                            <div class="flex items-center gap-2 w-full">
                                <span class="flex-1 min-w-0 truncate">{{ $project->short_address }}</span>
                                @if($project->latestStatus)
                                    <flux:badge size="sm" :color="$project->latestStatus->badge_color" inset="top bottom" class="shrink-0">
                                        {{ $project->latestStatus->title }}
                                    </flux:badge>
                                @endif
                            </div>
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            {{-- Status Filter --}}
            <flux:field>
                <flux:label>Status</flux:label>
                <flux:select
                    wire:model.live="filterStatusCodes"
                    placeholder="All Statuses"
                    variant="listbox"
                    multiple
                >
                    @foreach($this->statusOptions as $status)
                        <flux:select.option value="{{ $status['code'] }}">
                            <flux:badge size="sm" inset="top bottom" :color="$status['color']">
                                {{ $status['label'] }}
                            </flux:badge>
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            {{-- Vendor Filter --}}
            <flux:field>
                <flux:label>Vendor</flux:label>
                <flux:select
                    wire:model.live="filterVendorId"
                    placeholder="All Vendors"
                    variant="listbox"
                    searchable
                    clearable
                >
                    @foreach($vendors as $vendor)
                        <flux:select.option value="{{ $vendor->id }}">
                            <div class="flex items-center gap-2 whitespace-nowrap">
                                <flux:avatar size="xs" name="{{ $vendor->name }}" color="auto" color:seed="{{ $vendor->id }}" />
                                {{ $vendor->name }}
                            </div>
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            {{-- Team Members Filter --}}
            <flux:field>
                <flux:label>Team Members</flux:label>
                <flux:select
                    wire:model.live="filterUserIds"
                    placeholder="All Team Members"
                    variant="listbox"
                    searchable
                    multiple
                >
                    @foreach($employees as $employee)
                        <flux:select.option value="{{ $employee->id }}">
                            <div class="flex items-center gap-2 whitespace-nowrap">
                                <flux:avatar size="xs" name="{{ $employee->full_name }}" color="auto" color:seed="{{ $employee->id }}" />
                                {{ $employee->full_name }}
                            </div>
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <div class="flex gap-2 pt-2">
                @if($this->hasActiveFilters)
                    <flux:button wire:click="clearFilters" variant="subtle" class="flex-1">
                        Clear All
                    </flux:button>
                @endif
                <flux:button wire:click="$set('showMobileFilters', false)" variant="primary" class="flex-1">
                    Done
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <!-- Planner Cards - 14 Day Kanban View -->
    <div class="flex-1 min-h-0 overflow-x-scroll overflow-y-hidden bg-zinc-100 dark:bg-zinc-800">
        <div class="flex h-full min-h-0 min-w-max">
            <div
                aria-hidden="true"
                class="self-stretch shrink-0 w-4 {{ $firstDayIsWeekend ? 'bg-zinc-200 dark:bg-zinc-700' : 'bg-zinc-100 dark:bg-zinc-800' }}"
            ></div>

            <div class="flex h-full min-h-0 min-w-max pr-4">
            @foreach ($kanbanColumns as $dayData)
                <div
                    wire:key="day-{{ $dayData->day->format('Y-m-d') }}"
                    class="self-stretch shrink-0 {{ (! $loop->last && ! ($dayData->day->isFriday() || $dayData->day->isSunday())) ? 'pr-4' : '' }} {{ $dayData->isWeekend ? '-my-4 py-4 bg-zinc-200 dark:bg-zinc-700' : '' }}"
                >
                    <div class="flex flex-col h-full min-h-0 {{ $dayData->isWeekend ? 'w-40' : 'w-80' }}">
                        {{-- Day Header --}}
                        <div class="flex items-center justify-between p-3 mb-3 {{ $dayData->isWeekend ? 'opacity-75' : '' }}">
                            <flux:heading size="lg" class="{{ $dayData->isToday ? 'text-blue-600 dark:text-blue-400' : '' }}">
                                {{ $dayData->title }}
                            </flux:heading>
                            @if ($dayData->isToday)
                                <flux:badge color="blue" size="sm">Today</flux:badge>
                            @elseif ($dayData->isTomorrow && ! $dayData->isWeekend)
                                <flux:badge color="zinc" size="sm">Tomorrow</flux:badge>
                            @endif
                        </div>

                        {{-- Project Columns (vertical stack using Flux Kanban) --}}
                        <div class="flex-1 min-w-0 overflow-y-auto overflow-x-hidden space-y-2 flux-no-scrollbar">
                            @foreach ($dayData->columns as $projectColumn)
                                @php
                                    $hasTasks = $projectColumn->cards->count() > 0;
                                    $hasUndatedTasks = $dayData->isToday && ($projectColumn->undated_tasks_count ?? 0) > 0;
                                    $showTasks = $hasTasks || $hasUndatedTasks;
                                    
                                    // Determine opacity classes based on weekend and task state
                                    $opacityClass = match(true) {
                                        $dayData->isWeekend && !$showTasks => 'opacity-30 hover:opacity-60 transition-opacity',
                                        $dayData->isWeekend => 'opacity-75',
                                        !$showTasks => 'opacity-40 hover:opacity-70 transition-opacity',
                                        default => '',
                                    };
                                @endphp
                                <div
                                    wire:key="project-{{ $projectColumn->id }}-{{ $dayData->day->format('Y-m-d') }}"
                                    class="min-w-0 {{ $opacityClass }}"
                                >
                                    @php
                                        $latestStatus = $dayData->isWeekend ? null : $projectColumn->project->latestStatus;
                                    @endphp

                                    <flux:kanban class="w-full [&>div]:w-full [&>div]:min-w-0 [&>div]:flex-1">
                                        <flux:kanban.column class="!w-full !max-w-full bg-white dark:bg-zinc-900 rounded-lg shadow-sm">
                                            <flux:kanban.column.header
                                                class="min-w-0 w-full [&>div:first-child>div:first-child]:!min-w-0 [&>div:first-child>div:first-child]:!flex-1 [&>div:first-child>div:first-child]:truncate [&>div:first-child>div:last-child]:!shrink-0 [&_[data-flux-subheading]]:!min-w-0 [&_[data-flux-subheading]]:truncate"
                                            >
                                                <flux:heading class="min-w-0 truncate flex items-center gap-2">
                                                    <a
                                                        href="{{ route('projects.show', $projectColumn->project) }}"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        class="truncate hover:underline underline-offset-2"
                                                    >
                                                        {{ $projectColumn->title }}
                                                    </a>
                                                    @if($latestStatus)
                                                        <flux:tooltip content="{{ $latestStatus->title }}">
                                                            <flux:badge :color="$latestStatus->badge_color" size="sm" class="!px-0 !size-2 !min-w-0 rounded-full shrink-0" />
                                                        </flux:tooltip>
                                                    @endif
                                                </flux:heading>
                                                <x-slot name="actions">
                                                    <flux:button
                                                        variant="subtle"
                                                        icon="plus"
                                                        size="sm"
                                                        class="shrink-0"
                                                        wire:key="add-task-{{ $projectColumn->id }}-{{ $dayData->day->format('Y-m-d') }}"
                                                        wire:click="addTask({{ $projectColumn->id }}, '{{ $dayData->day->format('Y-m-d') }}')"
                                                        wire:target="addTask({{ $projectColumn->id }}, '{{ $dayData->day->format('Y-m-d') }}')"
                                                    />
                                                </x-slot>

                                                <x-slot name="subheading">
                                                    <span class="block min-w-0 truncate">
                                                        {{ $projectColumn->project->client->last_names }} | {{ $projectColumn->project->project_name }}
                                                    </span>
                                                </x-slot>
                                            </flux:kanban.column.header>
                                            <flux:kanban.column.cards>
                                                @if ($dayData->isToday && ($projectColumn->undated_tasks_count ?? 0) > 0)
                                                    <flux:kanban.card
                                                        as="button"
                                                        class="min-w-0 w-full"
                                                        wire:key="undated-tasks-{{ $projectColumn->id }}"
                                                        wire:click="openUndatedTasksModal({{ $projectColumn->id }})"
                                                        wire:target="openUndatedTasksModal({{ $projectColumn->id }})"
                                                        wire:loading.attr="disabled"
                                                        wire:loading.class="opacity-60 cursor-wait"
                                                    >
                                                        <div class="flex items-center justify-between gap-2 min-w-0">
                                                            <flux:heading size="sm" class="min-w-0 truncate text-orange-600 dark:text-orange-400">
                                                                No date tasks
                                                            </flux:heading>
                                                            <span class="text-xs text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
                                                                {{ $projectColumn->undated_tasks_count }}
                                                            </span>
                                                        </div>
                                                    </flux:kanban.card>
                                                @endif

                                                {{-- Task gap info (next/last task) --}}
                                                @if ($projectColumn->task_gap_info)
                                                    <div class="px-2 py-1.5 text-xs text-zinc-500 dark:text-zinc-400 italic">
                                                        @if ($projectColumn->task_gap_info->type === 'next')
                                                            <span class="text-blue-600 dark:text-blue-400">{{ $projectColumn->task_gap_info->label }}</span>
                                                        @else
                                                            <span class="text-amber-600 dark:text-amber-400">{{ $projectColumn->task_gap_info->label }}</span>
                                                        @endif
                                                    </div>
                                                @endif

                                                @foreach ($projectColumn->cards as $task)
                                                    <flux:kanban.card
                                                        as="button"
                                                        class="min-w-0 w-full"
                                                        wire:key="task-{{ $task->id }}-{{ $dayData->day->format('Y-m-d') }}-{{ $projectColumn->id }}"
                                                        wire:click="editTask({{ $task->id }}, '{{ $dayData->day->format('Y-m-d') }}', {{ $projectColumn->id }})"
                                                        wire:target="editTask({{ $task->id }}, '{{ $dayData->day->format('Y-m-d') }}', {{ $projectColumn->id }})"
                                                        wire:loading.attr="disabled"
                                                        wire:loading.class="opacity-60 cursor-wait"
                                                    >
                                                        @php
                                                            $taskUsers = $task->users;
                                                            $taskVendor = $task->vendor;
                                                            $taskTypeTextClasses = data_get($task->type_ui, 'text');

                                                            // Get selected dates from options
                                                            $selectedDates = $task->options->dates ?? [];
                                                            $totalDays = count($selectedDates);

                                                            // Find which day number this is
                                                            $currentDay = 0;
                                                            $dayFormat = $dayData->day->format('Y-m-d');
                                                            if (!empty($selectedDates)) {
                                                                sort($selectedDates);
                                                                $currentDay = array_search($dayFormat, $selectedDates);
                                                                if ($currentDay !== false) {
                                                                    $currentDay++; // Convert from 0-indexed to 1-indexed
                                                                }
                                                            }

                                                            $showDayCounter = $totalDays > 1 && $currentDay > 0;

                                                            $arrivalTimeLabel = null;
                                                            $dayTimeSettings = data_get($task->options, "time_settings.$dayFormat");
                                                            $dayUsesTime = (bool) data_get($dayTimeSettings, 'use_time', false);
                                                            $dayStartTime = (string) data_get($dayTimeSettings, 'start_time', '');

                                                            if ($dayUsesTime && $dayStartTime !== '') {
                                                                try {
                                                                    $arrivalTimeLabel = \Carbon\Carbon::createFromFormat('H:i', $dayStartTime)->format('g:i A');
                                                                } catch (\Exception $e) {
                                                                    $arrivalTimeLabel = null;
                                                                }
                                                            }
                                                        @endphp

                                                        <div class="flex items-start justify-between gap-2 min-w-0">
                                                            <div class="flex items-center gap-2 min-w-0">
                                                                <flux:heading size="sm" class="min-w-0 truncate {{ $taskTypeTextClasses }}">
                                                                    {{ $task->title }}
                                                                </flux:heading>
                                                                @if ($arrivalTimeLabel)
                                                                    <span class="shrink-0 text-xs text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
                                                                        {{ $arrivalTimeLabel }}
                                                                    </span>
                                                                @endif
                                                            </div>
                                                            @if($showDayCounter)
                                                                <span class="text-xs text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
                                                                    {{ $currentDay }}/{{ $totalDays }}
                                                                </span>
                                                            @endif
                                                        </div>

                                                        @if($taskUsers->count() > 0 || $taskVendor)
                                                            <div class="flex items-center gap-2 mt-2 min-w-0">
                                                                @if($taskUsers->count() > 0)
                                                                    <flux:avatar.group>
                                                                        @foreach($taskUsers->take(3) as $user)
                                                                            <flux:avatar
                                                                                circle
                                                                                size="xs"
                                                                                name="{{ $user->full_name }}"
                                                                                color="auto"
                                                                                color:seed="{{ $user->id }}"
                                                                                title="{{ $user->full_name }}"
                                                                            />
                                                                        @endforeach
                                                                        @if($taskUsers->count() > 3)
                                                                            <flux:avatar circle size="xs">{{ $taskUsers->count() - 3 }}+</flux:avatar>
                                                                        @endif
                                                                    </flux:avatar.group>
                                                                @endif

                                                                @if($taskVendor)
                                                                    <flux:avatar
                                                                        circle
                                                                        size="xs"
                                                                        name="{{ $taskVendor->name }}"
                                                                        color="auto"
                                                                        color:seed="{{ $taskVendor->id }}"
                                                                        title="{{ $taskVendor->name }}"
                                                                    />
                                                                    <span class="flex-1 min-w-0 truncate text-xs text-zinc-600 dark:text-zinc-400">{{ $taskVendor->name }}</span>
                                                                @endif
                                                            </div>
                                                        @endif
                                                    </flux:kanban.card>
                                                @endforeach
                                            </flux:kanban.column.cards>
                                        </flux:kanban.column>
                                    </flux:kanban>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                @if (! $loop->last && ($dayData->day->isFriday() || $dayData->day->isSunday()))
                    <div
                        aria-hidden="true"
                        class="self-stretch shrink-0 w-4 relative -my-4 py-4"
                    >
                        @if ($dayData->day->isFriday())
                            <div class="absolute inset-y-0 right-0 w-1/2 bg-zinc-200 dark:bg-zinc-700"></div>
                        @elseif ($dayData->day->isSunday())
                            <div class="absolute inset-y-0 left-0 w-1/2 bg-zinc-200 dark:bg-zinc-700"></div>
                        @endif

                        <div class="absolute inset-y-0 left-1/2 -translate-x-1/2 border-l border-zinc-300/50 dark:border-zinc-600/40"></div>
                    </div>
                @endif
            @endforeach
            </div>
        </div>
    </div>

    {{-- Task Create Modal --}}
    <livewire:tasks.task-create :projects="$projects" :employees="$employees" :vendors="$vendors"/>

    <flux:modal name="planner_undated_tasks_modal" class="space-y-4 min-w-[22rem]">
        <div class="space-y-1">
            <flux:heading size="lg">No date tasks</flux:heading>
            @if ($undatedTasksModalProjectTitle)
                <flux:subheading class="truncate">{{ $undatedTasksModalProjectTitle }}</flux:subheading>
            @endif
        </div>

        <flux:separator variant="subtle" />

        @if (empty($undatedTasksModalTasks))
            <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">No undated tasks found.</flux:text>
        @else
            <div class="space-y-2">
                @foreach ($undatedTasksModalTasks as $task)
                    <flux:kanban.card
                        as="button"
                        class="min-w-0 w-full"
                        wire:key="undated-task-{{ $task['id'] }}"
                        wire:click="editUndatedTask({{ $task['id'] }})"
                        wire:target="editUndatedTask({{ $task['id'] }})"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-60 cursor-wait"
                    >
                        <div class="flex items-start justify-between gap-2 min-w-0">
                            <flux:heading size="sm" class="min-w-0 truncate {{ $task['type_text_class'] ?? '' }}">
                                {{ $task['title'] }}
                            </flux:heading>
                        </div>

                        @if (($task['users_count'] ?? 0) > 0 || !empty($task['vendor']))
                            <div class="flex items-center gap-2 mt-2 min-w-0">
                                @if (($task['users_count'] ?? 0) > 0)
                                    <flux:avatar.group>
                                        @foreach(($task['users'] ?? []) as $user)
                                            <flux:avatar
                                                circle
                                                size="xs"
                                                name="{{ $user['full_name'] }}"
                                                color="auto"
                                                color:seed="{{ $user['id'] }}"
                                                title="{{ $user['full_name'] }}"
                                            />
                                        @endforeach
                                        @if(($task['users_count'] ?? 0) > 3)
                                            <flux:avatar circle size="xs">{{ ($task['users_count'] ?? 0) - 3 }}+</flux:avatar>
                                        @endif
                                    </flux:avatar.group>
                                @endif

                                @if (!empty($task['vendor']))
                                    <flux:avatar
                                        circle
                                        size="xs"
                                        name="{{ $task['vendor']['name'] }}"
                                        color="auto"
                                        color:seed="{{ $task['vendor']['id'] }}"
                                        title="{{ $task['vendor']['name'] }}"
                                    />
                                    <span class="flex-1 min-w-0 truncate text-xs text-zinc-600 dark:text-zinc-400">{{ $task['vendor']['name'] }}</span>
                                @endif
                            </div>
                        @endif
                    </flux:kanban.card>
                @endforeach
            </div>
        @endif
    </flux:modal>
    </div>
</div>