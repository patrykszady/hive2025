<!-- Flux Kanban Planner - 14 Day View with Projects & Tasks -->
<div class="h-full overflow-hidden">
    <flux:kanban class="h-full">
        @foreach($kanbanColumns as $column)
            @php
                $day = $column['day'];
                $isToday = $column['isToday'];
                $isWeekend = $column['isWeekend'];
            @endphp

            <flux:kanban.column wire:key="day-{{ $day->format('Y-m-d') }}">
                {{-- Column Header - Day --}}
                <flux:kanban.column.header
                    :heading="$column['title']"
                    :count="$column['taskCount']"
                    :class="$isToday ? 'bg-blue-50 dark:bg-blue-900/20' : ($isWeekend ? 'bg-zinc-100 dark:bg-zinc-800' : '')"
                />

                {{-- Column Cards - Projects with Tasks --}}
                <flux:kanban.column.cards>
                    @forelse($column['projectCards'] as $projectCard)
                        @php
                            $project = $projectCard['project'];
                            $tasks = $projectCard['tasks'];
                        @endphp

                        {{-- Project Card --}}
                        <flux:kanban.card wire:key="project-{{ $project->id }}-{{ $day->format('Y-m-d') }}">
                            {{-- Project Header with Badge --}}
                            <x-slot name="header">
                                <div class="flex items-center justify-between gap-2 w-full">
                                    <a
                                        href="{{ $project->getAddressMapURI() }}"
                                        target="_blank"
                                        class="truncate font-semibold text-sm text-zinc-800 dark:text-zinc-200 hover:text-blue-600 dark:hover:text-blue-400 flex items-center gap-1"
                                    >
                                        <flux:icon.map-pin class="w-3 h-3 flex-shrink-0" />
                                        <span class="truncate">{{ $project->address }}</span>
                                    </a>
                                    <flux:badge size="sm" color="lime">{{ $tasks->count() }}</flux:badge>
                                </div>
                            </x-slot>

                            {{-- Tasks List --}}
                            <div class="space-y-2 mt-2">
                                @foreach($tasks as $task)
                                    <div
                                        wire:click="editTask({{ $task->id }})"
                                        class="p-2 rounded border border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-700/50 cursor-pointer transition-colors {{ $task->type === 'Milestone' ? 'border-l-4 border-l-indigo-500' : 'border-l-4 border-l-blue-500' }}"
                                    >
                                        {{-- Task Title --}}
                                        <div class="flex items-center gap-1 text-sm text-zinc-900 dark:text-zinc-100">
                                            <flux:icon.pencil-square class="w-3 h-3 text-zinc-400 flex-shrink-0" />
                                            <span class="truncate">{{ $task->title }}</span>
                                        </div>

                                        {{-- Task Assignees --}}
                                        @if($task->users && $task->users->count() > 0)
                                            <div class="flex items-center gap-1 mt-1.5">
                                                <flux:avatar.group size="xs">
                                                    @foreach($task->users->take(3) as $user)
                                                        <flux:avatar
                                                            size="xs"
                                                            name="{{ $user->full_name }}"
                                                            color="auto"
                                                            color:seed="{{ $user->id }}"
                                                        />
                                                    @endforeach
                                                    @if($task->users->count() > 3)
                                                        <flux:avatar size="xs">+{{ $task->users->count() - 3 }}</flux:avatar>
                                                    @endif
                                                </flux:avatar.group>
                                            </div>
                                        @endif

                                        {{-- Vendor Badge --}}
                                        @if($task->vendor)
                                            <div class="mt-1.5">
                                                <flux:badge size="sm" color="zinc" class="truncate max-w-full">
                                                    {{ $task->vendor->name }}
                                                </flux:badge>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>

                            {{-- Footer: Add Task Button --}}
                            <x-slot name="footer">
                                <flux:button
                                    wire:click="addTask({{ $project->id }})"
                                    variant="ghost"
                                    size="sm"
                                    icon="plus"
                                    class="w-full justify-center"
                                >
                                    Add Task
                                </flux:button>
                            </x-slot>
                        </flux:kanban.card>
                    @empty
                        {{-- Empty Column State --}}
                        <div class="text-center py-8 text-zinc-400 dark:text-zinc-500 text-sm">
                            No tasks scheduled
                        </div>
                    @endforelse
                </flux:kanban.column.cards>
            </flux:kanban.column>
        @endforeach
    </flux:kanban>

    {{-- Task Create Modal --}}
    <livewire:tasks.task-create :projects="$projects" :employees="$employees" :vendors="$vendors"/>
</div>
