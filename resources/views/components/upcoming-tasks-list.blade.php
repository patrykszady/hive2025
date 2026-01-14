@props([
    'groupedTasks',
    'nextTaskInfo' => null,
    'taskCount' => 0,
    'showAvatars' => true,
    'clickable' => true,
    'unscheduledTasks' => null,
])

<flux:card>
    <div class="flex items-center justify-between mb-4">
        <flux:heading size="lg">Upcoming Tasks</flux:heading>
        <flux:badge size="sm" color="zinc">{{ $taskCount }}</flux:badge>
    </div>

    @if($groupedTasks->isEmpty())
        <flux:text class="text-zinc-500">No upcoming tasks for this project.</flux:text>
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
                    x-cloak
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
                            this.textColorClass = (this.isPast || this.isWeekend) 
                                ? 'text-zinc-400 dark:text-zinc-500' 
                                : 'text-zinc-700 dark:text-zinc-300';
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
                            <flux:badge color="green" size="sm">Today</flux:badge>
                        </template>
                        <template x-if="badge === 'tomorrow'">
                            <flux:badge color="sky" size="sm">Tomorrow</flux:badge>
                        </template>
                    </div>
                    
                    @include('components.upcoming-tasks-list-tasks', [
                        'tasks' => $tasks,
                        'date' => $date,
                        'carbonDate' => $carbonDate,
                        'showAvatars' => $showAvatars,
                        'clickable' => $clickable,
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
</flux:card>

@if($unscheduledTasks && $unscheduledTasks->isNotEmpty())
    <flux:card class="mt-4">
        <div class="flex items-center justify-between mb-4">
            <flux:heading size="lg">Unscheduled Tasks</flux:heading>
            <flux:badge color="zinc" size="sm">{{ $unscheduledTasks->count() }}</flux:badge>
        </div>
        <div class="space-y-2">
            @foreach($unscheduledTasks as $task)
                @php
                    $typeUi = $task->type_ui ?? [];
                    $taskTypeTextClasses = data_get($typeUi, 'text', '');
                    $taskVendor = $task->vendor ?? null;
                @endphp
                <flux:kanban.card
                    class="min-w-0 w-full"
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
            @endforeach
        </div>
    </flux:card>
@endif
