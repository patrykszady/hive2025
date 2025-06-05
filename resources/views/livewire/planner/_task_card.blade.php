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
            @if (count($task->dates ?? []) > 1)
                <flux:icon
                    name="{{ ($task->dates[0] ?? '') === $day->format('Y-m-d') ? 'chevron-down' :
                    (($task->dates[count($task->dates ?? []) - 1] ?? '') === $day->format('Y-m-d') ? 'chevron-up' : 'chevron-up-down') }}"
                    variant="micro"
                    class="ml-auto text-gray-400"
                />
            @endif
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
            @if($task->users->count() > 0)
                <flux:avatar.group class="ml-auto">
                    @foreach($task->users as $user)
                        <flux:avatar size="xs" name="{{ $user->full_name }}" color="auto" color:seed="{{ $user->id }}" />
                    {{-- <flux:avatar size="xs" name="{{ $task->user->full_name }}" color="auto" color:seed="{{ $task->user->id }}" /> --}}
                    @endforeach
                </flux:avatar.group>
            @endif
        </div>
    </div>
</flux:card>
