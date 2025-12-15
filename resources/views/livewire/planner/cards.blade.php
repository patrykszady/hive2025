<div>
    <!-- Planner Cards - 14 Day Kanban View -->
    <div class="h-screen overflow-x-auto bg-zinc-200 dark:bg-zinc-900">
        <div class="flex gap-4 h-full min-w-max p-4">
        @foreach ($kanbanColumns as $dayData)
            <div 
                wire:key="day-{{ $dayData->day->format('Y-m-d') }}"
                class="flex flex-col w-80 shrink-0"
            >
                {{-- Day Header --}}
                <div class="flex items-center justify-between p-3 mb-3">
                    <flux:heading size="lg" class="{{ $dayData->isToday ? 'text-blue-600 dark:text-blue-400' : '' }}">
                        {{ $dayData->title }}
                    </flux:heading>
                    @if ($dayData->isToday)
                        <flux:badge color="blue" size="sm">Today</flux:badge>
                    @endif
                </div>

                {{-- Project Columns (vertical stack using Flux Kanban) --}}
                <div class="flex-1 overflow-y-auto space-y-4">
                    @foreach ($dayData->columns as $projectColumn)
                        @php
                            $isWeekend = $dayData->day->isSaturday() || $dayData->day->isSunday();
                        @endphp
                        <div wire:key="project-{{ $projectColumn->id }}-{{ $dayData->day->format('Y-m-d') }}" class="{{ $isWeekend ? 'opacity-75' : '' }}">
                            <flux:kanban class="rounded-lg">
                                <flux:kanban.column>
                                    @php
                                        $clientLastNames = $projectColumn->project->client?->users->pluck('last_name')->unique()->join(', ') ?? '';
                                        $subheading = trim(($clientLastNames ? $clientLastNames . ' | ' : '') . ($projectColumn->project->project_name ?? ''), ' |');
                                        $status = $projectColumn->project->latestStatus;
                                    @endphp
                                    <flux:kanban.column.header 
                                        heading="{{ $projectColumn->title }}"
                                        subheading="{{ $subheading }}"
                                        :badge:color="$status?->color ?? 'zinc'"
                                        :badge="$status?->title ?? ''"
                                    >
                                        <x-slot name="actions">
                                            <flux:button variant="subtle" icon="plus" size="sm" wire:click="addTask({{ $projectColumn->id }})" />
                                        </x-slot>
                                    </flux:kanban.column.header>
                                    <flux:kanban.column.cards>
                                        @foreach ($projectColumn->cards as $task)
                                            <flux:kanban.card 
                                                as="button" 
                                                wire:click="editTask({{ $task->id }})"
                                            >
                                                @php
                                                    $taskUsers = $task->users;
                                                    $taskVendor = $task->vendor;
                                                    
                                                    // Task type color (blue for tasks, could be different for milestones)
                                                    $taskTypeColor = $task->type === 'milestone' ? 'purple' : 'blue';
                                                    
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
                                                
                                                <div class="flex items-start justify-between gap-2">
                                                    <flux:heading size="sm" class="text-{{ $taskTypeColor }}-600 dark:text-{{ $taskTypeColor }}-400">
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
        @endforeach
    </div>

    {{-- Task Create Modal --}}
    <livewire:tasks.task-create :projects="$projects" :employees="$employees" :vendors="$vendors"/>
</div>