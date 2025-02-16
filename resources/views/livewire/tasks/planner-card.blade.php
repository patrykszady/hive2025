<div>
    {{-- Add todo --}}
    {{-- no submit button needed if an input exists under form wire:submit="add" --}}
    {{-- <form wire:submit="add">
        <div class="flex gap-2 mb-4">
            <input wire:model="draft" type="text" class="grow rounded-full shadow shadow-slate-300 px-5 py-3" placeholder="Add next...">
        </div>
    </form> --}}

    <div x-sort="$wire.sort($key, $position)" x-sort:group="tasks" class="grid gap-3">
        @foreach($this->tasks as $task)
            {{-- group flex items-center justify-between p-1.5 bg-white rounded-full shadow shadow-slate-300 --}}
            <div
                x-sort:item="{{$task->id}}"
                wire:click="$dispatchTo('tasks.task-create', 'editTask', { task: {{$task->id}} })"
                :key="$task->id"
                @class([
                    'cursor-pointer pl-1 border border-solid border-gray-300 h-12 hover:bg-gray-100 font-bold rounded-md text-clip overflow-hidden bg-white'
                    // 'filtered' => !is_null($task->start_date) ? $task->start_date->format('Y-m-d') != $task_date : false
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
                                <flux:icon.chevron-down variant="mini" />
                            @elseif($task->end_date->format('Y-m-d') == $task_date)
                                <flux:icon.chevron-up variant="mini" />
                            @else
                                <flux:icon.chevron-up-down variant="solid" />
                            @endif
                        </span>
                    @endif

                    <br>
                    <span class="text-sm font-medium @if(!is_null($task->start_date) ? $task->start_date->format('Y-m-d') == $task_date : true) text-gray-600 @else text-gray-300 @endif">
                        @if($task->vendor) {{$task->vendor->name, 15}} @elseif($task->user) {{$task->user->first_name, 15}} @endif
                    </span>
                @endcan
            </div>
        @endforeach
    </div>
</div>
