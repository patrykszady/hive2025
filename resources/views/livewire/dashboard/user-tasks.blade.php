<div>
    <x-upcoming-tasks-list
        :grouped-tasks="$this->groupedTasks"
        :task-count="$this->taskCount"
        :show-avatars="true"
        :clickable="true"
        :show-project-info="true"
        title="My Tasks Upcoming"
        empty-message="No tasks upcoming assigned to you."
    />
</div>
