<div>
    <x-upcoming-tasks-list
        :grouped-tasks="$this->groupedTasks"
        :task-count="$this->taskCount"
        :show-avatars="true"
        :clickable="true"
        :show-project-info="true"
        title="My Upcoming Tasks"
        empty-message="No upcoming tasks assigned to you."
    />
</div>
