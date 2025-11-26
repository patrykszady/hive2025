<form wire:submit="{{$view_text['form_submit']}}" x-data="{ 
    // Calculate browser's current LOCAL date (not UTC)
    getBrowserDate() {
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    },
    browserDate: null,
    ready: false,
    init() {
        this.browserDate = this.getBrowserDate();
        // Send browser's LOCAL date to Livewire immediately on mount
        this.$wire.setBrowserDate(this.browserDate).then(() => {
            this.ready = true;
        });
    }
}" x-init="init()">
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

                <div x-cloak x-show="!ready"></div>

                <div x-show="ready" x-cloak>
                    <flux:calendar
                        wire:model.live="selected_date"
                        wire:loading.class.delay="opacity-50"
                        :max="$selected_date ? $selected_date->format('Y-m-d') : ''"
                        start-day="1"
                        unavailable="{{$this->days}}"
                    />
                </div>

                <flux:separator variant="subtle" />

                <div class="space-y-2 mt-2" x-show="ready" x-cloak>
                    @if($this->selected_date)
                        <flux:button class="w-full cursor-default"><b>{{$this->selected_date->format('D M jS, Y')}}</b></flux:button>
                    @endif
                    <flux:button class="w-full cursor-default">Hours | <b>{{$this->hours_count}}</b></flux:button>
                    <flux:button type="submit" variant="primary" class="w-full">{{$view_text['button_text']}}</flux:button>
                </div>

                <flux:error name="check_total_min" />
            </flux:card>
		</div>

		<div class="col-span-4 space-y-2 lg:col-span-2" x-show="ready" x-cloak>
            @if($selected_date)
                <div wire:loading.class.delay="opacity-50">
                    <flux:card class="space-y-2">
                        <flux:heading size="lg">Projects</flux:heading>
                        <flux:separator variant="subtle" />

                {{-- PROJECT HOUR AMOUNT --}}
                @foreach ($projects as $index => $project)
                    <flux:field>
                        {{-- label_text_color_custom="{{ !empty($day_project_tasks[$index]) ? 'text-indigo-600' : NULL}}" --}}
                        <div class="grid gap-2 grid-cols-2">
                            <div>
                                <flux:label><a href="{{route('projects.show', $project->id)}}" target="_blank">{{$project->address}}</a></flux:label>
                                <flux:description><i>{{$project->project_name}}</i></flux:description>
                            </div>
                            <div>
                                <flux:input.group>
                                    <flux:input.group.prefix>Hours</flux:input.group.prefix>
                                    <flux:input wire:model.blur="form.projects.{{$index}}.hours" type="number" inputmode="decimal" step="0.25" min="0.25" />
                                </flux:input.group>
                                @if(!empty($day_project_tasks[$index]))
                                    @foreach($day_project_tasks[$index] as $task)
                                        <flux:description><i class="text-sky-600">{{$task['title']}}</i></flux:description>
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
            </div>

            <flux:card>
                <flux:heading size="lg">Different Project</flux:heading>
                <flux:input.group>
                    <flux:select wire:model.live="new_project_id" variant="listbox" searchable placeholder="Choose project...">
                        <x-slot name="search">
                            <flux:select.search placeholder="Search..." />
                        </x-slot>

                        @foreach($this->other_projects as $project)
                            <flux:select.option value="{{$project->id}}"><div>{{$project->address}} <br> <i>{{$project->project_name}}</i></div></flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:button variant="primary" wire:click="add_project" icon="plus-circle">Add</flux:button>
                </flux:input.group>
            </flux:card>
            @endif
		</div>
	</div>
</form>
