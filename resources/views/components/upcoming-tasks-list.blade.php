@props([
    'groupedTasks',
    'nextTaskInfo' => null,
    'taskCount' => 0,
    'showAvatars' => true,
    'clickable' => true,
    'unscheduledTasks' => null,
    'showProjectInfo' => false,
    'title' => 'Upcoming Tasks',
    'emptyMessage' => 'No upcoming tasks for this project.',
])

<x-island-card heading="{{ $title }}">
    <x-slot:badge>
        <flux:badge size="sm" color="zinc">{{ $taskCount }}</flux:badge>
    </x-slot:badge>
    <x-slot:actions>
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
    </x-slot:actions>

    @if($groupedTasks->isEmpty())
        <flux:text class="text-zinc-500">{{ $emptyMessage }}</flux:text>
    @else
        <div class="space-y-4">
            @foreach($groupedTasks as $date => $tasks)
                @php
                    $carbonDate = \Carbon\Carbon::parse($date);
                    $isWeekend = $carbonDate->isWeekend();
                    $hasTasks = $tasks->isNotEmpty();
                @endphp
                
                {{-- Alpine-based rendering - uses browser's local timezone for Today/Tomorrow --}}
                <div 
                    class="space-y-2"
                    x-data="{
                        date: '{{ $date }}',
                        isWeekend: {{ $isWeekend ? 'true' : 'false' }},
                        hasTasks: {{ $hasTasks ? 'true' : 'false' }},
                        badge: '',
                        isPast: false,
                        opacityClass: '',
                        textColorClass: 'text-zinc-700 dark:text-zinc-300',
                        init() {
                            const parts = this.date.split('-');
                            const d = new Date(parts[0], parts[1] - 1, parts[2]);
                            d.setHours(0, 0, 0, 0);
                            
                            const now = new Date();
                            const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
                            const tomorrow = new Date(today);
                            tomorrow.setDate(tomorrow.getDate() + 1);
                            
                            this.isPast = d.getTime() < today.getTime();
                            this.badge = d.getTime() === today.getTime() ? 'today' 
                                : (d.getTime() === tomorrow.getTime() ? 'tomorrow' : '');
                            
                            // Calculate opacity class
                            if (this.isPast && !this.hasTasks) {
                                this.opacityClass = this.isWeekend ? 'opacity-30' : 'opacity-40';
                            } else if (this.isPast && this.hasTasks) {
                                this.opacityClass = 'opacity-50';
                            } else if (this.isWeekend && !this.hasTasks) {
                                this.opacityClass = 'opacity-50';
                            }
                            
                            // Calculate text color class
                            if (this.badge === 'today') {
                                this.textColorClass = 'text-indigo-600 dark:text-indigo-400';
                            } else if (this.isPast || this.isWeekend) {
                                this.textColorClass = 'text-zinc-400 dark:text-zinc-500';
                            } else {
                                this.textColorClass = 'text-zinc-700 dark:text-zinc-300';
                            }
                        }
                    }"
                    :class="opacityClass"
                >
                    {{-- Date Header - min-h-6 reserves space for badge to prevent layout shift --}}
                    <div class="flex items-center gap-2 min-h-6">
                        <flux:heading size="sm" ::class="textColorClass">
                            {{ $carbonDate->format('D, M j, Y') }}
                        </flux:heading>
                        <template x-if="badge === 'today'">
                            <flux:badge color="indigo" size="sm">Today</flux:badge>
                        </template>
                        <template x-if="badge === 'tomorrow'">
                            <flux:badge color="zinc" size="sm">Tomorrow</flux:badge>
                        </template>
                        @if($tasks->isEmpty())
                            <flux:badge color="zinc" size="sm">No Tasks</flux:badge>
                        @endif
                    </div>
                    
                    @include('components.upcoming-tasks-list-tasks', [
                        'tasks' => $tasks,
                        'date' => $date,
                        'carbonDate' => $carbonDate,
                        'showAvatars' => $showAvatars,
                        'clickable' => $clickable,
                        'showProjectInfo' => $showProjectInfo,
                    ])
                </div>
            @endforeach

            {{-- Next task beyond the displayed week --}}
            @if($nextTaskInfo)
                <div class="pt-2 border-t border-zinc-200 dark:border-zinc-700">
                    <div class="flex items-center gap-1.5 text-sm text-amber-600 dark:text-amber-400 italic pl-1">
                        <flux:icon.calendar-days class="size-3.5" />
                        <span>{{ $nextTaskInfo->label }} ({{ $nextTaskInfo->date }})</span>
                    </div>
                </div>
            @endif
        </div>
    @endif
</x-island-card>

@if($unscheduledTasks && $unscheduledTasks->isNotEmpty())
    <x-island-card heading="Unscheduled Tasks" class="mt-4">
        <x-slot:badge>
            <flux:badge color="zinc" size="sm">{{ $unscheduledTasks->count() }}</flux:badge>
        </x-slot:badge>
        <div class="space-y-2">
            @foreach($unscheduledTasks as $task)
                @php
                    $typeUi = $task->type_ui ?? [];
                    $taskTypeTextClasses = data_get($typeUi, 'text', '');
                    $taskVendor = $task->vendor ?? null;
                @endphp
                @if($clickable)
                    <flux:kanban.card
                        as="button"
                        class="min-w-0 w-full"
                        wire:key="unscheduled-task-{{ $task->id }}"
                        wire:click="$dispatchTo('tasks.task-create', 'editTask', { task: {{ $task->id }} })"
                    >
                        <div class="flex items-start justify-between gap-2 min-w-0">
                            <div class="flex items-center gap-2 min-w-0">
                                <flux:heading size="sm" class="min-w-0 truncate {{ $taskTypeTextClasses }}">
                                    {{ $task->title }}
                                </flux:heading>
                            </div>
                        </div>
                        @if($showAvatars && $taskVendor)
                            <div class="flex items-center gap-2 mt-2 min-w-0">
                                <flux:avatar
                                    circle
                                    size="xs"
                                    name="{{ $taskVendor->name }}"
                                    color="auto"
                                    color:seed="{{ $taskVendor->id }}"
                                    title="{{ $taskVendor->name }}"
                                />
                                <span class="flex-1 min-w-0 truncate text-xs text-zinc-600 dark:text-zinc-400">{{ $taskVendor->name }}</span>
                            </div>
                        @endif
                    </flux:kanban.card>
                @else
                    <flux:kanban.card
                        class="min-w-0 w-full transition hover:bg-zinc-50 dark:hover:bg-zinc-800 hover:shadow-sm hover:border-zinc-300 dark:hover:border-zinc-600"
                        wire:key="unscheduled-task-{{ $task->id }}"
                    >
                        <div class="flex items-start justify-between gap-2 min-w-0">
                            <div class="flex items-center gap-2 min-w-0">
                                <flux:heading size="sm" class="min-w-0 truncate {{ $taskTypeTextClasses }}">
                                    {{ $task->title }}
                                </flux:heading>
                            </div>
                        </div>
                        @if($showAvatars && $taskVendor)
                            <div class="flex items-center gap-2 mt-2 min-w-0">
                                <flux:avatar
                                    circle
                                    size="xs"
                                    name="{{ $taskVendor->name }}"
                                    color="auto"
                                    color:seed="{{ $taskVendor->id }}"
                                    title="{{ $taskVendor->name }}"
                                />
                                <span class="flex-1 min-w-0 truncate text-xs text-zinc-600 dark:text-zinc-400">{{ $taskVendor->name }}</span>
                            </div>
                        @endif
                    </flux:kanban.card>
                @endif
            @endforeach
        </div>
    </x-island-card>
@endif
