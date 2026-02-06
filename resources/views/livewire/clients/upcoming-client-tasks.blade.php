<div wire:transition>
    <x-upcoming-tasks-list
        :grouped-tasks="$this->groupedTasks"
        :next-task-info="$this->nextTaskInfo"
        :task-count="$this->taskCount"
        :unscheduled-tasks="$this->unscheduledTasks"
        :show-avatars="true"
        :clickable="true"
        :show-project-info="true"
        title="Upcoming Project Tasks"
        empty-message="No upcoming tasks for this client."
    />
</div>
