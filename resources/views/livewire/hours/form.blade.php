<form wire:submit="{{$view_text['form_submit']}}">
    <style>
        [wire\:loading][wire\:target^="incrementHours"],
        [wire\:loading][wire\:target^="decrementHours"] {
            display: none !important;
        }
    </style>
	<div class="grid max-w-xl grid-cols-4 gap-4 xl:relative lg:max-w-5xl sm:px-6">
		{{-- FLOAT CALENDAR --}}
		<div class="col-span-4 space-y-4 lg:col-span-2 lg:h-32">
            <flux:card>
                <div class="flex justify-between">
                    <flux:heading size="lg">Daily Hours for {{auth()->user()->first_name}}</flux:heading>
                    <flux:button
                        wire:navigate.hover
                        href="{{route('timesheets.index')}}"
                        size="sm"
                        >
                        Confirm Timesheets
                    </flux:button>
                </div>
                <flux:subheading><i>Pick Date to add or edit Daily Hours for {{auth()->user()->first_name}}</i></flux:subheading>

                <flux:separator variant="subtle" />
                <div>
                    <flux:calendar
                        wire:model.live="selected_date"
                        wire:loading.class.delay="opacity-50"
                        min="{{$this->minDate}}"
                        ::max="$store.timezone.today"
                        start-day="1"
                        unavailable="{{$this->days}}"
                    />
                </div>

                <flux:separator variant="subtle" />
                <div class="space-y-2 mt-2">
                    @if($this->selected_date)
                        <flux:button class="w-full cursor-default"><b>{{$this->selected_date->format('D M jS, Y')}}</b></flux:button>
                    @endif
                    <flux:button class="w-full cursor-default">Hours | <b>{{$this->hours_count}}</b></flux:button>
                    <flux:button type="submit" variant="primary" class="w-full">{{$view_text['button_text']}}</flux:button>
                </div>

                <flux:error name="check_total_min" />
            </flux:card>
		</div>

        <div class="col-span-4 space-y-2 lg:col-span-2">
            <div class="space-y-2">
            @if($selected_date)
                <flux:card class="space-y-2">
                    <flux:heading size="lg">Project Hours</flux:heading>
                    <flux:subheading><i>Add hours worked for each project on {{ $selected_date->format('M jS, Y') }}</i></flux:subheading>
                    <flux:separator variant="subtle" />

                {{-- PROJECT HOUR AMOUNT --}}
                @foreach ($projects as $index => $project)
                    <flux:field wire:key="project-{{ $project->id }}">
                        {{-- label_text_color_custom="{{ !empty($day_project_tasks[$index]) ? 'text-indigo-600' : NULL}}" --}}
                        <div class="grid gap-2 grid-cols-2">
                            <div>
                                <flux:label><a href="{{route('projects.show', $project->id)}}" target="_blank">{{ $project->short_address }}</a></flux:label>
                                <flux:description><i>{{$project->project_name}}</i></flux:description>
                            </div>
                            <div>
                                <flux:button.group class="w-full">
                                    <flux:button 
                                        wire:click="decrementHours({{ $index }})" 
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
                                        step="0.5" 
                                        min="0" 
                                        max="16"
                                        placeholder="Hours"
                                        class="flex-1 text-center"
                                    />
                                    <flux:button 
                                        wire:click="incrementHours({{ $index }})" 
                                        wire:loading.attr="disabled"
                                        wire:target="incrementHours({{ $index }})"
                                        icon="plus" 
                                        variant="outline" 
                                        square 
                                    />
                                </flux:button.group>
                                @if(!empty($day_project_tasks[$index]))
                                    @foreach($day_project_tasks[$index] as $task)
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
                </flux:card>

            <flux:card>
                <flux:heading size="lg">Different Project</flux:heading>
                <flux:input.group>
                    <flux:select wire:model.live="new_project_id" variant="listbox" searchable placeholder="Choose project...">
                        <x-slot name="search">
                            <flux:select.search placeholder="Search..." />
                        </x-slot>

                        @foreach($this->other_projects as $project)
                            <flux:select.option value="{{$project->id}}"><div>{{ $project->short_address }} <br> <i class="font-normal">{{$project->project_name}}</i></div></flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:button variant="primary" wire:click="add_project" icon="plus-circle" wire:loading.attr="disabled" wire:target="add_project">Add</flux:button>
                </flux:input.group>
            </flux:card>
            </div>
            @endif
            </div>
		</div>
	</div>
</form>
