<div>
    <flux:modal wire:model="showModal" name="send-schedule-modal" class="w-full max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">Confirm Tasks</flux:heading>

            @if (empty($this->clientProjectIds))
                <flux:callout variant="warning" icon="exclamation-triangle">
                    No projects found for this contact.
                </flux:callout>
            @else
                {{-- Task list --}}
                <div class="space-y-1">
                    <flux:text class="text-sm font-medium">Tasks</flux:text>
                    @if ($this->groupedUpcomingTasks->flatten(1)->isEmpty() && $this->pendingTasks->isEmpty() && $this->nextUpcomingTasks->isEmpty())
                        <div class="py-6 text-center">
                            <flux:icon name="calendar" class="mx-auto h-8 w-8 text-zinc-300 dark:text-zinc-600" />
                            <flux:text class="mt-2 text-sm text-zinc-400">No upcoming tasks</flux:text>
                        </div>
                    @else
                        <div class="max-h-96 overflow-y-auto space-y-4">
                            @foreach ($this->groupedUpcomingTasks as $date => $tasks)
                                @php
                                    $carbonDate = \Carbon\Carbon::parse($date);
                                    $isWeekend = $carbonDate->isWeekend();
                                    $hasTasks = $tasks->isNotEmpty();
                                    $showProjectInfo = count($this->clientProjectIds) > 1;
                                @endphp

                                <div
                                    wire:key="day-{{ $date }}"
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
                                    {{-- Date Header --}}
                                    <div class="flex items-center gap-2 min-h-6">
                                        <flux:heading size="sm" ::class="textColorClass">
                                            {{ $carbonDate->format('D, M j, Y') }}
                                        </flux:heading>
                                        <template x-if="badge === 'today'">
                                            <flux:badge color="indigo" size="sm">Today</flux:badge>
                                        </template>
                                        <template x-if="badge === 'tomorrow'">
                                            <flux:badge color="sky" size="sm">Tomorrow</flux:badge>
                                        </template>
                                        <template x-if="badge === 'yesterday'">
                                            <flux:badge color="zinc" size="sm">Yesterday</flux:badge>
                                        </template>
                                        @if($tasks->isEmpty())
                                            <flux:badge color="zinc" size="sm">No Tasks</flux:badge>
                                        @endif

                                        <flux:button
                                            variant="subtle"
                                            icon="plus"
                                            size="xs"
                                            class="ml-auto"
                                            wire:click="openCreateTaskForDate('{{ $date }}')"
                                            tooltip="Add task for this date"
                                            aria-label="Add task for {{ $carbonDate->format('D, M j, Y') }}"
                                        />
                                    </div>

                                    {{-- Task cards --}}
                                    @if($hasTasks)
                                        @include('components.upcoming-tasks-list-tasks', [
                                            'tasks' => $tasks,
                                            'date' => $date,
                                            'carbonDate' => $carbonDate,
                                            'showAvatars' => true,
                                            'clickable' => true,
                                            'showProjectInfo' => $showProjectInfo,
                                            'showVendorInfo' => true,
                                        ])
                                    @endif
                                </div>
                            @endforeach

                            {{-- Next upcoming tasks beyond the 3-day window --}}
                            @if ($this->nextUpcomingTasks->isNotEmpty())
                                @php $nextTasks = $this->nextUpcomingTasks; @endphp
                                <div wire:key="day-next-up" class="space-y-2">
                                    <div class="flex items-center gap-2 min-h-6">
                                        <flux:heading size="sm" class="text-zinc-700 dark:text-zinc-300">
                                            {{ \Carbon\Carbon::parse($nextTasks->first()->start_date)->format('D, M j, Y') }}
                                        </flux:heading>
                                        <flux:badge color="sky" size="sm">Next Up</flux:badge>
                                    </div>
                                    @include('components.upcoming-tasks-list-tasks', [
                                        'tasks' => $nextTasks,
                                        'date' => $nextTasks->first()->start_date->format('Y-m-d'),
                                        'carbonDate' => \Carbon\Carbon::parse($nextTasks->first()->start_date),
                                        'showAvatars' => true,
                                        'clickable' => true,
                                        'showProjectInfo' => count($this->clientProjectIds) > 1,
                                        'showVendorInfo' => true,
                                    ])
                                </div>
                            @endif

                            {{-- Pending tasks (no dates) --}}
                            @if($this->pendingTasks->isNotEmpty())
                                <div wire:key="day-pending" class="space-y-2">
                                    <div class="flex items-center gap-2 min-h-6">
                                        <flux:heading size="sm" class="text-zinc-700 dark:text-zinc-300">
                                            Pending
                                        </flux:heading>
                                        <flux:badge color="amber" size="sm">{{ $this->pendingTasks->count() }}</flux:badge>
                                    </div>

                                    @include('components.upcoming-tasks-list-tasks', [
                                        'tasks' => $this->pendingTasks,
                                        'date' => null,
                                        'carbonDate' => null,
                                        'showAvatars' => true,
                                        'clickable' => true,
                                        'showProjectInfo' => count($this->clientProjectIds) > 1,
                                        'showVendorInfo' => true,
                                    ])
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

                {{-- Editable message --}}
                @if ($this->editableMessage)
                    <div>
                        <flux:textarea
                            wire:model="editableMessage"
                            label="Message"
                            rows="8"
                            size="sm"
                        />
                    </div>
                @endif
            @endif

            {{-- Actions --}}
            <div class="flex justify-end gap-2 pt-2">
                <flux:button
                    variant="ghost"
                    icon="calendar"
                    wire:click="useDatedSchedule"
                    :disabled="!$this->scheduleWithoutDate"
                >
                    Schedule With Date
                </flux:button>
                <flux:button
                    variant="ghost"
                    icon="calendar-days"
                    wire:click="useNoDateSchedule"
                    :disabled="$this->scheduleWithoutDate"
                >
                    Schedule No Date
                </flux:button>
                <flux:button variant="ghost" wire:click="close">Cancel</flux:button>
                <flux:button
                    variant="primary"
                    icon="paper-airplane"
                    wire:click="send"
                    wire:loading.attr="disabled"
                    :disabled="!$this->editableMessage"
                >
                    Confirm Tasks
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
