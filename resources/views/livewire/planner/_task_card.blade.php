<flux:card
    size="sm"
    class="hover:bg-gray-50 dark:hover:bg-gray-700 p-2! m-1! cursor-pointer"
    wire:click="$dispatchTo('tasks.task-create', 'editTask', { task: {{$task->id}} })"
    x-sort:item="{{$task->id}}"
    :key="$task->id"
    >

    <div class="flex flex-col justify-between h-full">
        <!-- Task Title and Icon -->
        <flux:heading class="flex items-center gap-1">
            {{$task->title}}
            <flux:icon
                name="{{$task->duration <= 1 ? 'bars-3' : ($task->start_date->format('Y-m-d') == $day->format('Y-m-d') ? 'chevron-down' :
                    ($task->end_date->format('Y-m-d') == $day->format('Y-m-d') ? 'chevron-up' : 'chevron-up-down'))}}"
                class="ml-auto text-gray-400"
                variant="micro"
            />
        </flux:heading>

        <!-- Bottom-left corner text for vendor name -->
        <flux:text class="truncate overflow-hidden whitespace-nowrap w-full">{{ $task->vendor->name ?? '' }}</flux:text>
        <div class="text-xs text-gray-500 truncate overflow-hidden whitespace-nowrap w-full">

        </div>
    </div>
</flux:card>
