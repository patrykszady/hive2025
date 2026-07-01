<div class="min-h-screen bg-zinc-100 dark:bg-zinc-900">
    {{-- Header --}}
    <div class="border-b border-zinc-200 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800">
        <div class="max-w-lg mx-auto flex items-center gap-2.5">
            <a href="https://hive.contractors" target="_blank" rel="noopener noreferrer" class="flex items-center gap-2.5">
                <x-hive-logo class="size-6 shrink-0" />
                <span class="text-base font-semibold text-zinc-900 dark:text-white">Hive Contractors</span>
            </a>
            @if($valid && $vendor)
                <span class="ml-auto text-sm text-zinc-500 dark:text-zinc-400">Hi {{ $vendor->short_name ?? $vendor->name }}!</span>
            @endif
        </div>
    </div>

    <div class="max-w-lg mx-auto px-4 py-6">
        @if(!$valid)
            {{-- Invalid Token --}}
            <flux:card class="text-center">
                <div class="w-16 h-16 mx-auto mb-4 bg-zinc-100 dark:bg-zinc-700 rounded-full flex items-center justify-center">
                    <flux:icon.exclamation-triangle class="size-8 text-zinc-400" />
                </div>
                <flux:text class="text-zinc-600 dark:text-zinc-400">{{ $message }}</flux:text>
            </flux:card>
        @elseif($tasks->isEmpty() && $pastTasks->isEmpty() && $pendingTasks->isEmpty())
            {{-- No Tasks --}}
            <flux:card class="text-center">
                <div class="w-16 h-16 mx-auto mb-4 bg-green-100 dark:bg-green-900/30 rounded-full flex items-center justify-center">
                    <flux:icon.check class="size-8 text-green-600 dark:text-green-400" />
                </div>
                <flux:text class="text-zinc-600 dark:text-zinc-400">All done! No tasks to show.</flux:text>
            </flux:card>
        @else
            <div class="space-y-4">

            {{-- Past Tasks (collapsed accordion) --}}
            @if($pastTasks->isNotEmpty())
                <flux:accordion transition>
                    <flux:accordion.item>
                        <flux:accordion.heading>
                            <div class="flex items-center gap-2">
                                <span class="text-zinc-500 dark:text-zinc-400">Past Tasks</span>
                                <flux:badge color="zinc" size="sm">{{ $pastTasks->count() }}</flux:badge>
                            </div>
                        </flux:accordion.heading>
                        <flux:accordion.content>
                            <div class="space-y-3 mt-2">
                                @foreach($pastTasks as $task)
                                    <div wire:key="past-task-{{ $task->id }}" class="bg-white dark:bg-zinc-900 rounded-lg shadow-sm border border-zinc-200 dark:border-zinc-700 overflow-hidden opacity-60">
                                        <div class="p-3">
                                            <div class="flex items-start justify-between gap-2 min-w-0">
                                                <div class="flex items-center gap-2 min-w-0">
                                                    <flux:heading size="sm" class="min-w-0 truncate line-through text-zinc-400">
                                                        {{ $task->title }}
                                                    </flux:heading>
                                                    <flux:badge size="sm" :color="data_get($task->type_ui, 'flux', 'sky')" inset="top bottom">
                                                        {{ $task->type ?? 'Task' }}
                                                    </flux:badge>
                                                </div>
                                                @if($task->vendor_status === 'confirmed')
                                                    <flux:badge color="green" size="sm" icon="check">Confirmed</flux:badge>
                                                @elseif($task->vendor_status === 'rejected')
                                                    <flux:badge color="red" size="sm" icon="x-mark">Declined</flux:badge>
                                                @else
                                                    <flux:badge color="zinc" size="sm">Past</flux:badge>
                                                @endif
                                            </div>
                                            <div class="mt-1 flex items-center gap-1.5 text-xs text-zinc-400">
                                                <flux:icon.clock class="size-3.5 shrink-0" />
                                                <span>{{ $task->date_with_time }}</span>
                                            </div>
                                            @if($task->project?->address)
                                                <div class="mt-1 flex items-center gap-1.5 text-xs text-zinc-400">
                                                    <flux:icon.map-pin class="size-3.5 shrink-0" />
                                                    <span>{{ $task->project->address }}</span>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </flux:accordion.content>
                    </flux:accordion.item>
                </flux:accordion>
            @endif

            {{-- Upcoming Confirmed Tasks (grouped by date, matching hub UI) --}}
            @if($groupedTasks->isNotEmpty())
                <?php $renderedTaskIds = []; ?>
                <div class="space-y-5">
                    @foreach($groupedTasks as $date => $dayTasks)
                        <x-schedule.day-group :date="$date" :count="$dayTasks->count()" :is-first="$loop->first">
                            @foreach($dayTasks as $task)
                                @php
                                    $showFooter = ! in_array($task->id, $renderedTaskIds, true);
                                    if ($showFooter) {
                                        $renderedTaskIds[] = $task->id;
                                    }

                                    $footerType = null;
                                    if ($showFooter && in_array((string) $task->vendor_status, ['requested', 'proposed'], true)) {
                                        $footerType = 'actions';
                                    } elseif ($showFooter && $task->vendor_status === 'confirmed') {
                                        $footerType = 'confirmed';
                                    }
                                @endphp
                                <x-schedule.task-card wire:key="scheduled-task-{{ $date }}-{{ $task->id }}">
                                    <x-schedule.task-card-body :task="$task" :date="$date" :show-project="true" />

                                    @if($footerType)
                                        <x-slot:footer>
                                            @if($footerType === 'actions')
                                                <button
                                                    wire:click="confirm({{ $task->id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:loading.class="opacity-60 cursor-wait"
                                                    wire:target="confirm({{ $task->id }})"
                                                    class="flex-1 flex items-center justify-center gap-2 px-4 py-3 text-sm font-medium text-green-600 dark:text-green-400 hover:bg-green-50 dark:hover:bg-green-900/20 transition-colors border-r border-zinc-200 dark:border-zinc-700"
                                                >
                                                    <flux:icon.check class="size-5" />
                                                    <span>Available</span>
                                                </button>
                                                <button
                                                    wire:click="openProposeDatesModal({{ $task->id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:loading.class="opacity-60 cursor-wait"
                                                    wire:target="openProposeDatesModal({{ $task->id }})"
                                                    class="flex-1 flex items-center justify-center gap-2 px-4 py-3 text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition-colors border-r border-zinc-200 dark:border-zinc-700"
                                                >
                                                    <flux:icon.calendar class="size-5" />
                                                    <span>Change</span>
                                                </button>
                                                <button
                                                    wire:click="reject({{ $task->id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:loading.class="opacity-60 cursor-wait"
                                                    wire:target="reject({{ $task->id }})"
                                                    class="flex-1 flex items-center justify-center gap-2 px-4 py-3 text-sm font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
                                                >
                                                    <flux:icon.x-mark class="size-5" />
                                                    <span>Decline</span>
                                                </button>
                                            @else
                                                <button
                                                    wire:click="openProposeDatesModal({{ $task->id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:loading.class="opacity-60 cursor-wait"
                                                    wire:target="openProposeDatesModal({{ $task->id }})"
                                                    class="flex-1 flex items-center justify-center gap-2 px-4 py-3 text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition-colors border-r border-zinc-200 dark:border-zinc-700"
                                                >
                                                    <flux:icon.calendar class="size-5" />
                                                    Change
                                                </button>
                                                <button
                                                    wire:click="markPending({{ $task->id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:loading.class="opacity-60 cursor-wait"
                                                    wire:target="markPending({{ $task->id }})"
                                                    class="flex-1 flex items-center justify-center gap-2 px-4 py-3 text-sm font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
                                                >
                                                    <flux:icon.x-mark class="size-5" />
                                                    Decline
                                                </button>
                                            @endif
                                        </x-slot:footer>
                                    @endif
                                </x-schedule.task-card>
                            @endforeach
                        </x-schedule.day-group>
                    @endforeach
                </div>
            @endif

            {{-- Pending Tasks: unscheduled (no dates), expanded accordion --}}
            @if($pendingTasks->isNotEmpty())
                <flux:accordion transition>
                    <flux:accordion.item expanded>
                        <flux:accordion.heading>
                            <div class="flex items-center gap-2">
                                <span class="text-orange-600 dark:text-orange-400">Pending Tasks</span>
                                <flux:badge color="amber" size="sm">{{ $pendingTasks->count() }}</flux:badge>
                            </div>
                        </flux:accordion.heading>
                        <flux:accordion.content>
                            <div class="space-y-3 mt-2">
                                @foreach($pendingTasks as $task)
                                    <div wire:key="pending-task-{{ $task->id }}" class="bg-white dark:bg-zinc-900 rounded-lg shadow-sm border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                                        <div class="p-3">
                                            <div class="flex items-start justify-between gap-2 min-w-0">
                                                <div class="flex items-center gap-2 min-w-0">
                                                    <flux:heading size="sm" class="min-w-0 truncate">
                                                        {{ $task->title }}
                                                    </flux:heading>
                                                    <flux:badge size="sm" :color="data_get($task->type_ui, 'flux', 'sky')" inset="top bottom">
                                                        {{ $task->type ?? 'Task' }}
                                                    </flux:badge>
                                                </div>
                                                <flux:badge color="red" size="sm">No Date</flux:badge>
                                            </div>

                                            {{-- Address --}}
                                            @if($task->project?->address)
                                                @php
                                                    $pendingCityStateZip = trim(implode(' ', array_filter([
                                                        collect([$task->project?->city, $task->project?->state])->filter()->implode(', '),
                                                        $task->project?->zip_code,
                                                    ])));
                                                @endphp
                                                <div class="mt-2 flex items-start gap-1.5 text-sm text-zinc-600 dark:text-zinc-400">
                                                    <flux:icon.map-pin class="size-4 shrink-0 mt-0.5" />
                                                    <div>
                                                        <div class="truncate">{{ $task->project->address }}</div>
                                                        @if($pendingCityStateZip)
                                                            <div>{{ $pendingCityStateZip }}</div>
                                                        @endif
                                                    </div>
                                                </div>
                                            @endif

                                            {{-- Owner --}}
                                            @if($task->owner)
                                                <div class="flex items-center gap-2 mt-3 min-w-0">
                                                    <flux:avatar
                                                        circle
                                                        size="xs"
                                                        name="{{ $task->owner->full_name ?? $task->owner->name }}"
                                                        color="auto"
                                                        color:seed="{{ $task->owner->id }}"
                                                    />
                                                    <span class="flex-1 min-w-0 truncate text-xs text-zinc-600 dark:text-zinc-400">
                                                        {{ $task->owner->short_name ?? $task->owner->name }}
                                                    </span>
                                                </div>
                                            @endif
                                        </div>

                                        {{-- Footer action --}}
                                        <div class="flex border-t border-zinc-200 dark:border-zinc-700">
                                            <button
                                                wire:click="openProposeDatesModal({{ $task->id }})"
                                                wire:loading.attr="disabled"
                                                wire:loading.class="opacity-60 cursor-wait"
                                                wire:target="openProposeDatesModal({{ $task->id }})"
                                                class="flex-1 flex items-center justify-center gap-2 px-4 py-3 text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition-colors"
                                            >
                                                <flux:icon.calendar class="size-5" />
                                                Set Dates / Schedule
                                            </button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </flux:accordion.content>
                    </flux:accordion.item>
                </flux:accordion>
            @endif

            </div>{{-- end space-y-4 --}}
        @endif

        {{-- Registration CTA --}}
        <div class="mt-6 rounded-xl border border-indigo-200 dark:border-indigo-800 bg-indigo-50 dark:bg-indigo-900/20 p-5 text-center">
            <div class="mx-auto mb-3 flex size-12 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900/40">
                <x-hive-logo class="size-7" />
            </div>
            <flux:heading size="sm" class="text-indigo-900 dark:text-indigo-100">Join Hive Contractors</flux:heading>
            <flux:text class="mt-2 text-sm text-indigo-700 dark:text-indigo-300">
                Register a Hive account to confirm availability, update arrival times, and stay connected with Hive Contractors.
            </flux:text>
            <flux:text class="mt-2 text-sm text-indigo-700 dark:text-indigo-300">
                You’ll also be able to see project details, notifications, and schedule changes in one place.
            </flux:text>
            <div class="mt-4 flex items-center justify-center gap-3">
                <flux:button variant="primary" href="{{ route('registration') }}">
                    Register
                </flux:button>
                <flux:button href="{{ route('login') }}">
                    Login
                </flux:button>
            </div>
        </div>

        <x-public-schedule-footer />
    </div>

    {{-- Propose Dates Modal --}}
    <flux:modal name="vendor_propose_dates_modal" class="space-y-4 max-w-md">
        <div class="space-y-1">
            <flux:heading size="lg">Select Different Dates</flux:heading>
            <flux:subheading>Select the dates you're available for this task.</flux:subheading>
        </div>

        <flux:separator variant="subtle" />

        {{-- Dates Calendar --}}
        <flux:field>
            <flux:label>Select Days</flux:label>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-3">
                <flux:calendar
                    multiple
                    with-today
                    size="sm"
                    wire:model.live="proposedDates"
                />
            </div>
        </flux:field>

        {{-- Time Settings for each selected date --}}
        @if(!empty($proposedDates))
            <flux:field>
                <flux:label>Arrival Time</flux:label>
                
                <div class="space-y-3 max-h-48 overflow-y-auto">
                    @foreach($proposedDates as $date)
                        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-3 space-y-2">
                            <div class="flex items-center justify-between">
                                <flux:subheading class="text-sm">
                                    {{ \Carbon\Carbon::parse($date)->format('D, M j') }}
                                </flux:subheading>
                                <flux:switch 
                                    wire:model.live="proposedTimeSettings.{{ $date }}.use_time"
                                    size="sm"
                                />
                            </div>

                            @if($proposedTimeSettings[$date]['use_time'] ?? false)
                                <div class="grid grid-cols-2 gap-2 pt-2 border-t border-zinc-100 dark:border-zinc-800">
                                    <div class="relative"
                                        x-data="{
                                            get _t() {
                                                const v = (this.$wire.proposedTimeSettings?.['{{ $date }}']?.start_time) || '08:00';
                                                const [h, m] = v.split(':').map(Number);
                                                return { h: isNaN(h) ? 8 : h, m: isNaN(m) ? 0 : m };
                                            },
                                            get hour() {
                                                const a = ((this._t.h % 12) * 30 + this._t.m * 0.5 - 90) * Math.PI / 180;
                                                return { x: 12 + 4 * Math.cos(a), y: 12 + 4 * Math.sin(a) };
                                            },
                                            get minute() {
                                                const a = (this._t.m * 6 - 90) * Math.PI / 180;
                                                return { x: 12 + 6 * Math.cos(a), y: 12 + 6 * Math.sin(a) };
                                            },
                                        }">
                                        <flux:time-picker
                                            wire:model.live="proposedTimeSettings.{{ $date }}.start_time"
                                            wire:change="updateEndTime('{{ $date }}')"
                                            interval="30"
                                            min="06:00"
                                            max="23:00"
                                            open-to="08:00"
                                            placeholder="Start"
                                        />
                                        <svg viewBox="0 0 24 24" aria-hidden="true"
                                            class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 size-4 text-zinc-500 dark:text-zinc-400">
                                            <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.5"/>
                                            <line x1="12" y1="12" :x2="hour.x" :y2="hour.y" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
                                            <line x1="12" y1="12" :x2="minute.x" :y2="minute.y" stroke="currentColor" stroke-width="1.25" stroke-linecap="round"/>
                                        </svg>
                                    </div>
                                    <div class="relative"
                                        x-data="{
                                            get _t() {
                                                const v = (this.$wire.proposedTimeSettings?.['{{ $date }}']?.end_time) || '17:00';
                                                const [h, m] = v.split(':').map(Number);
                                                return { h: isNaN(h) ? 17 : h, m: isNaN(m) ? 0 : m };
                                            },
                                            get hour() {
                                                const a = ((this._t.h % 12) * 30 + this._t.m * 0.5 - 90) * Math.PI / 180;
                                                return { x: 12 + 4 * Math.cos(a), y: 12 + 4 * Math.sin(a) };
                                            },
                                            get minute() {
                                                const a = (this._t.m * 6 - 90) * Math.PI / 180;
                                                return { x: 12 + 6 * Math.cos(a), y: 12 + 6 * Math.sin(a) };
                                            },
                                        }">
                                        <flux:time-picker
                                            wire:model.live="proposedTimeSettings.{{ $date }}.end_time"
                                            interval="30"
                                            :min="!empty($proposedTimeSettings[$date]['start_time']) ? \Carbon\Carbon::createFromFormat('H:i', $proposedTimeSettings[$date]['start_time'])->addMinutes(30)->format('H:i') : '06:00'"
                                            max="23:00"
                                            open-to="17:00"
                                            placeholder="End"
                                        />
                                        <svg viewBox="0 0 24 24" aria-hidden="true"
                                            class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 size-4 text-zinc-500 dark:text-zinc-400">
                                            <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.5"/>
                                            <line x1="12" y1="12" :x2="hour.x" :y2="hour.y" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>
                                            <line x1="12" y1="12" :x2="minute.x" :y2="minute.y" stroke="currentColor" stroke-width="1.25" stroke-linecap="round"/>
                                        </svg>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </flux:field>
        @endif

        <div class="flex gap-2 mb-0!">
            <flux:button wire:click="cancelProposal" variant="ghost" class="flex-1">
                Cancel
            </flux:button>
            <flux:button 
                wire:click="saveProposedDates" 
                variant="primary" 
                wire:loading.attr="disabled"
                wire:loading.class="opacity-50"
                wire:target="saveProposedDates"
                class="flex-1"
                :disabled="empty($proposedDates)"
            >
                Update Dates
            </flux:button>
        </div>
    </flux:modal>
</div>
