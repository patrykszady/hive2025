<div>
    {{-- Add todo --}}
    {{-- no submit button needed if an input exists under form wire:submit="add" --}}
    {{-- <form wire:submit="add">
        <div class="flex gap-2 mb-4">
            <input wire:model="draft" type="text" class="grow rounded-full shadow shadow-slate-300 px-5 py-3" placeholder="Add next...">
        </div>
    </form> --}}

    {{-- Todo list --}}
    {{-- _{{$project->id}} --}}
    <x-sortable group="tasks" handler="sort" x-sort:config="{ filter: '.filtered' }" class="grid gap-3" :key="$project->id">
        @foreach($this->tasks as $task)
            {{-- group flex items-center justify-between p-1.5 bg-white rounded-full shadow shadow-slate-300 --}}
            <x-sortable.item
                :key="$task->id"
                wire:click="$dispatchTo('tasks.task-create', 'editTask', { task: {{$task->id}} })"
                @class([
                    'cursor-pointer pl-1 border border-solid border-gray-300 h-12 hover:bg-gray-100 font-bold rounded-md text-clip overflow-hidden bg-white',
                    'filtered' => !is_null($task->start_date) ? $task->start_date->format('Y-m-d') != $task_date : false
                ])
                >
                @can('update', $task)
                    @if(!is_null($task->start_date) ? $task->start_date->format('Y-m-d') == $task_date : true)
                        <span
                            class="{{ $task->type == 'Milestone' ? 'text-green-600' : '' }}  {{ $task->type == 'Material' ? 'text-yellow-600' : '' }} {{ $task->type == 'Task' ? 'text-indigo-600' : '' }} {{$task->direction == 'right' ? 'float-right' : ''}}"
                            >
                            {{$task->title}}
                        </span>
                    @else
                        <span
                            class="{{ $task->type == 'Milestone' ? 'text-green-300' : '' }}  {{ $task->type == 'Material' ? 'text-yellow-300' : '' }} {{ $task->type == 'Task' ? 'text-indigo-300' : '' }} {{$task->direction == 'right' ? 'float-right' : ''}}"
                            >
                            {{$task->title}}
                        </span>
                    @endif

                    @if($task->duration > 1)
                        <span class="float-right text-gray-400 mr-2">
                            @if($task->start_date->format('Y-m-d') == $task_date)
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="w-4 h-4 ml-auto text-gray-400 shrink-0 size-4">
                                    <path fill-rule="evenodd" d="M4.22 6.22a.75.75 0 0 1 1.06 0L8 8.94l2.72-2.72a.75.75 0 1 1 1.06 1.06l-3.25 3.25a.75.75 0 0 1-1.06 0L4.22 7.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                                </svg>
                            @elseif($task->end_date->format('Y-m-d') == $task_date)
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="w-4 h-4 ml-auto text-gray-400 shrink-0 size-4">
                                    <path fill-rule="evenodd" d="M11.78 9.78a.75.75 0 0 1-1.06 0L8 7.06 5.28 9.78a.75.75 0 0 1-1.06-1.06l3.25-3.25a.75.75 0 0 1 1.06 0l3.25 3.25a.75.75 0 0 1 0 1.06Z" clip-rule="evenodd" />
                                </svg>
                            @else
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="w-4 h-4 ml-auto text-gray-400 shrink-0 size-4">
                                    <path fill-rule="evenodd" d="M11.78 9.78a.75.75 0 0 1-1.06 0L8 7.06 5.28 9.78a.75.75 0 0 1-1.06-1.06l3.25-3.25a.75.75 0 0 1 1.06 0l3.25 3.25a.75.75 0 0 1 0 1.06Z" clip-rule="evenodd" />
                                </svg>
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="w-4 h-4 ml-auto text-gray-400 shrink-0 size-4">
                                    <path fill-rule="evenodd" d="M4.22 6.22a.75.75 0 0 1 1.06 0L8 8.94l2.72-2.72a.75.75 0 1 1 1.06 1.06l-3.25 3.25a.75.75 0 0 1-1.06 0L4.22 7.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                                </svg>
                            @endif
                        </span>
                    @endif

                    <br>
                    <span class="text-sm font-medium @if(!is_null($task->start_date) ? $task->start_date->format('Y-m-d') == $task_date : true) text-gray-600 @else text-gray-300 @endif">
                        @if($task->vendor) {{$task->vendor->name, 15}} @elseif($task->user) {{$task->user->first_name, 15}} @endif
                    </span>
                @endcan
            </x-sortable.item>
        @endforeach
    </x-sortable>
</div>
