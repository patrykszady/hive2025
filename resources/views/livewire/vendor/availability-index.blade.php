<div class="min-h-screen bg-zinc-100 dark:bg-zinc-900">
    {{-- Header --}}
    <div class="bg-white dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700 px-4 py-6">
        <div class="max-w-lg mx-auto text-center">
            <flux:heading size="xl">Task Availability</flux:heading>
            @if($valid && $vendor)
                <flux:subheading class="mt-1">Hi {{ $vendor->short_name ?? $vendor->name }}!</flux:subheading>
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
        @elseif($tasks->isEmpty())
            {{-- No Pending Tasks --}}
            <flux:card class="text-center">
                <div class="w-16 h-16 mx-auto mb-4 bg-green-100 dark:bg-green-900/30 rounded-full flex items-center justify-center">
                    <flux:icon.check class="size-8 text-green-600 dark:text-green-400" />
                </div>
                <flux:text class="text-zinc-600 dark:text-zinc-400">All done! No pending tasks to confirm.</flux:text>
            </flux:card>
        @else
            {{-- Pending Tasks List --}}
            <div class="space-y-3">
                @foreach($tasks as $task)
                    <div class="bg-white dark:bg-zinc-900 rounded-lg shadow-sm border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                        {{-- Task Card Content --}}
                        <div class="p-3">
                            <div class="flex items-start justify-between gap-2 min-w-0">
                                <flux:heading size="sm" class="min-w-0 truncate">
                                    {{ $task->title }}
                                </flux:heading>
                                @if($task->vendor_status === 'confirmed')
                                    <flux:badge color="green" size="sm" icon="check">Confirmed</flux:badge>
                                @elseif($task->vendor_status === 'rejected')
                                    <flux:badge color="red" size="sm" icon="x-mark">Declined</flux:badge>
                                @elseif($task->vendor_status === 'proposed')
                                    <flux:badge color="indigo" size="sm" icon="calendar">Proposed</flux:badge>
                                @else
                                    <flux:badge color="yellow" size="sm">Pending</flux:badge>
                                @endif
                            </div>

                            {{-- Project & Date Info --}}
                            <div class="mt-2 space-y-1">
                                @if($task->project?->address)
                                    @php
                                        $cityState = collect([
                                            $task->project?->city,
                                            $task->project?->state,
                                        ])->filter()->implode(', ');

                                        $cityStateZip = trim(implode(' ', array_filter([
                                            $cityState,
                                            $task->project?->zip_code,
                                        ])));
                                    @endphp

                                    <div class="flex items-start gap-1.5 text-sm text-zinc-600 dark:text-zinc-400">
                                        <flux:icon.map-pin class="size-4 shrink-0 mt-0.5" />
                                        <a 
                                            href="{{ $task->project->getAddressMapURI() }}" 
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="hover:underline hover:text-zinc-900 dark:hover:text-zinc-200 transition-colors"
                                        >
                                            <div class="truncate">{{ $task->project->address }}</div>
                                            @if($cityStateZip)
                                                <div>{{ $cityStateZip }}</div>
                                            @endif
                                        </a>
                                    </div>
                                @else
                                    <flux:subheading class="truncate">No location</flux:subheading>
                                @endif
                                
                                @php
                                    $startDate = $task->start_date?->format('Ymd\THis');
                                    $endDate = $task->end_date?->format('Ymd\THis') ?? $task->start_date?->addHours(2)->format('Ymd\THis');
                                    $location = $task->project?->full_address ?? '';
                                    $description = 'Task for ' . ($task->owner?->name ?? 'Hive');
                                    
                                    $icsContent = "BEGIN:VCALENDAR\r\n"
                                        . "VERSION:2.0\r\n"
                                        . "BEGIN:VEVENT\r\n"
                                        . "DTSTART:{$startDate}\r\n"
                                        . "DTEND:{$endDate}\r\n"
                                        . "SUMMARY:{$task->title}\r\n"
                                        . "LOCATION:{$location}\r\n"
                                        . "DESCRIPTION:{$description}\r\n"
                                        . "END:VEVENT\r\n"
                                        . "END:VCALENDAR";
                                    
                                    $calendarUrl = 'data:text/calendar;charset=utf-8,' . rawurlencode($icsContent);
                                @endphp
                                <div class="flex items-center gap-1.5 text-sm text-zinc-600 dark:text-zinc-400">
                                    <flux:icon.clock class="size-4 shrink-0" />
                                    <a 
                                        href="{{ $calendarUrl }}"
                                        download="{{ Str::slug($task->title) }}.ics"
                                        class="hover:underline hover:text-zinc-900 dark:hover:text-zinc-200 transition-colors"
                                    >{{ $task->date_with_time }}</a>
                                    @php
                                        $serverBadge = $task->start_date?->isToday() ? 'today' : ($task->start_date?->isTomorrow() ? 'tomorrow' : '');
                                    @endphp
                                    <span
                                        x-data="{ 
                                            badge: '{{ $serverBadge }}',
                                            init() {
                                                let p = '{{ $task->start_date?->format('Y-m-d') }}'.split('-');
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
                            </div>

                            {{-- Owner Avatar Row --}}
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

                        {{-- Action Buttons --}}
                        @if(in_array($task->vendor_status, ['requested', 'proposed']))
                            <div class="flex border-t border-zinc-200 dark:border-zinc-700">
                                <button 
                                    wire:click="confirm({{ $task->id }})"
                                    wire:loading.attr="disabled"
                                    wire:loading.class="opacity-60 cursor-wait"
                                    class="flex-1 flex items-center justify-center gap-2 px-4 py-3 text-sm font-medium text-green-600 dark:text-green-400 hover:bg-green-50 dark:hover:bg-green-900/20 transition-colors border-r border-zinc-200 dark:border-zinc-700"
                                >
                                    <flux:icon.check class="size-5" />
                                    <span class="hidden sm:inline">I'm Available</span>
                                    <span class="sm:hidden">Yes</span>
                                </button>
                                <button 
                                    wire:click="openProposeDatesModal({{ $task->id }})"
                                    wire:loading.attr="disabled"
                                    wire:loading.class="opacity-60 cursor-wait"
                                    class="flex-1 flex items-center justify-center gap-2 px-4 py-3 text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition-colors border-r border-zinc-200 dark:border-zinc-700"
                                >
                                    <flux:icon.calendar class="size-5" />
                                    <span class="hidden sm:inline">Different Dates</span>
                                    <span class="sm:hidden">Change</span>
                                </button>
                                <button 
                                    wire:click="reject({{ $task->id }})"
                                    wire:loading.attr="disabled"
                                    wire:loading.class="opacity-60 cursor-wait"
                                    class="flex-1 flex items-center justify-center gap-2 px-4 py-3 text-sm font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
                                >
                                    <flux:icon.x-mark class="size-5" />
                                    <span class="hidden sm:inline">Not Available</span>
                                    <span class="sm:hidden">No</span>
                                </button>
                            </div>
                        @elseif($task->vendor_status === 'confirmed')
                            <div class="flex border-t border-zinc-200 dark:border-zinc-700">
                                <button 
                                    wire:click="openProposeDatesModal({{ $task->id }})"
                                    wire:loading.attr="disabled"
                                    wire:loading.class="opacity-60 cursor-wait"
                                    class="flex-1 flex items-center justify-center gap-2 px-4 py-3 text-sm font-medium text-indigo-600 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition-colors"
                                >
                                    <flux:icon.calendar class="size-5" />
                                    Change Dates
                                </button>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Footer --}}
        <div class="text-center mt-8">
            <a href="{{ url('/') }}" class="inline-flex items-center gap-1.5 text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 transition-colors">
                <img src="{{ asset('favicon.png') }}" alt="Hive" class="size-4" />
                <span>Powered by Hive Contractors</span>
            </a>
        </div>
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
                                <div class="grid grid-cols-2 gap-2 pt-2 border-t border-zinc-100 dark:border-zinc-800 [&_[data-flux-time-picker-button]:not(:has([data-flux-time-picker-placeholder]))>[data-flux-icon]:first-child]:hidden">
                                    <flux:time-picker
                                        wire:model.live="proposedTimeSettings.{{ $date }}.start_time"
                                        wire:change="updateEndTime('{{ $date }}')"
                                        interval="60"
                                        min="06:00"
                                        max="23:00"
                                        open-to="08:00"
                                        placeholder="Start"
                                    />
                                    <flux:time-picker
                                        wire:model.live="proposedTimeSettings.{{ $date }}.end_time"
                                        interval="60"
                                        min="06:00"
                                        max="23:00"
                                        open-to="10:00"
                                        placeholder="End"
                                    />
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </flux:field>
        @endif

        <flux:separator variant="subtle" />

        <div class="flex gap-2">
            <flux:button wire:click="cancelProposal" variant="ghost" class="flex-1">
                Cancel
            </flux:button>
            <flux:button 
                wire:click="saveProposedDates" 
                variant="primary" 
                class="flex-1"
                :disabled="empty($proposedDates)"
            >
                Update Dates
            </flux:button>
        </div>
    </flux:modal>
</div>
