<div>
    <flux:card>
        <div class="flex items-center justify-between mb-4">
            <flux:heading size="lg">My Upcoming Tasks</flux:heading>
            <flux:badge size="sm" color="zinc">{{ $this->tasks->count() }}</flux:badge>
        </div>

        @if($this->tasks->isEmpty())
            <flux:text class="text-zinc-500">No upcoming tasks assigned to you.</flux:text>
        @else
            <div class="space-y-2">
                @foreach($this->tasks as $task)
                    <x-cards.task
                        :task="$task"
                        :show-project="true"
                        wire:key="user-task-{{ $task->id }}"
                        wire:click="$dispatchTo('tasks.task-create', 'editTask', { task: {{ $task->id }} })"
                    />
                @endforeach
            </div>
        @endif
    </flux:card>
</div>
