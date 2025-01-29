<div>
    @foreach($projects as $project_index => $project)
        {{-- <x-cards class="px-4 {{!$project->no_date_tasks->isEmpty() || !$project->tasks->isEmpty() ? 'pb-4' : ''}} mb-1 sm:px-2 lg:max-w-5xl lg:px-4"> --}}
        <x-cards class="{{!$project->no_date_tasks->isEmpty() || !$project->tasks->isEmpty() ? 'pb-4' : ''}} mb-1 lg:max-w-5xl">
            <x-cards.heading class="px-1 py-1">
                <x-slot name="left">
                    <div>
                        <a href="{{route('projects.show', $project->id)}}" target="_blank">
                            <span class="font-bold text-base">{{$project->address}}</span>
                            <br class="md:hidden">
                            <span class="text-sm md:text-base">{{$project->project_name}}</span>
                        </a>
                        <div class="inline-flex">
                            <span
                                class="px-2 text-xs font-semibold rounded-full bg-indigo-100 text-indigo-800 float-right ml-2"
                                >
                                {{ $project->last_status->title }}
                            </span>
                        </div>
                    </div>
                </x-slot>
                <x-slot name="right">
                    <x-cards.button
                        type="button"
                        wire:click="$dispatchTo('tasks.task-create', 'addTask', { project_id: {{$project->id}} })"
                        button_color="white"
                        >
                        Add Task
                    </x-cards.button>
                </x-slot>
            </x-cards.heading>

            <x-cards.body>
                @if(!$project->no_date_tasks->isEmpty())
                    <div class="noDateTasks grid grid-cols-2 sm:grid-cols-5 gap-1 m-1 p-1">
                        {{-- <div class="col-md-9 bg-red-300">
                            <div class="trash ui-droppable" id="trash">
                            </div>
                        </div> --}}
                        @foreach($project->no_date_tasks as $task)
                            <div
                                class="grid-stack-item cursor-pointer"
                                wire:click="$dispatchTo('tasks.task-create', 'editTask', { task: {{$task->id}} })"
                                gs-w="1" gs-h="1" gs-x="1" gs-id="{{$task->id}}"
                                >
                                <div class="pl-1 grid-stack-item-content border border-solid border-gray-300 h-12 hover:bg-gray-100 font-bold rounded-md text-clip overflow-hidden">
                                    @can('update', $task)
                                        <span
                                            class="{{ $task->type == 'Milestone' ? 'text-green-600' : '' }}  {{ $task->type == 'Material' ? 'text-yellow-600' : '' }} {{ $task->type == 'Task' ? 'text-indigo-600' : '' }} {{$task->direction == 'right' ? 'float-right' : ''}}"
                                            >
                                            {{$task->title}}
                                        </span>

                                        @if($task->vendor)
                                            <br>
                                            <span class="text-sm font-medium text-gray-600 {{$task->direction == 'right' ? 'float-right' : ''}}">{{$task->vendor->name, 15}}</span>
                                        @elseif($task->user)
                                            <br>
                                            <span class="text-sm font-medium text-gray-600 {{$task->direction == 'right' ? 'float-right' : ''}}">{{$task->user->first_name, 15}}</span>
                                        @endif
                                    @endcan
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <hr>
                @endif

                <div
                    class="overflow-x-auto"
                    x-bind="scrollSync"
                    >
                    <div class="max-w-5xl" style="width: 1400px;">
                        {{-- @if(!$project->no_date_tasks->isEmpty() || !$project->tasks->isEmpty()) --}}
                            <div class="grid grid-cols-7 gap-1 divide-x divide-solid divide-gray-300">
                                @foreach($days as $day_index => $day)
                                    <h5
                                        wire:click="$dispatchTo('tasks.task-create', 'addTask', { project_id: {{$project->id}}, date: '{{ $day['database_date'] }}' })"
                                        class="pl-1 cursor-pointer hover:bg-gray-100 {{$day['is_today'] == TRUE ? 'text-indigo-600' : ''}}"
                                        >
                                        {{ $day['formatted_date'] }}
                                    </h5>
                                @endforeach
                            </div>
                            <hr>
                        {{-- @endif --}}

                        <div
                            class="grid-stack {{!$project->no_date_tasks->isEmpty() && $project->tasks->isEmpty() ? ' pb-12' : ''}}"
                            x-data="{
                                init() {
                                    let grids = GridStack.initAll({
                                        column: 7,
                                        cellHeight: '60px',
                                        cellWidth: '100px',
                                        float: false,
                                        resizable: {
                                            handles: 'w, e'
                                        },
                                        margin: 2,
                                        acceptWidgets: true,
                                        {{-- removable: '.trash', // drag-out delete class --}}
                                    });

                                    grids[{{$project_index}}].on('added change', function(event, items) {
                                        let newItems = [];
                                        items.forEach ((el) => {
                                            newItems.push({_id: el._id, x: el.x, y: el.y, w: el.w, task_id: el.id});
                                        });

                                        $wire.taskMoved(newItems);
                                    });
                                    GridStack.setupDragIn('.noDateTasks .grid-stack-item', { appendTo: 'body' });
                                }
                            }"
                            >
                            {{-- 5/20/2024 if Satruday or Sunday change bg-color --}}
                            {{-- <div class="flex h-full divide-x-2">
                                <div class="bg-transparent" style="width: 71.428571%;"></div>
                                <div class="bg-gray-500" style="width: 28.571429%;"></div>
                            </div> --}}

                            @if(!$project->no_date_tasks->isEmpty() || !$project->tasks->isEmpty())
                            @foreach($days as $day_index => $day)
                                @foreach($project->tasks->where('date', $day['database_date']) as $task)
                                    @php
                                        $gs_w = $task->direction == 'left' ? (7 - $day_index < $task->duration ? 7 - $day_index : $task->duration) : $day_index + 1;
                                        $gs_x = $task->direction == 'left' ? $day_index : 0;
                                    @endphp
                                    <div
                                        {{-- bg-red-300 --}}
                                        class="grid-stack-item"
                                        gs-id="{{$task->id}}"
                                        gs-x="{{$gs_x}}"
                                        gs-y="{{$task->order}}"
                                        gs-w="{{$gs_w}}"
                                        gs-locked="true"
                                        gs-no-move="@cannot('update', $task) true @endcannot"
                                        gs-no-resize="@cannot('update', $task) true @endcannot"
                                        >
                                        <div
                                            @can('update', $task)
                                                wire:click="$dispatchTo('tasks.task-create', 'editTask', { task: {{$task->id}} })"
                                            @endcan
                                            class="p-1 border-{{$task->direction == 'right' ? 'l' : 'l'}}-4 grid-stack-item-content bg-gray-200 bg-opacity-50 @cannot('update', $task) opacity-50 cursor-none @else cursor-pointer hover:bg-gray-200 @endcannot
                                                {{-- 5/20/2024 if Satruday or Sunday change bg-color --}}
                                                {{-- {{in_array($day_index, [5, 6]) ? 'bg-gray-700' : 'bg-gray-100'}} --}}


                                                {{-- @if(in_array($day_index, [5, 6]))
                                                bg-gray-700
                                                @else
                                                bg-gray-100
                                                @endif --}}
                                                {{ $task->type == 'Milestone' ? 'border-green-600' : '' }}  {{ $task->type == 'Material' ? 'border-yellow-600' : '' }} {{ $task->type == 'Task' ? 'border-indigo-600' : '' }}
                                            "
                                            >

                                            @if($task->direction == 'left' && 7 - $day_index < $task->duration)
                                                <div class="flex float-right fill-gray-300">
                                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-gray-400 ">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                                                    </svg>
                                                </div>
                                            @elseif($task->direction == 'right')
                                                <div class="flex float-left fill-gray-300">
                                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-6 h-6 text-gray-400 ">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
                                                    </svg>
                                                </div>
                                            @endif

                                            <span
                                                class="{{ $task->type == 'Milestone' ? 'text-green-600' : '' }}  {{ $task->type == 'Material' ? 'text-yellow-600' : '' }} {{ $task->type == 'Task' ? 'text-indigo-600' : '' }} {{$task->direction == 'right' ? 'float-left' : ''}}"
                                                >
                                                {{$task->title}}
                                            </span>

                                            @can('update', $task)
                                                @if($task->vendor)
                                                    <br>
                                                    <span class="text-sm font-medium text-gray-600 {{$task->direction == 'right' ? 'float-left' : ''}}">{{$task->vendor->name, 15}}</span>
                                                @endif

                                                @if($task->user)
                                                    <br>
                                                    <span class="text-sm font-medium text-gray-600 {{$task->direction == 'right' ? 'float-left' : ''}}">{{$task->user->first_name, 15}}</span>
                                                @endif
                                            @endcan
                                        </div>
                                    </div>
                                @endforeach
                            @endforeach
                            @endif
                        </div>
                    </div>
                </div>
            </x-cards.body>
        </x-cards>
    @endforeach

    <script type="text/javascript">
        document.addEventListener('alpine:init', () => {
            Alpine.store('scrollSync', {
                scrollLeft: 0,
            })
            Alpine.bind('scrollSync', {
                '@scroll'(){
                    this.$store.scrollSync.scrollLeft = this.$el.scrollLeft
                },
                'x-effect'() {
                    this.$el.scrollLeft = this.$store.scrollSync.scrollLeft
                }
            })
        })
    </script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/gridstack.js/10.1.2/gridstack-all.js" defer></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/gridstack.js/10.1.2/gridstack.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/gridstack.js/10.1.2/gridstack-extra.min.css">
</div>
