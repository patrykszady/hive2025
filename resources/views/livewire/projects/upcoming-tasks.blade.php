<div>
    <x-upcoming-tasks-list
        :grouped-tasks="$this->groupedTasks"
        :next-task-info="$this->nextTaskInfo"
        :task-count="$this->taskCount"
        :unscheduled-tasks="$this->unscheduledTasks"
        :show-avatars="true"
        :clickable="true"
    />
</div>

