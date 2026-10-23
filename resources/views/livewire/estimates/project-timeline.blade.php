<x-page.shell width="wide" :cols="1" :breadcrumbs="[['label' => 'Project timeline']]">
    <x-page.column :span="1">
        {{-- CONTROLS + SUMMARY --}}
        <x-island-card heading="Estimate your project duration" subheading="Pick a length, then click the calendar for the start or the week the cabinets arrive.">
            <x-slot:actions>
                <div class="flex items-center gap-1">
                    @if ($start || $cabinets)
                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearDates">Clear dates</flux:button>
                    @endif
                    <flux:button size="sm" variant="ghost" icon="cog-6-tooth" :href="route('project-timeline.settings')" wire:navigate>Settings</flux:button>
                </div>
            </x-slot:actions>

            @php
                $step = 'inline-flex size-5 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold';
                $cabinetFlow = $clickSets === 'cabinets' || $cabinets !== null;
            @endphp
            <div class="space-y-5 pt-2 pb-3">
                {{-- The length comes first: the calendar previews nothing without one. --}}
                <flux:field>
                    <flux:label class="gap-2">
                        <span @class([$step, 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => ! $length, 'bg-green-500 text-white' => $length])>1</span>
                        Project length
                    </flux:label>
                    <flux:radio.group wire:model.live="length" variant="segmented" size="sm" class="w-full" data-timeline-length>
                        @foreach (\App\Services\Estimates\ProjectDuration::PRESETS as $key => $preset)
                            <flux:radio :value="$key" :label="$preset['label']" />
                        @endforeach
                    </flux:radio.group>
                </flux:field>

                <div class="grid items-end gap-x-6 gap-y-5 lg:grid-cols-3">
                    <flux:field>
                        <flux:label class="gap-2">
                            <span @class([$step, 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => ! $clickSets, 'bg-green-500 text-white' => $clickSets])>2</span>
                            Clicking a day sets
                        </flux:label>
                        <flux:radio.group wire:model.live="clickSets" variant="segmented" size="sm" class="w-full">
                            <flux:radio value="start" label="Project start" />
                            <flux:radio value="cabinets" label="Cabinet week" />
                        </flux:radio.group>
                    </flux:field>

                    <flux:field>
                        <flux:label class="gap-2">
                            <span @class([$step, 'bg-zinc-200 text-zinc-500 dark:bg-white/10 dark:text-zinc-400' => ! $cabinetFlow, 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $cabinetFlow && ! $prepWeeks, 'bg-green-500 text-white' => $cabinetFlow && $prepWeeks])>3</span>
                            Weeks of work before cabinets
                        </flux:label>
                        <flux:radio.group wire:model.live="prepWeeks" variant="segmented" size="sm" class="w-full">
                            @foreach (\App\Services\Estimates\ProjectDuration::PREP_WEEKS as $weeks)
                                <flux:radio :value="$weeks" :label="$weeks.'w'" :disabled="! $cabinetFlow" />
                            @endforeach
                        </flux:radio.group>
                    </flux:field>

                    <flux:checkbox.group wire:model.live="faiths" label="Homeowner's faith (optional)" variant="pills">
                        @foreach (\App\Support\FaithHolidays::FAITHS as $faith => $label)
                            <flux:checkbox :value="$faith" :label="$label" />
                        @endforeach
                    </flux:checkbox.group>
                </div>

                @if ($summary = $this->summary)
                    <div class="grid gap-4 rounded-lg bg-zinc-50 p-4 sm:grid-cols-2 lg:grid-cols-4 dark:bg-white/5" data-timeline-summary>
                        <div>
                            <flux:text size="sm">Start</flux:text>
                            @if ($summary['start'])
                                <flux:heading>{{ $summary['start']->format('D, M j, Y') }}</flux:heading>
                                @if ($summary['start_timing'] === 'best')
                                    <flux:text size="sm" class="text-emerald-600 dark:text-emerald-400">Right in the best time to start</flux:text>
                                @elseif ($summary['start_timing'] === 'early')
                                    <flux:text size="sm">Earlier than needed for the cabinets</flux:text>
                                @elseif ($summary['start_timing'] === 'late')
                                    <flux:text size="sm" class="text-red-600 dark:text-red-400">Later than the best start: not enough weeks before the cabinets</flux:text>
                                @endif
                            @else
                                <flux:heading>—</flux:heading>
                                <flux:text size="sm">Pick the weeks of work before cabinets.</flux:text>
                            @endif
                            @if ($summary['best_passed'])
                                <flux:text size="sm" class="text-red-600 dark:text-red-400">The best start has passed: fewer weeks are left before the cabinets than needed</flux:text>
                            @endif
                            @if ($summary['best_from'])
                                <flux:text size="sm" class="text-emerald-700 dark:text-emerald-300" data-timeline-best>Best start: {{ $summary['best_from']->format('D M j') }}{{ $summary['best_from']->equalTo($summary['best_to']) ? '' : ' – '.$summary['best_to']->format('D M j') }}</flux:text>
                            @endif
                        </div>
                        <div>
                            <flux:text size="sm">Cabinets arrive</flux:text>
                            <flux:heading>{{ $summary['cabinets_week'] ?? '—' }}</flux:heading>
                            @if ($summary['cabinets'])
                                <flux:text size="sm">{{ $summary['cabinets']->format('D M j') }} – {{ $summary['cabinets_until']->format('D M j') }}</flux:text>
                                @if ($summary['prep_weeks'])
                                    <flux:text size="sm">{{ $summary['prep_weeks'] }} {{ Str::plural('week', $summary['prep_weeks']) }} of work before{{ $prepWeeks && $prepWeeks !== $summary['prep_weeks'] ? " ({$prepWeeks} needed)" : '' }}</flux:text>
                                @endif
                            @endif
                        </div>
                        <div>
                            <flux:text size="sm">Finish</flux:text>
                            @if ($summary['finish'])
                                <flux:heading>{{ $summary['finish']->format('D, M j, Y') }}</flux:heading>
                                @if ($summary['finish']->greaterThan($this->window['to']))
                                    <flux:button size="xs" variant="ghost" icon-trailing="arrow-right" wire:click="showProject">Continues into {{ $summary['finish']->format('M Y') }}</flux:button>
                                @endif
                            @else
                                <flux:heading>—</flux:heading>
                                @if ($summary['start'])
                                    <flux:text size="sm">Pick a project length.</flux:text>
                                @endif
                            @endif
                        </div>
                        <div>
                            <flux:text size="sm">Length</flux:text>
                            <flux:heading>{{ $length ? \App\Services\Estimates\ProjectDuration::PRESETS[$length]['label'] : '—' }}</flux:heading>
                            @if ($summary['calendar_days'])
                                <flux:text size="sm">{{ $summary['calendar_days'] }} days · {{ $summary['working_days'] }} working days · {{ $summary['weeks'] }} weeks</flux:text>
                            @endif
                            @if ($summary['days_off'])
                                <flux:text size="sm" class="text-red-600 dark:text-red-400">{{ $summary['days_off'] }} {{ Str::plural('weekday', $summary['days_off']) }} off for holidays</flux:text>
                            @endif
                            @if ($summary['limited_days'])
                                <flux:text size="sm" class="text-yellow-700 dark:text-yellow-400">{{ $summary['limited_days'] }} limited {{ Str::plural('day', $summary['limited_days']) }} (thin crew)</flux:text>
                            @endif
                        </div>
                    </div>

                    @if ($summary['start_booked'])
                        <flux:callout variant="warning" icon="exclamation-triangle" heading="The start lands in a booked week">
                            <flux:callout.text>{{ $summary['start_booked'] }}, so two GS jobs would start that week.</flux:callout.text>
                        </flux:callout>
                    @endif

                    @if ($summary['cabinets_before_start'])
                        <flux:callout variant="warning" icon="exclamation-triangle" heading="The cabinets arrive before the project starts">
                            <flux:callout.text>Pick an earlier start, or click Cabinet week to move the delivery.</flux:callout.text>
                        </flux:callout>
                    @endif

                    @if ($summary['cabinets_after_finish'])
                        <flux:callout variant="warning" icon="exclamation-triangle" heading="The cabinets arrive after the finish date">
                            <flux:callout.text>Pick a longer project, or a later cabinet week.</flux:callout.text>
                        </flux:callout>
                    @endif
                @else
                    <div class="rounded-lg bg-zinc-50 p-4 dark:bg-white/5">
                        <flux:text>{{ $length ? 'Click a day on the calendar for the start, or switch to Cabinet week to start from the delivery.' : 'Pick a project length to start; the calendar previews it as you hover.' }}</flux:text>
                    </div>
                @endif

                @if ($this->holidayGroups !== [])
                    <div class="space-y-3" data-timeline-holidays>
                        <div>
                            <flux:heading size="sm">Holidays</flux:heading>
                            <flux:text size="sm">
                                Click a pill to switch it. ✓ The homeowner can have us, so we work (yellow; light indigo for faith). ✗ We stay away and the day comes off the working days (red; indigo for faith).
                                @if ($this->finish) Faded pills fall outside the project. @endif
                            </flux:text>
                        </div>
                        @foreach ($this->holidayGroups as $group => $holidays)
                            <div wire:key="holiday-group-{{ $group }}">
                                <flux:text size="sm" class="mb-1.5 font-medium">{{ $group === 'civic' ? 'US & Illinois · during the project' : \App\Support\FaithHolidays::FAITHS[$group].' · '.$this->window['label'] }}</flux:text>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($holidays as $holiday)
                                        @if ($holiday['weekend'])
                                            <flux:badge
                                                wire:key="holiday-{{ $holiday['key'] }}"
                                                size="sm"
                                                rounded
                                                color="zinc"
                                                @class(['opacity-50' => $this->finish && ! $holiday['in_project']])
                                                :title="trim('On a weekend: we do not work it either way. '.$holiday['note'])"
                                            >{{ $holiday['label'] }}</flux:badge>
                                        @else
                                            <flux:badge
                                                as="button"
                                                wire:key="holiday-{{ $holiday['key'] }}"
                                                wire:click="toggleHoliday('{{ $holiday['key'] }}')"
                                                data-holiday="{{ $holiday['key'] }}"
                                                data-works="{{ $holiday['works'] ? 'yes' : 'no' }}"
                                                size="sm"
                                                rounded
                                                :color="$holiday['faith'] ? 'indigo' : ($holiday['works'] ? 'yellow' : 'red')"
                                                :variant="$holiday['works'] ? null : 'solid'"
                                                :icon="$holiday['works'] ? 'check' : 'x-mark'"
                                                @class(['opacity-50' => $this->finish && ! $holiday['in_project']])
                                                :title="trim(($holiday['works'] ? 'The homeowner can have us. ' : 'We stay away. ').$holiday['note'])"
                                            >{{ $holiday['label'] }}</flux:badge>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </x-island-card>

        {{-- THE MONTHS: inside a card, because x-page.column hides any child without one --}}
        <x-island-card :heading="$this->window['label']">
            <x-slot:actions>
                <flux:button.group>
                    <flux:button size="sm" icon="chevron-left" wire:click="previousMonth" aria-label="A month earlier" />
                    <flux:button size="sm" wire:click="thisMonth">Today</flux:button>
                    <flux:button size="sm" icon="chevron-right" wire:click="nextMonth" aria-label="A month later" />
                </flux:button.group>
            </x-slot:actions>

            {{-- Hover previews the schedule from the day under the mouse, painted like a
                 set project: Alpine marks each day's data-preview and the rules on this
                 wrapper colour it (important, so they beat the day's own tile; holidays
                 and booked days keep theirs, as in the set look). Static x-data (only the
                 preset table); the live choices are read from $wire. --}}
            <div
                @class([
                    '[&_[data-preview=endpoint]]:bg-sky-600! [&_[data-preview=endpoint]]:font-semibold! [&_[data-preview=endpoint]]:text-white! [&_[data-preview=endpoint]]:opacity-100! dark:[&_[data-preview=endpoint]]:bg-sky-500!',
                    '[&_[data-preview=cabinets]]:bg-amber-500! [&_[data-preview=cabinets]]:font-semibold! [&_[data-preview=cabinets]]:text-white!',
                    '[&_[data-preview=best]]:bg-emerald-100! [&_[data-preview=best]]:font-semibold! [&_[data-preview=best]]:text-emerald-800! [&_[data-preview=best]]:inset-ring-2! [&_[data-preview=best]]:inset-ring-emerald-500! dark:[&_[data-preview=best]]:bg-emerald-500/20! dark:[&_[data-preview=best]]:text-emerald-200!',
                    '[&_[data-preview=project]]:bg-sky-100! [&_[data-preview=project]]:font-normal! [&_[data-preview=project]]:text-zinc-800! dark:[&_[data-preview=project]]:bg-sky-500/20! dark:[&_[data-preview=project]]:text-zinc-200!',
                    '[&_[data-preview=clear]]:bg-transparent! [&_[data-preview=clear]]:font-normal! [&_[data-preview=clear]]:text-zinc-800! [&_[data-preview=clear]]:inset-ring-0! dark:[&_[data-preview=clear]]:text-zinc-200!',
                    '[&_[data-preview=clear-weekend]]:bg-transparent! [&_[data-preview=clear-weekend]]:font-normal! [&_[data-preview=clear-weekend]]:text-zinc-400! [&_[data-preview=clear-weekend]]:inset-ring-0! dark:[&_[data-preview=clear-weekend]]:text-zinc-500!',
                ])
                x-data="{
                    presets: @js(collect(\App\Services\Estimates\ProjectDuration::PRESETS)->map(fn ($preset) => [$preset['unit'], $preset['amount']])),
                    demoDays: @js($this->settings->demoDays),
                    today: @js(now()->toDateString()),
                    past: false,
                    fadeBefore: {{ $this->settings->fadeBeforeDays }},
                    fadeAfter: {{ $this->settings->fadeAfterDays }},
                    range: null,
                    booked: null,
                    parse(date) { const [y, m, d] = date.split('-').map(Number); return new Date(Date.UTC(y, m - 1, d)); },
                    iso(day) { return day.toISOString().slice(0, 10); },
                    shift(date, days) { const day = this.parse(date); day.setUTCDate(day.getUTCDate() + days); return this.iso(day); },
                    daysBetween(from, to) { return Math.round((this.parse(to) - this.parse(from)) / 86400000); },
                    demoDay(date) {
                        const isDemo = (day) => this.demoDays.includes(this.parse(day).getUTCDay() || 7);
                        for (let distance = 0; distance <= 3; distance++) {
                            for (const day of [this.shift(date, distance), this.shift(date, -distance)]) {
                                if (isDemo(day) && day >= this.today) { return day; }
                            }
                        }
                        for (let distance = 4; distance < 7; distance++) {
                            if (isDemo(this.shift(date, distance))) { return this.shift(date, distance); }
                        }
                        return date;
                    },
                    weekMonday(date) { return this.shift(date, 1 - this.parse(date).getUTCDay()); },
                    // The weeks needed before a cabinet week: where they begin, and the week holding the best start.
                    plan(cabinetMonday, prepWeeks) {
                        if (! cabinetMonday || ! prepWeeks) { return { prepFrom: null, bestWeek: null }; }
                        const bestWeek = this.shift(cabinetMonday, -7 * prepWeeks);
                        return { prepFrom: this.demoDay(bestWeek > this.today ? bestWeek : this.today), bestWeek };
                    },
                    isBest(date) {
                        const week = this.range?.bestWeek;
                        return !! week && date >= this.today && date >= week && date <= this.shift(week, 5) && this.demoDays.includes(this.parse(date).getUTCDay() || 7);
                    },
                    finishFor(start) {
                        const preset = this.presets[this.$wire.$get('length')];
                        if (! preset) { return null; }
                        const [unit, amount] = preset;
                        if (unit === 'week') { return this.shift(start, amount * 7 - 1); }
                        const day = this.parse(start);
                        const target = new Date(Date.UTC(day.getUTCFullYear(), day.getUTCMonth() + amount, 1));
                        const lastDay = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0)).getUTCDate();
                        target.setUTCDate(Math.min(day.getUTCDate(), lastDay));
                        return this.shift(this.iso(target), -1);
                    },
                    show(date, booked) {
                        this.booked = booked ?? null;
                        this.past = date < this.today;
                        if (this.past) { this.range = null; return; }
                        // No length, no preview: the length comes first.
                        if (! this.presets[this.$wire.$get('length')]) { this.range = null; return; }
                        const prepWeeks = Number(this.$wire.$get('prepWeeks'));
                        if (this.$wire.$get('clickSets') === 'cabinets') {
                            const monday = this.weekMonday(date);
                            const plan = this.plan(monday, prepWeeks);
                            const start = plan.prepFrom;
                            this.range = { start, finish: start ? this.finishFor(start) : null, weekFrom: monday, weekTo: this.shift(monday, 4), ...plan };
                        } else {
                            // A placed cabinet week, and the weeks needed before it, stay; the start follows the mouse.
                            const cabinets = this.$wire.$get('cabinets');
                            const start = this.demoDay(date);
                            const plan = this.plan(cabinets, prepWeeks);
                            this.range = { start, finish: this.finishFor(start), weekFrom: cabinets, weekTo: cabinets ? this.shift(cabinets, 4) : null, prepFrom: plan.prepFrom ?? start, bestWeek: plan.bestWeek };
                        }
                    },
                    previewTone(date, tone, serverFade) {
                        const range = this.range;
                        if (! range) { return null; }
                        if (tone === 'past') { return null; }
                        const keepsOwnTile = ['holiday_off', 'holiday_on', 'faith_off', 'faith_on', 'booked'].includes(tone);
                        if (range.start && (date === range.start || date === range.finish)) { return 'endpoint'; }
                        if (range.weekFrom && date >= range.weekFrom && date <= range.weekTo) { return 'cabinets'; }
                        if (! ['holiday_off', 'faith_off', 'booked'].includes(tone) && this.isBest(date)) { return 'best'; }
                        if (! keepsOwnTile && range.start && range.finish && date > range.start && date < range.finish) { return 'project'; }
                        const weekend = [0, 6].includes(this.parse(date).getUTCDay());
                        // The set project's tiles, and its fade, give way to the hovered schedule.
                        if (['endpoint', 'cabinets', 'best', 'project'].includes(tone) || (serverFade && ! this.fadeStrength(date))) {
                            return weekend ? 'clear-weekend' : 'clear';
                        }
                        return null;
                    },
                    fadeStrength(date) {
                        const range = this.range;
                        if (! range || ! range.start) { return 0; }
                        if (date < range.start) {
                            const days = this.daysBetween(date, range.start);
                            return days <= this.fadeBefore ? (this.fadeBefore + 1 - days) / (this.fadeBefore + 1) : 0;
                        }
                        if (range.finish && date > range.finish) {
                            const days = this.daysBetween(range.finish, date);
                            return days <= this.fadeAfter ? (this.fadeAfter + 1 - days) / (this.fadeAfter + 1) : 0;
                        }
                        return 0;
                    },
                    fadeStyle(date, tone) {
                        if (tone !== 'day' && tone !== 'weekend') { return {}; }
                        const strength = this.fadeStrength(date);
                        if (! strength) { return {}; }
                        return { backgroundColor: 'color-mix(in oklab, var(--color-yellow-300) ' + Math.round(40 * strength) + '%, transparent)' };
                    },
                    say(date) { return this.parse(date).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' }); },
                    label() {
                        if (this.past) { return 'Days before today can\'t be picked.'; }
                        if (! this.presets[this.$wire.$get('length')]) { return 'Pick a project length above to preview the schedule.'; }
                        const range = this.range;
                        if (! range) { return 'Hover a day to preview the schedule; click to set it.'; }
                        if (this.booked && this.$wire.$get('clickSets') !== 'cabinets') { return this.booked + ' · double-click to start here anyway'; }
                        const cabinets = range.weekFrom ? 'Cabinets the week of ' + this.say(range.weekFrom) : '';
                        if (! range.start) { return cabinets + ' · pick the weeks of work before cabinets for the start'; }
                        const weeksBefore = range.weekFrom && range.start < range.weekFrom ? Math.round(this.daysBetween(this.weekMonday(range.start), range.weekFrom) / 7) : 0;
                        const start = (cabinets ? cabinets + (weeksBefore ? ' (' + weeksBefore + (weeksBefore === 1 ? ' week' : ' weeks') + ' of work before)' : '') + ' · start ' : 'Start ') + this.say(range.start);
                        const timing = ! range.bestWeek ? '' : (this.isBest(range.start) ? ' (the best time to start)' : (range.start < range.bestWeek ? ' (earlier than needed)' : ' (later than the best start)'));
                        return start + timing + (range.finish ? ' → finish ' + this.say(range.finish) : ' · pick a project length for the finish');
                    },
                }"
                x-on:mouseleave="range = null; booked = null; past = false"
            >
                <div class="h-5 truncate pt-1 text-sm text-zinc-500 dark:text-zinc-400" x-text="label()" data-timeline-preview></div>

                <div class="grid grid-cols-1 gap-3 pt-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4" data-timeline-from="{{ $from }}">
                    @foreach ($this->months as $month)
                        <div wire:key="month-{{ $month['key'] }}" class="rounded-lg border border-zinc-100 p-3 dark:border-white/5">
                            <flux:heading size="sm" class="mb-2">{{ $month['name'] }}</flux:heading>
                            <div class="grid grid-cols-7 gap-0.5 text-center text-[0.65rem] font-medium uppercase text-zinc-400">
                                @foreach (['S', 'M', 'T', 'W', 'T', 'F', 'S'] as $weekday)
                                    <div>{{ $weekday }}</div>
                                @endforeach
                            </div>
                            @foreach ($month['weeks'] as $week)
                                <div class="mt-0.5 grid grid-cols-7 gap-0.5">
                                    @foreach ($week as $cell)
                                        @if ($cell)
                                            {{-- The fade goes through --fade-bg, not an inline background: the hover
                                                 preview sets (and later restores) background-color and would wipe it. --}}
                                            <button
                                                type="button"
                                                @if ($cell['past'] || $cell['weekend'])
                                                    aria-disabled="true"
                                                    @if ($cell['past']) data-past @endif
                                                @else
                                                    wire:click="pickDate('{{ $cell['date'] }}')"
                                                    @if ($cell['booked'])
                                                        wire:dblclick="pickDate('{{ $cell['date'] }}', true)"
                                                        data-booked="{{ $cell['booked'] }}"
                                                    @endif
                                                @endif
                                                @unless ($cell['weekend'])
                                                    x-on:mouseenter="show('{{ $cell['date'] }}', $el.dataset.booked)"
                                                @endunless
                                                x-bind:data-preview="previewTone('{{ $cell['date'] }}', $el.dataset.tone, $el.dataset.fade)"
                                                x-bind:style="fadeStyle('{{ $cell['date'] }}', $el.dataset.tone)"
                                                data-date="{{ $cell['date'] }}"
                                                data-tone="{{ $cell['tone'] }}"
                                                @if ($cell['limited']) data-limited @endif
                                                @if ($cell['fade']) data-fade="{{ $cell['fade'] }}" style="--fade-bg: color-mix(in oklab, var(--color-yellow-300) {{ round(40 * $cell['fade']) }}%, transparent)" @endif
                                                @if ($cell['title']) title="{{ $cell['title'] }}" @endif
                                                @class([
                                                    'flex h-8 items-center justify-center rounded-md text-xs tabular-nums transition',
                                                    'hover:ring-1 hover:ring-zinc-400' => ! $cell['past'] && ! $cell['weekend'],
                                                    'cursor-default opacity-50' => $cell['weekend'] && $cell['tone'] !== 'endpoint',
                                                    'cursor-default text-zinc-300 dark:text-zinc-600' => $cell['tone'] === 'past',
                                                    'bg-sky-600 font-semibold text-white dark:bg-sky-500' => $cell['tone'] === 'endpoint',
                                                    'bg-amber-500 font-semibold text-white' => $cell['tone'] === 'cabinets',
                                                    'bg-red-500 font-semibold text-white' => $cell['tone'] === 'holiday_off',
                                                    'bg-yellow-200 font-semibold text-yellow-900 dark:bg-yellow-400/25 dark:text-yellow-200' => $cell['tone'] === 'holiday_on',
                                                    'bg-indigo-500 font-semibold text-white' => $cell['tone'] === 'faith_off',
                                                    'bg-indigo-100 font-semibold text-indigo-700 dark:bg-indigo-500/25 dark:text-indigo-300' => $cell['tone'] === 'faith_on',
                                                    'bg-emerald-100 font-semibold text-emerald-800 inset-ring-2 inset-ring-emerald-500 dark:bg-emerald-500/20 dark:text-emerald-200' => $cell['tone'] === 'best',
                                                    'bg-sky-100 text-zinc-800 dark:bg-sky-500/20 dark:text-zinc-200' => $cell['tone'] === 'project',
                                                    'bg-zinc-100 text-zinc-400 line-through dark:bg-white/5 dark:text-zinc-600' => $cell['tone'] === 'booked',
                                                    'opacity-50' => $cell['booked'],
                                                    'text-zinc-400 dark:text-zinc-500' => $cell['tone'] === 'weekend',
                                                    'text-zinc-800 dark:text-zinc-200' => $cell['tone'] === 'day',
                                                    'bg-(--fade-bg)' => $cell['fade'],
                                                    'border-2 border-dotted border-yellow-400 dark:border-yellow-500' => $cell['limited'],
                                                    'opacity-50' => $cell['past'] && $cell['limited'],
                                                    'ring-1 ring-zinc-900 dark:ring-white' => $cell['today'],
                                                ])
                                            >{{ $cell['day'] }}</button>
                                        @else
                                            <div class="h-8"></div>
                                        @endif
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- LEGEND --}}
            @php
                $swatch = 'size-3 shrink-0 rounded';
            @endphp
            <div class="space-y-2 pt-3 pb-2 text-xs text-zinc-500 dark:text-zinc-400">
                <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
                    <span class="w-14 font-medium text-zinc-700 dark:text-zinc-300">Project</span>
                    <span class="inline-flex items-center gap-1.5"><span class="{{ $swatch }} bg-sky-600"></span> Start / finish</span>
                    <span class="inline-flex items-center gap-1.5"><span class="{{ $swatch }} bg-sky-100 dark:bg-sky-500/20"></span> Project days</span>
                    <span class="inline-flex items-center gap-1.5"><span class="{{ $swatch }} bg-amber-500"></span> Cabinet week</span>
                    <span class="inline-flex items-center gap-1.5"><span class="{{ $swatch }} bg-emerald-100 inset-ring-2 inset-ring-emerald-500 dark:bg-emerald-500/20"></span> Best time to start</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-3 w-6 shrink-0 rounded bg-linear-to-r from-transparent to-yellow-300/40"></span> Before / after</span>
                </div>
                <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
                    <span class="w-14 font-medium text-zinc-700 dark:text-zinc-300">Days</span>
                    <span class="inline-flex items-center gap-1.5"><span class="{{ $swatch }} bg-red-500"></span><span class="{{ $swatch }} bg-yellow-200 dark:bg-yellow-400/25"></span> Holiday: off / we work</span>
                    <span class="inline-flex items-center gap-1.5"><span class="{{ $swatch }} bg-indigo-500"></span><span class="{{ $swatch }} bg-indigo-100 dark:bg-indigo-500/25"></span> Faith holiday: off / we work</span>
                    <span class="inline-flex items-center gap-1.5"><span class="{{ $swatch }} border-2 border-dotted border-yellow-400 dark:border-yellow-500"></span> Limited days</span>
                    <span class="inline-flex items-center gap-1.5"><span class="{{ $swatch }} bg-zinc-200 opacity-70 dark:bg-white/10"></span> Booked (double-click to start anyway)</span>
                    <span class="inline-flex items-center gap-1.5"><span class="{{ $swatch }} ring-1 ring-zinc-900 dark:ring-white"></span> Today</span>
                </div>
            </div>
        </x-island-card>
    </x-page.column>
</x-page.shell>
