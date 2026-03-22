@props([
    'groupedTasks',
    'nextTaskInfo' => null,
    'taskCount' => 0,
    'showAvatars' => true,
    'clickable' => true,
    'unscheduledTasks' => null,
    'showProjectInfo' => false,
    'showVendorInfo' => true,
    'showNotifications' => true,
    'publicView' => false,
    'title' => 'Tasks',
    'emptyMessage' => 'No tasks upcoming for this project.',
    'projectId' => null,
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
                    <flux:accordion.item>
                        <flux:accordion.heading>
                            <div class="flex items-center gap-2">
                                <span class="text-orange-600 dark:text-orange-400">Pending Tasks</span>
                                <flux:badge color="amber" size="sm">{{ $unscheduledTasks->count() }}</flux:badge>
                            </div>
                        </flux:accordion.heading>
                        <flux:accordion.content>
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
                                                        :title="($showVendorInfo ?? true) ? $taskVendor->name : null"
                                                    />
                                                    @if($showVendorInfo ?? true)
                                                        <span class="flex-1 min-w-0 truncate text-xs text-zinc-600 dark:text-zinc-400">{{ $taskVendor->name }}</span>
                                                    @endif
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
                                                        :title="($showVendorInfo ?? true) ? $taskVendor->name : null"
                                                    />
                                                    @if($showVendorInfo ?? true)
                                                        <span class="flex-1 min-w-0 truncate text-xs text-zinc-600 dark:text-zinc-400">{{ $taskVendor->name }}</span>
                                                    @endif
                                                </div>
                                            @endif
                                        </flux:kanban.card>
                                    @endif
                                @endforeach
                            </div>
                        </flux:accordion.content>
                    </flux:accordion.item>
                </flux:accordion>
            @endif

            @foreach($groupedTasks as $date => $tasks)
                @php
                    $carbonDate = \Carbon\Carbon::parse($date);
                    $isWeekend = $carbonDate->isWeekend();
                    $hasTasks = $tasks->isNotEmpty();

                    // Server-side pre-compute using browser date (session/cookie) for instant render
                    $browserToday = browser_date() ?? now()->format('Y-m-d');
                    $browserTomorrow = \Carbon\Carbon::parse($browserToday)->addDay()->format('Y-m-d');
                    $browserYesterday = \Carbon\Carbon::parse($browserToday)->subDay()->format('Y-m-d');

                    $serverBadge = match($date) {
                        $browserToday => 'today',
                        $browserTomorrow => 'tomorrow',
                        $browserYesterday => 'yesterday',
                        default => '',
                    };
                    $serverIsPast = $date < $browserToday;
                    $serverOpacity = match(true) {
                        $serverIsPast && !$hasTasks && $isWeekend => 'opacity-30',
                        $serverIsPast && !$hasTasks => 'opacity-40',
                        $serverIsPast && $hasTasks => 'opacity-50',
                        $isWeekend && !$hasTasks => 'opacity-50',
                        default => '',
                    };
                    $serverTextColor = match(true) {
                        $serverBadge === 'today' => 'text-indigo-600 dark:text-indigo-400',
                        $serverIsPast || $isWeekend => 'text-zinc-400 dark:text-zinc-500',
                        default => 'text-zinc-700 dark:text-zinc-300',
                    };
                @endphp
                
                {{-- Server pre-computes badge/opacity/color; Alpine confirms using browser timezone --}}
                <div 
                    class="space-y-2 {{ $serverOpacity }}"
                    x-data="{
                        date: '{{ $date }}',
                        isWeekend: {{ $isWeekend ? 'true' : 'false' }},
                        hasTasks: {{ $hasTasks ? 'true' : 'false' }},
                        badge: '{{ $serverBadge }}',
                        isPast: {{ $serverIsPast ? 'true' : 'false' }},
                        opacityClass: '{{ $serverOpacity }}',
                        textColorClass: '{{ $serverTextColor }}',
                        init() {
                            const parts = this.date.split('-');
                            const d = new Date(parts[0], parts[1] - 1, parts[2]);
                            d.setHours(0, 0, 0, 0);
                            
                            const now = new Date();
                            const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
                            const tomorrow = new Date(today);
                            tomorrow.setDate(tomorrow.getDate() + 1);
                            
                            const yesterday = new Date(today);
                            yesterday.setDate(yesterday.getDate() - 1);
                            
                            this.isPast = d.getTime() < today.getTime();
                            this.badge = d.getTime() === today.getTime() ? 'today' 
                                : (d.getTime() === tomorrow.getTime() ? 'tomorrow' 
                                : (d.getTime() === yesterday.getTime() ? 'yesterday' : ''));
                            
                            if (this.isPast && !this.hasTasks) {
                                this.opacityClass = this.isWeekend ? 'opacity-30' : 'opacity-40';
                            } else if (this.isPast && this.hasTasks) {
                                this.opacityClass = 'opacity-50';
                            } else if (this.isWeekend && !this.hasTasks) {
                                this.opacityClass = 'opacity-50';
                            } else {
                                this.opacityClass = '';
                            }
                            
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
                        <flux:heading size="sm" class="{{ $serverTextColor }}" ::class="textColorClass">
                            {{ $carbonDate->format('D, M j, Y') }}
                        </flux:heading>

                        {{-- Badges: server-rendered visible immediately, Alpine hides/shows on init --}}
                        <span x-show="badge === 'today'" @if($serverBadge !== 'today') style="display:none" @endif>
                            <flux:badge color="indigo" size="sm">Today</flux:badge>
                        </span>
                        <span x-show="badge === 'tomorrow'" @if($serverBadge !== 'tomorrow') style="display:none" @endif>
                            <flux:badge color="sky" size="sm">Tomorrow</flux:badge>
                        </span>
                        <span x-show="badge === 'yesterday'" @if($serverBadge !== 'yesterday') style="display:none" @endif>
                            <flux:badge color="zinc" size="sm">Yesterday</flux:badge>
                        </span>
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
                        'showVendorInfo' => $showVendorInfo,
                        'publicView' => $publicView,
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


