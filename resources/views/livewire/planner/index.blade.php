<flux:card class="!p-0 overflow-x-scroll !h-[48rem] !bg-gray-100">
    <div class="sticky top-0 flex flex-none">
        <div class="divide-x text-sm leading-6 text-gray-500 grid grid-flow-col auto-cols-max">
            {{-- First. leftmost table column on the first row.  --}}
            <div class="col-end-1 w-14 sticky left-0 bg-white shadow-gray-100 shadow-xl"></div>

            @foreach($projects as $project)
                <div class="w-64 p-2">
                    <div class="!p-2 flex justify-between hover:bg-gray-100 shadow-xl shadow-gray-100 bg-white rounded-md border border-solid border-gray-300">
                        <div>
                            <span class="font-semibold text-gray-800">
                                <a href="{{route('projects.show', $project->id)}}" target="_blank">{{ Str::limit($project->address, 18) }}</a>
                            </span>
                            <br>
                            <span class="font-normal italic text-gray-600">
                                {{ Str::limit($project->project_name, 18) }}
                            </span>

                            {{-- NO DATE/ NOT SCHEDULE --}}
                            {{-- ACCORDIAN HERE --}}
                            <flux:accordion transition>
                                <flux:accordion.item>
                                    <flux:accordion.heading>No Date Tasks</flux:accordion.heading>

                                    <flux:accordion.content>
                                        <flux:card
                                            x-sort="$wire.sort($key, $position, {{$project->id}}, 0)"
                                            x-sort:group="tasks"
                                            x-sort:config="{ filter: '.filtered' }"
                                            @class([
                                                '!p-0 space-y-2 !bg-none',
                                            ])
                                            >
                                            @foreach($project->tasks()->whereNull('start_date')->whereNull('end_date')->get() as $task)
                                                @include('livewire.planner._task_card')
                                            @endforeach
                                        </flux:card>
                                    </flux:accordion.content>
                                </flux:accordion.item>
                            </flux:accordion>
                        </div>

                        <flux:button
                            wire:click="$dispatchTo('tasks.task-create', 'addTask', { project_id: {{$project->id}} })"
                            icon="plus"
                        />
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- HORIZONTAL DATE LINES HERE --}}
    <div class="flex flex-auto bg-gray-100">
        <div class="sticky left-0 w-14 flex-none shadow-gray-100 shadow-xl bg-white"></div>

        <div>
            @foreach($days as $day_index => $day)
                @if($day['database_date'] !== NULL)
                    <div
                        @class([
                            'sticky left-0 -ml-14 w-14 pr-2 text-right text-xs text-gray-800',
                            '!text-gray-400' => $day['is_weekend'] && !$day['is_today'] ? true : false,
                            '!text-sky-600' => $day['is_today'] ? true : false,
                        ])
                        >
                        <span class="font-semibold">{{strtok($day['formatted_date'], ',')}}</span>
                        <br>
                        <span class="italic">
                            {{substr($day['formatted_date'], strpos($day['formatted_date'], ', ') + 2)}}
                        </span>
                    </div>

                    <div
                        @class([
                            'text-sm text-gray-500 grid grid-flow-col -mt-8 divide-x',
                            'bg-gray-50' => $day['is_weekend']  ? true : false,
                            'bg-sky-100' => $day['is_today'] ? true : false,
                        ])
                        >
                        @foreach($this->projects as $project)
                            {{-- border-2 border-black --}}
                            {{-- divide-y-4 divide-black --}}
                            <div class="w-64 p-2 border-b-2">
                                <flux:card
                                    x-sort="$wire.sort($key, $position, {{$project->id}}, {{$day_index}})"
                                    x-sort:group="tasks"
                                    x-sort:config="{ filter: '.filtered' }"
                                    @class([
                                        '!min-h-4 !bg-gray-100 border-none space-y-1 !p-0',
                                        // OR $day['database_date'] === NULL
                                        '!bg-gray-50' => $day['is_weekend'] ? true : false,
                                        '!bg-sky-100' => $day['is_today'] ? true : false,
                                    ])
                                    >
                                    @foreach($project->tasks()->whereNotNull('start_date')->whereNotNull('end_date')->get() as $task)
                                        @if(\Carbon\Carbon::parse($day['database_date'])->between($task->start_date, $task->end_date) && $day['database_date'] !== NULL)
                                            @if(isset($task->options->include_weekend_days) && $day['is_weekend'])
                                                @if(isset($task->options->include_weekend_days->saturday) && $task->options->include_weekend_days->saturday === true && $day['is_saturday'] === true)
                                                    @include('livewire.planner._task_card')
                                                @elseif(isset($task->options->include_weekend_days->sunday) && $task->options->include_weekend_days->sunday === true && $day['is_sunday'] === true)
                                                    @include('livewire.planner._task_card')
                                                @endif
                                            @else
                                                @include('livewire.planner._task_card')
                                            @endif
                                        @endif
                                    @endforeach
                                </flux:card>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endforeach
        </div>
    </div>

    <livewire:tasks.task-create :employees="$employees" :projects="$projects" :vendors="$vendors" />
</flux:card>
