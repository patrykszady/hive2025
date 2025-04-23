<flux:card
    size="sm"
    class="hover:bg-gray-50 dark:hover:bg-gray-700 p-2! m-1! cursor-pointer w-58 items-center overflow-hidden"
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

        <div class="flex items-center gap-1">
            <!-- Bottom-left corner text for vendor name -->
            @if($task->vendor)
                <div class="flex items-center gap-2">
                    <flux:avatar size="xs" name="{{ $task->vendor->name }}" color="auto" color:seed="{{ $task->vendor->id }}" />
                    <flux:text class="truncate w-32">{{ $task->vendor->name }}</flux:text>
                </div>
            @endif

            <!-- User Avatar Group in Bottom-Right -->
            @if($task->user)
                <flux:avatar.group class="ml-auto">
                    <flux:avatar size="xs" name="{{ $task->user->full_name }}" color="auto" color:seed="{{ $task->user->id }}" />
                </flux:avatar.group>
            @endif
        </div>
    </div>
</flux:card>
