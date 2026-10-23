<x-page.shell width="flat" :cols="1" :breadcrumbs="[['label' => 'Project timeline', 'href' => route('project-timeline')], ['label' => 'Settings']]">
    <x-page.column :span="1">
        <x-island-card heading="Demo days" subheading="The days a project can start. A click on any other day moves to the nearest one.">
            <div class="pt-1 pb-3">
                <flux:checkbox.group wire:model="demoDays" variant="pills">
                    @foreach (\App\Services\Estimates\TimelineSettings::WEEKDAYS as $day => $name)
                        <flux:checkbox :value="(string) $day" :label="$name" />
                    @endforeach
                </flux:checkbox.group>
                <flux:error name="demoDays" />
            </div>
        </x-island-card>

        <x-island-card heading="Holidays" subheading="Stay away: a red day, off the working days. Work: a yellow day, like Pulaski Day. Hide: left off the calendar.">
            <div class="space-y-1 pt-1 pb-3" data-settings-holidays>
                @foreach (\App\Services\Estimates\TimelineSettings::civicHolidays() as $slug => $holiday)
                    <div wire:key="holiday-{{ $slug }}" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-1.5">
                        <div class="min-w-0">
                            <flux:heading>{{ $holiday['name'] }}</flux:heading>
                            @if ($holiday['note'])
                                <flux:text size="sm">{{ $holiday['note'] }}</flux:text>
                            @endif
                        </div>
                        <flux:radio.group wire:model="holidays.{{ $slug }}" variant="segmented" size="sm">
                            <flux:radio value="off" label="Stay away" />
                            <flux:radio value="work" label="Work" />
                            <flux:radio value="hide" label="Hide" />
                        </flux:radio.group>
                    </div>
                @endforeach
            </div>
        </x-island-card>

        <x-island-card heading="Limited days" subheading="Days the crew is thin. They show a dotted yellow border and still count as working days.">
            <div class="space-y-4 pt-1 pb-3">
                <flux:switch wire:model="thanksgivingWeek" label="Thanksgiving week" description="Monday to Sunday of the week." />
                <flux:switch wire:model="christmasWeek" label="Christmas Eve to New Year's Day" />
                <flux:switch wire:model="dayBeforeChristmasEve" label="The day before Christmas Eve" description="Starts the Christmas run on Dec 23." />
                <flux:switch wire:model="bridgeDays" label="Make it a long weekend" description="A lone weekday between those days and a weekend joins them: Friday Jan 2 after a Thursday New Year's, or the Monday before a run that starts on a Tuesday." />
                <flux:switch wire:model="easterDays" label="Easter" description="Holy Thursday, Good Friday and Easter Monday." />
                <flux:switch wire:model="orthodoxDays" label="Ukrainian Orthodox holidays" description="Christmas Eve and Christmas (Jan 6–7), Theophany (Jan 19), and Good Friday, Easter Monday and Trinity Monday from Orthodox Easter." />
            </div>
        </x-island-card>

        <x-island-card heading="Booked weeks" subheading="Our own jobs grey out the days after they start. A click will not start a project there; a double-click still does.">
            <div class="space-y-4 pt-1 pb-3">
                <flux:switch wire:model.live="bookedWeeks" label="Grey out the days after each job starts" />
                @if ($bookedWeeks)
                    <div class="grid items-end gap-4 sm:grid-cols-2">
                        <flux:input type="number" min="1" :max="\App\Services\Estimates\TimelineSettings::MAX_BOOKED_DAYS" wire:model="bookedDays" label="Days a job's start holds" description="Its start day counts: 7 is the start and the six after." />
                        <flux:checkbox.group wire:model="bookedStatuses" label="Jobs that hold them" variant="pills">
                            @foreach (\App\Services\Estimates\TimelineSettings::BOOKABLE_STATUSES as $code => $status)
                                <flux:checkbox :value="(string) $code" :label="$status" />
                            @endforeach
                        </flux:checkbox.group>
                    </div>
                @endif
            </div>
        </x-island-card>

        <x-island-card heading="Calendar fade" subheading="Days that fade in before a project's start and out after its finish, on the calendar and in the hover preview.">
            <div class="grid items-end gap-4 pt-1 pb-3 sm:grid-cols-2">
                <flux:input type="number" min="0" :max="\App\Services\Estimates\TimelineSettings::MAX_FADE_BEFORE_DAYS" wire:model="fadeBeforeDays" label="Days before the start" />
                <flux:input type="number" min="0" :max="\App\Services\Estimates\TimelineSettings::MAX_FADE_AFTER_DAYS" wire:model="fadeAfterDays" label="Days after the finish" />
            </div>
        </x-island-card>

        <x-island-card>
            <div class="flex flex-wrap items-center justify-end gap-2 py-2">
                <flux:button variant="ghost" wire:click="useDefaults">Reset to defaults</flux:button>
                <flux:button variant="primary" wire:click="save">Save</flux:button>
            </div>
        </x-island-card>
    </x-page.column>
</x-page.shell>
