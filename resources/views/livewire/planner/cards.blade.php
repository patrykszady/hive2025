<div class="flex flex-col h-full min-h-0 overflow-hidden bg-white dark:bg-zinc-900">
    <!-- Planner Cards - 14 Day Kanban View -->
    <div class="flex-1 min-h-0 overflow-x-scroll overflow-y-hidden bg-white dark:bg-zinc-900">
        <div class="flex h-full min-h-0 min-w-max px-4">
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
                        <div class="flex-1 min-w-0 overflow-y-auto overflow-x-hidden space-y-4 flux-no-scrollbar">
                            @foreach ($dayData->columns as $projectColumn)
                                <div
                                    wire:key="project-{{ $projectColumn->id }}-{{ $dayData->day->format('Y-m-d') }}"
                                    class="min-w-0 {{ $dayData->isWeekend ? 'opacity-75' : '' }}"
                                >
                                    @php
                                        $latestStatus = $dayData->isWeekend ? null : $projectColumn->project->latestStatus;
                                    @endphp
                                    <flux:kanban class="w-full [&>div]:w-full [&>div]:min-w-0 [&>div]:flex-1">
                                        <flux:kanban.column class="!w-full !max-w-full">
                                            <flux:kanban.column.header
                                                class="min-w-0 w-full [&>div:first-child>div:first-child]:!min-w-0 [&>div:first-child>div:first-child]:!flex-1 [&>div:first-child>div:first-child]:truncate [&>div:first-child>div:last-child]:!shrink-0 [&_[data-flux-subheading]]:!min-w-0 [&_[data-flux-subheading]]:truncate"
                                                :badge:color="$latestStatus?->badge_color"
                                                :badge="$latestStatus?->title"
                                                badge:class="opacity-50 !bg-transparent dark:!bg-transparent ring-1 ring-inset ring-current"
                                            >
                                                <flux:heading class="min-w-0 truncate">
                                                    <a
                                                        href="{{ route('projects.show', $projectColumn->project) }}"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        class="truncate hover:underline underline-offset-2"
                                                    >
                                                        {{ $projectColumn->title }}
                                                    </a>
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
                                                        @endphp

                                                        <div class="flex items-start justify-between gap-2 min-w-0">
                                                            <flux:heading size="sm" class="min-w-0 truncate {{ $taskTypeTextClasses }}">
                                                                {{ $task->title }}
                                                            </flux:heading>
                                                            @if($showDayCounter)
                                                                <span class="text-xs text-zinc-500 dark:text-zinc-400 whitespace-nowrap">
                                                                    {{ $currentDay }}/{{ $totalDays }}
                                                                </span>
                                                            @endif
                                                        </div>

                                                        @if($taskUsers->count() > 0 || $taskVendor)
                                                            <div class="flex items-center gap-2 mt-2">
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
                                                                    <span class="text-xs text-zinc-600 dark:text-zinc-400">{{ $taskVendor->name }}</span>
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

    {{-- Task Create Modal --}}
    <livewire:tasks.task-create :projects="$projects" :employees="$employees" :vendors="$vendors"/>
</div>