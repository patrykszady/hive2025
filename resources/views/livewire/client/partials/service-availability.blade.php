{{-- Preferred Service Times picker for Service Call projects --}}
@php
    $serviceDays = $this->serviceDays;
    $focusedDay = $focusedServiceDay && in_array($focusedServiceDay, $serviceDays, true)
        ? $focusedServiceDay
        : ($serviceDays[0] ?? null);
    $selectedByDay = $this->selectedServiceByDay;

    $dayMeta = collect($serviceDays)->map(function (string $day) {
        $carbon = \Carbon\Carbon::parse($day);

        return [
            'date' => $day,
            'dow' => $carbon->format('D'),
            'dom' => $carbon->format('j'),
            'mon' => $carbon->format('M'),
            'full' => $carbon->format('l, M j'),
            'short' => $carbon->format('D, M j'),
            'isWeekend' => $carbon->isWeekend(),
        ];
    })->values()->all();

    $initialFocusedDay = $focusedDay;

    if (! $initialFocusedDay || (collect($dayMeta)->firstWhere('date', $initialFocusedDay)['isWeekend'] ?? false)) {
        $initialFocusedDay = collect($dayMeta)->firstWhere('isWeekend', false)['date'] ?? ($dayMeta[0]['date'] ?? null);
    }
@endphp

<flux:card
    class="mb-6"
    x-data="{
        selected: $wire.entangle('selectedServiceSlots'),
        focusedDay: @js($initialFocusedDay),
        days: @js($dayMeta),
        maxDays: 90,
        slots: @js($this->serviceTimeSlots),
        parseDate(str) { const [y, m, d] = str.split('-').map(Number); return new Date(y, m - 1, d) },
        makeDay(d) {
            const pad = (n) => String(n).padStart(2, '0');
            const dow = d.getDay();
            return {
                date: `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`,
                dow: d.toLocaleDateString('en-US', { weekday: 'short' }),
                dom: String(d.getDate()),
                mon: d.toLocaleDateString('en-US', { month: 'short' }),
                full: d.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric' }),
                short: d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }),
                isWeekend: dow === 0 || dow === 6,
            };
        },
        extendDays(count = 14) {
            if (this.days.length >= this.maxDays) { return }

            const last = this.days.length ? this.days[this.days.length - 1].date : null;
            if (! last) { return }

            const cursor = this.parseDate(last);
            const added = [];
            for (let i = 0; i < count && this.days.length + added.length < this.maxDays; i++) {
                cursor.setDate(cursor.getDate() + 1);
                added.push(this.makeDay(cursor));
            }
            this.days = [...this.days, ...added];
        },
        key(day, time) { return day + '|' + time },
        isSelected(day, time) { return this.selected.includes(this.key(day, time)) },
        isDisabled(day) { return this.days.find(d => d.date === day)?.isWeekend ?? false },
        toggle(day, time) {
            if (this.isDisabled(day)) { return }

            const k = this.key(day, time);
            const anytimeKey = this.key(day, 'Anytime');
            const dayPrefix = day + '|';
            const i = this.selected.indexOf(k);

            if (time === 'Anytime') {
                if (i === -1) {
                    this.selected = this.selected.filter(slot => ! slot.startsWith(dayPrefix));
                    this.selected.push(k);
                } else {
                    this.selected.splice(i, 1);
                }

                return;
            }

            if (this.selected.includes(anytimeKey)) {
                this.selected = this.selected.filter(slot => slot !== anytimeKey);
            }

            if (i === -1) {
                this.selected.push(k)
            } else {
                this.selected.splice(i, 1)
            }
        },
        daySelectedCount(day) { return this.selected.filter(s => s.startsWith(day + '|')).length },
        get slotCount() { return this.selected.length },
        get dayCount() { return new Set(this.selected.map(s => s.split('|')[0])).size },
        get meetsMinimum() { return this.slotCount >= 3 && this.dayCount >= 3 },
        get focusedMeta() { return this.days.find(d => d.date === this.focusedDay) || this.days[0] },
        get groupedSelected() {
            const groups = {};
            [...this.selected].sort().forEach(s => {
                const [day, time] = s.split('|');
                (groups[day] = groups[day] || []).push(time);
            });
            return groups;
        },
        labelFor(day) { const m = this.days.find(d => d.date === day); return m ? m.short : day },
    }"
>
    <div class="flex items-start gap-2.5">
        <div class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-orange-100 dark:bg-orange-900/30">
            <flux:icon.calendar-days class="size-5 text-orange-600 dark:text-orange-400" />
        </div>
        <div>
            <flux:heading size="lg">Preferred Service Times</flux:heading>
            <flux:text class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                Pick at least 3 times across 3 days, ideally in different timeframes.
            </flux:text>
        </div>
    </div>

    @php($pendingTasks = $this->pickerPendingTasks)
    @if($pendingTasks->isNotEmpty())
        <div class="mt-4 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
            <div class="flex items-center justify-between gap-2">
                <flux:text class="text-xs font-semibold uppercase tracking-wide text-orange-600 dark:text-orange-400">Pending Tasks</flux:text>
                <flux:badge color="amber" size="sm">{{ $pendingTasks->count() }}</flux:badge>
            </div>

            <div class="mt-3 space-y-2">
                @foreach($pendingTasks as $task)
                    <x-task-card
                        :task="$task"
                        date=""
                        :clickable="false"
                        :show-avatars="false"
                        :show-vendor-info="false"
                        wire:key="pending-task-{{ $task->id }}"
                    />
                @endforeach
            </div>

            @if($pendingTasks->count() > 1)
                <flux:text class="mt-3 text-xs italic text-zinc-500 dark:text-zinc-400">
                    We’ll try to bundle these when possible.
                </flux:text>
            @endif
        </div>
    @endif

    @if($serviceAvailabilitySubmitted)
        @php($project = $this->getProject())
        @php($contractorName = $project?->createdByVendor?->short_name ?? $project?->createdByVendor?->name ?? 'your contractor')
        @php($taskVendorNames = $pendingTasks->map(fn ($task) => $task->vendor?->short_name ?? $task->vendor?->name)->filter()->unique()->values())
        @php($taskVendorName = $taskVendorNames->count() === 1 ? $taskVendorNames->first() : ($taskVendorNames->isNotEmpty() ? $taskVendorNames->join(', ') : $contractorName))
        {{-- Confirmation state --}}
        <div class="mt-5 rounded-xl border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-900/20">
            <div class="flex items-center gap-2">
                <flux:icon.check-circle class="size-5 text-green-600 dark:text-green-400" />
                <flux:text class="font-medium text-green-800 dark:text-green-200">Times sent to {{ $contractorName }}.</flux:text>
                <flux:button size="sm" variant="ghost" icon="pencil-square" class="ms-auto" wire:click="editServiceAvailability">
                    Change
                </flux:button>
            </div>

            <div class="mt-3 space-y-2">
                @foreach($selectedByDay as $day => $times)
                    <div wire:key="confirmed-{{ $day }}" class="flex flex-wrap items-center gap-2">
                        <span class="w-28 shrink-0 text-sm font-medium text-zinc-700 dark:text-zinc-300">
                            {{ \Carbon\Carbon::parse($day)->format('D, M j') }}
                        </span>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($times as $time)
                                <flux:badge wire:key="confirmed-{{ $day }}-{{ $time }}" size="sm" color="green">{{ $time }}</flux:badge>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            <flux:text class="mt-3 text-sm text-green-700 dark:text-green-300">
                {{ $taskVendorName }} will be in touch ASAP.
            </flux:text>
        </div>
    @else
        {{-- Picker state (fully client-side via Alpine; syncs to Livewire on submit) --}}
        {{-- Day selector --}}
        <div class="forward-modal-scroll mt-3 -mx-6 flex gap-1.5 overflow-x-auto overflow-y-hidden pb-1">
            <template x-for="day in days" :key="day.date">
                <button
                    type="button"
                    @click="! day.isWeekend && (focusedDay = day.date)"
                    class="relative flex shrink-0 flex-col items-center rounded-xl border px-2 py-2 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-400 focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:focus-visible:ring-offset-zinc-900"
                    :disabled="day.isWeekend"
                    :class="(day.date === focusedDay
                        ? 'border-indigo-500 bg-indigo-50 dark:border-indigo-400 dark:bg-indigo-900/30'
                        : (daySelectedCount(day.date) > 0
                            ? 'border-green-500 bg-green-50 dark:border-green-400 dark:bg-green-900/30'
                            : 'border-zinc-200 bg-white hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-zinc-600'))
                        + (day.isWeekend ? ' w-11 cursor-not-allowed opacity-45 grayscale' : ' w-16')"
                >
                    <span class="text-[11px] font-medium uppercase text-zinc-400" x-text="day.dow"></span>
                    <span
                        class="text-base font-semibold"
                        :class="day.date === focusedDay
                            ? 'text-indigo-600 dark:text-indigo-300'
                            : (daySelectedCount(day.date) > 0
                                ? 'text-green-700 dark:text-green-300'
                                : 'text-zinc-800 dark:text-zinc-100')"
                        x-text="day.dom"
                    ></span>
                    <span
                        class="text-[10px] uppercase"
                        :class="daySelectedCount(day.date) > 0 && day.date !== focusedDay
                            ? 'text-green-500 dark:text-green-400'
                            : 'text-zinc-400'"
                        x-text="day.mon"
                    ></span>
                </button>
            </template>

            <template x-if="days.length < maxDays">
                <div
                    x-intersect.margin.0px.200px.0px.0px="extendDays(14)"
                    class="flex w-10 shrink-0 items-center justify-center text-zinc-300 dark:text-zinc-600"
                    aria-hidden="true"
                >
                    <flux:icon.chevron-right class="size-5" />
                </div>
            </template>
        </div>

        {{-- Time-frame options for focused day --}}
        <div class="mt-4" x-show="focusedDay && ! isDisabled(focusedDay)">
            <flux:text class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                <span x-text="focusedMeta?.full"></span>
            </flux:text>
            <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
                <template x-for="slot in slots" :key="slot">
                    <button
                        type="button"
                        @click="toggle(focusedDay, slot)"
                        class="flex items-center justify-center gap-1.5 rounded-lg border px-3 py-2.5 text-sm font-medium transition"
                        :class="isSelected(focusedDay, slot)
                            ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:border-indigo-400 dark:bg-indigo-900/30 dark:text-indigo-200'
                            : 'border-zinc-200 bg-white text-zinc-700 hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:border-zinc-600'"
                    >
                        <flux:icon.check variant="micro" class="size-3.5" x-show="isSelected(focusedDay, slot)" x-cloak />
                        <span x-text="slot"></span>
                    </button>
                </template>
            </div>
        </div>

        {{-- Selected summary --}}
        <div class="mt-5 border-t border-zinc-100 pt-4 dark:border-zinc-700" x-show="slotCount > 0" x-cloak>
            <flux:text class="text-xs font-semibold uppercase tracking-wide text-zinc-400">Selected times</flux:text>
            <div class="mt-2 space-y-2">
                <template x-for="(times, day) in groupedSelected" :key="day">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="w-24 shrink-0 text-sm font-medium text-zinc-700 dark:text-zinc-300" x-text="labelFor(day)"></span>
                        <div class="flex flex-wrap gap-1.5">
                            <template x-for="time in times" :key="time">
                                <button
                                    type="button"
                                    @click="toggle(day, time)"
                                    class="inline-flex items-center gap-1 rounded-md border border-indigo-500 bg-indigo-50 px-2 py-1 text-sm text-indigo-700 dark:border-indigo-400 dark:bg-indigo-900/30 dark:text-indigo-200"
                                >
                                    <span x-text="time"></span>
                                    <flux:icon.x-mark variant="micro" class="size-3.5" />
                                </button>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <div class="mt-5">
            <flux:button
                variant="primary"
                class="w-full"
                icon="paper-airplane"
                wire:click="submitServiceAvailability"
                wire:loading.attr="disabled"
                x-bind:disabled="! meetsMinimum"
            >
                Request
            </flux:button>
            <flux:text class="mt-2 text-center text-xs text-zinc-400" x-show="! meetsMinimum" x-cloak>
                Add 3 times across 3 days to Request.
            </flux:text>
        </div>
    @endif
</flux:card>

