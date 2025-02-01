<div
    wire:click="$dispatchTo('tasks.task-create', 'editTask', { task: {{$task->id}} })"
    x-sort:item="{{$task->id}}"
    :key="$task->id"
    @class([
        'cursor-pointer pl-1 border h-12 border-solid border-gray-300 hover:bg-gray-200 font-bold rounded-md text-clip overflow-hidden bg-white',
        // 'h-12' => !is_null($task->start_date) ? $task->start_date->format('Y-m-d') === $day['database_date'] : false,
        'h-6' => !is_null($task->start_date) ? $task->start_date->format('Y-m-d') !== $day['database_date'] : false,
        'filtered' => !is_null($task->start_date) ? $task->start_date->format('Y-m-d') != $day['database_date'] || $task->duration > 1 : false
    ])
    >
    @can('update', $task)
        @if(!is_null($task->start_date) ? $task->start_date->format('Y-m-d') == $day['database_date'] : true)
            <span
                class="{{ $task->type == 'Milestone' ? 'text-green-600' : '' }}  {{ $task->type == 'Material' ? 'text-yellow-600' : '' }} {{ $task->type == 'Task' ? 'text-sky-600' : '' }} {{$task->direction == 'right' ? 'float-right' : ''}}"
                >
                {{$task->title}}
            </span>
        @else
            <span
                class="{{ $task->type == 'Milestone' ? 'text-green-300' : '' }}  {{ $task->type == 'Material' ? 'text-yellow-300' : '' }} {{ $task->type == 'Task' ? 'text-sky-300' : '' }} {{$task->direction == 'right' ? 'float-right' : ''}}"
                >
                {{$task->title}}
            </span>
        @endif

        <span class="float-right text-gray-400 mr-2">
            @if($task->duration > 1)
                @if($task->start_date->format('Y-m-d') == $day['database_date'])
                    <flux:icon.chevron-down variant="mini" />
                @elseif($task->end_date->format('Y-m-d') == $day['database_date'])
                    <flux:icon.chevron-up variant="mini" />
                @else
                    <flux:icon.chevron-up-down variant="solid" />
                @endif
            @else
                <span x-sort:handle>
                    <flux:icon.bars-3 variant="mini" />
                </span>
            @endif
        </span>

        <br>
        <span class="text-sm font-medium @if(!is_null($task->start_date) ? $task->start_date->format('Y-m-d') == $day['database_date'] : true) text-gray-600 @else text-gray-300 @endif">
            @if($task->vendor) {{$task->vendor->name, 15}} @elseif($task->user) {{$task->user->first_name, 15}} @endif
        </span>
    @endcan
</div>
