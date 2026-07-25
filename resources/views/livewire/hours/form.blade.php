<form wire:submit="{{$view_text['form_submit']}}">
    <style>
        [wire\:loading][wire\:target^="incrementHours"],
        [wire\:loading][wire\:target^="decrementHours"] {
            display: none !important;
        }
        td[data-date-variant='hours'] button {
            outline: 1px solid oklch(0.585 0.233 277.117 / 0.35);
            outline-offset: -2px;
            border-radius: 0.5rem;
        }
        td[data-date-variant='hours'][data-selected] button {
            outline: none;
        }
        ui-calendar.hours-create-calendar {
            display: inline-block;
            width: max-content;
            max-width: 100%;
        }
    </style>
	<div class="grid max-w-xl grid-cols-4 gap-4 xl:relative lg:max-w-5xl sm:px-6">
		{{-- FLOAT CALENDAR --}}
		<div class="col-span-4 space-y-4 lg:col-span-2 lg:h-32">
            <x-island-card heading="Daily Hours for {{auth()->user()->first_name}}" subheading="Pick Date to add or edit Daily Hours for {{auth()->user()->first_name}}" :separator="true">
                <x-slot:actions>
                    <flux:button
                        wire:navigate.hover
                        href="{{route('timesheets.index')}}"
                        size="sm"
                        >
                        Confirm Timesheets
                    </flux:button>
                </x-slot:actions>
                <div
                    class="flex justify-center"
                    x-data="{ datesWithHours: @js($this->datesWithHours) }"
                    x-init="(async () => {
                        await customElements.whenDefined('ui-calendar');
                        const cal = $el.querySelector('ui-calendar');
                        if (!cal) return;
                        await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
                        if (cal.appendMetadata) cal.appendMetadata(datesWithHours);
                    })()"
                    @update-hours-metadata.window="
                        datesWithHours = $event.detail.metadata;
                        $nextTick(() => {
                            const cal = $el.querySelector('ui-calendar');
                            if (cal && cal.resetMetadata) cal.resetMetadata(datesWithHours);
                        });
                    "
                >
                    <flux:calendar
                        class="hours-create-calendar"
                        wire:model.live="selected_date"
                        with-today
                        min="{{$this->minDate}}"
                        max="{{ browser_today()->format('Y-m-d') }}"
                        start-day="1"
                        unavailable="{{$this->days}}"
                    />
                </div>

                <flux:separator variant="subtle" />
                <div class="space-y-2 mt-2">
                    @if($this->selected_date)
                        <flux:button class="w-full cursor-default"><b>{{$this->selected_date->format('D M jS, Y')}}</b></flux:button>
                    @endif
                    <flux:button
                        class="w-full cursor-default"
                        x-data="{ count: @js($this->hours_count) }"
                        @hours-count-updated.window="count = $event.detail.count"
                    >Hours | <b x-text="count"></b></flux:button>
                    <flux:button type="submit" variant="primary" class="w-full">{{$view_text['button_text']}}</flux:button>
                </div>

                <flux:error name="check_total_min" />
            </x-island-card>
		</div>

        <div class="col-span-4 space-y-2 lg:col-span-2">
            @island(name: 'project-hours', always: true)
            <div class="space-y-2">
            @if($this->selected_date)
                <x-island-card heading="Project Hours" subheading="Add hours worked for each project on {{ $this->selected_date->format('M jS, Y') }}" :separator="true">

                {{-- PROJECT HOUR AMOUNT --}}
                @foreach ($this->projects as $index => $project)
                    <flux:field wire:key="project-{{ $project->id }}">
                        <div class="grid grid-cols-2 items-center gap-2">
                            <div>
                                <flux:label><a wire:navigate.hover href="{{route('projects.show', $project->id)}}" target="_blank">{{ $project->short_address }}</a></flux:label>
                                <flux:description><i>{{$project->project_name}}</i></flux:description>
                            </div>
                            <div>
                                <flux:button.group class="w-full">
                                    <flux:button 
                                        wire:click.preserve-scroll="decrementHours({{ $index }})" 
                                        wire:loading.attr="disabled"
                                        wire:target="decrementHours({{ $index }})"
                                        icon="minus" 
                                        variant="outline" 
                                        square 
                                    />
                                    <flux:input 
                                        wire:model.live.debounce.150ms="form.projects.{{ $index }}.hours"
                                        type="number" 
                                        inputmode="decimal" 
                                        step="0.25" 
                                        min="0" 
                                        max="16"
                                        placeholder="Hours"
                                        class="flex-1 self-center text-center"
                                    />
                                    <flux:button 
                                        wire:click.preserve-scroll="incrementHours({{ $index }})" 
                                        wire:loading.attr="disabled"
                                        wire:target="incrementHours({{ $index }})"
                                        icon="plus" 
                                        variant="outline" 
                                        square 
                                    />
                                </flux:button.group>
                                @if(!empty($this->day_project_tasks[$index]))
                                    @foreach($this->day_project_tasks[$index] as $task)
                                        <flux:description><i class="text-indigo-600">{{$task['title']}}</i></flux:description>
                                    @endforeach
                                @endif
                            </div>
                        </div>
                    </flux:field>

                    @if(!$loop->last)
                        <flux:separator variant="subtle" />
                    @endif
                @endforeach
                </x-island-card>

            <x-island-card heading="Different Project">
                <flux:input.group>
                    <x-forms.project-select
                        :projects="$this->other_projects"
                        model="new_project_id"
                        :show-label="false"
                        :show-status-badge="true"
                        in-input-group
                    />

                    <flux:button variant="primary" wire:click="add_project" icon="plus-circle" wire:loading.attr="disabled" wire:target="add_project">Add</flux:button>
                </flux:input.group>
            </x-island-card>
            @endif
            </div>
            @endisland
		</div>
	</div>
</form>
