<!-- Planner Cards - 14 Day Kanban View -->
<div class="h-[calc(100vh-4rem)] overflow-x-auto p-4">
    <div class="flex gap-4 h-full min-w-max">
        @foreach($kanbanColumns as $column)
            @php
                $day = $column['day'];
                $isToday = $column['isToday'];
                $isWeekend = $column['isWeekend'];
            @endphp

            <div 
                wire:key="day-{{ $day->format('Y-m-d') }}"
                class="flex flex-col w-72 shrink-0 rounded-lg {{ $isToday ? 'ring-2 ring-blue-500' : '' }} {{ $isWeekend ? 'bg-zinc-100 dark:bg-zinc-800/50' : 'bg-zinc-50 dark:bg-zinc-800' }}"
            >
                {{-- Column Header --}}
                <div class="flex items-center justify-between p-3 border-b border-zinc-200 dark:border-zinc-700">
                    <flux:heading size="sm" class="{{ $isToday ? 'text-blue-600 dark:text-blue-400' : '' }}">
                        {{ $column['title'] }}
                    </flux:heading>
                    @if($column['taskCount'] > 0)
                        <flux:badge size="sm" color="{{ $isToday ? 'blue' : 'zinc' }}">{{ $column['taskCount'] }}</flux:badge>
                    @endif
                </div>

                {{-- Cards Container --}}
                <div class="flex-1 overflow-y-auto p-2 space-y-2">
                    @foreach($column['projectCards'] as $projectCard)
                        @php
                            $project = $projectCard['project'];
                            $tasks = $projectCard['tasks'];
                        @endphp

                        @foreach($tasks as $task)
                            <flux:card 
                                wire:key="task-{{ $task->id }}-{{ $day->format('Y-m-d') }}"
                                wire:click="editTask({{ $task->id }})"
                                class="cursor-pointer hover:shadow-md transition-shadow"
                            >
                                <flux:heading size="sm">{{ $task->title }}</flux:heading>
                                <flux:text size="sm" class="text-zinc-500 truncate">{{ $project->address }}</flux:text>
                                
                                @if($task->users && $task->users->count() > 0)
                                    <flux:avatar.group size="xs" class="mt-2">
                                        @foreach($task->users->take(3) as $user)
                                            <flux:avatar
                                                size="xs"
                                                :name="$user->full_name"
                                                color="auto"
                                                :color:seed="$user->id"
                                            />
                                        @endforeach
                                        @if($task->users->count() > 3)
                                            <flux:avatar size="xs">+{{ $task->users->count() - 3 }}</flux:avatar>
                                        @endif
                                    </flux:avatar.group>
                                @endif
                            </flux:card>
                        @endforeach
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    {{-- Task Create Modal --}}
    <livewire:tasks.task-create :projects="$projects" :employees="$employees" :vendors="$vendors"/>
</div>
