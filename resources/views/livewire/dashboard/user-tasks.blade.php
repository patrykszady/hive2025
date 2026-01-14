<div>
    <flux:card>
        <div class="flex items-center justify-between mb-4">
            <flux:heading size="lg">My Upcoming Tasks</flux:heading>
            <flux:badge size="sm" color="zinc">{{ $this->tasks->count() }}</flux:badge>
        </div>

        @if($this->tasks->isEmpty())
            <flux:text class="text-zinc-500">No upcoming tasks assigned to you.</flux:text>
        @else
            @php
                // Group tasks by date
                $groupedTasks = $this->tasks->groupBy(function ($task) {
                    return $task->start_date->format('Y-m-d');
                });
            @endphp
            <div class="space-y-4">
                @foreach($groupedTasks as $date => $tasks)
                    @php
                        $carbonDate = \Carbon\Carbon::parse($date);
                        $serverBadge = $carbonDate->isToday() ? 'today' : ($carbonDate->isTomorrow() ? 'tomorrow' : '');
                    @endphp
                    <div class="space-y-2">
                        {{-- Date Header --}}
                        <div class="flex items-center gap-2">
                            <flux:heading size="sm" class="text-zinc-700 dark:text-zinc-300">
                                {{ $carbonDate->format('D, M j, Y') }}
                            </flux:heading>
                            <span
                                x-data="{ 
                                    badge: '{{ $serverBadge }}',
                                    init() {
                                        let p = '{{ $date }}'.split('-');
                                        let d = new Date(p[0], p[1]-1, p[2]); d.setHours(0,0,0,0);
                                        let t = new Date(); t.setHours(0,0,0,0);
                                        let tm = new Date(t); tm.setDate(tm.getDate()+1);
                                        this.badge = d.getTime() === t.getTime() ? 'today' : (d.getTime() === tm.getTime() ? 'tomorrow' : '');
                                    }
                                }"
                                x-cloak
                            >
                                <template x-if="badge === 'today'">
                                    <flux:badge color="green" size="sm">Today</flux:badge>
                                </template>
                                <template x-if="badge === 'tomorrow'">
                                    <flux:badge color="sky" size="sm">Tomorrow</flux:badge>
                                </template>
                            </span>
                        </div>

                        {{-- Task Cards for this date --}}
                        <div class="space-y-2 pl-0">
                            @foreach($tasks as $task)
                                @php
                                    $typeUi = $task->type_ui ?? [];
                                    $taskTypeTextClasses = data_get($typeUi, 'text', '');
                                    $taskUsers = $task->users ?? collect();
                                    $taskVendor = $task->vendor;
                                    
                                    // Arrival time
                                    $arrivalTimeLabel = null;
                                    $dayFormat = $carbonDate->format('Y-m-d');
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
                                <flux:kanban.card
                                    as="button"
                                    class="min-w-0 w-full"
                                    wire:key="user-task-{{ $task->id }}"
                                    wire:click="$dispatchTo('tasks.task-create', 'editTask', { task: {{ $task->id }} })"
                                >
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
                                    </div>

                                    @if($task->project)
                                        <div class="mt-1">
                                            <a 
                                                href="{{ route('projects.show', $task->project) }}"
                                                wire:click.stop
                                                class="text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300 hover:underline truncate"
                                            >
                                                {{ $task->project->short_address ?? 'No project' }}
                                                @if($task->project->client)
                                                    — {{ $task->project->client->name }}
                                                @endif
                                            </a>
                                        </div>
                                    @endif

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
                                                
                                                @if($task->vendor_status)
                                                    @php $statusUi = $task->vendor_status_ui; @endphp
                                                    <flux:badge 
                                                        size="sm" 
                                                        :color="$statusUi['flux'] ?? 'zinc'"
                                                        :icon="$statusUi['icon'] ?? null"
                                                    >
                                                        {{ $statusUi['label'] ?? ucfirst($task->vendor_status) }}
                                                    </flux:badge>
                                                @endif
                                            @endif
                                        </div>
                                    @endif
                                </flux:kanban.card>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </flux:card>
</div>
