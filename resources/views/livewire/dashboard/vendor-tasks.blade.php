<div wire:transition>
    <x-upcoming-tasks-list
        :grouped-tasks="$this->groupedTasks"
        :next-task-info="$this->nextTaskInfo"
        :task-count="$this->taskCount"
        :unscheduled-tasks="$this->unscheduledTasks"
        :show-avatars="true"
        :clickable="true"
        :show-project-info="true"
        :show-vendor-info="false"
        :show-add-task="true"
        title="Tasks"
        empty-message="No tasks upcoming for your projects."
    />
</div>
