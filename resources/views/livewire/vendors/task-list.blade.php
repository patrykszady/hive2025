<div wire:transition>
    <x-upcoming-tasks-list
        :grouped-tasks="$this->groupedTasks"
        :later-tasks="$this->laterTasks"
        :task-count="$this->taskCount"
        :unscheduled-tasks="$this->unscheduledTasks"
        :show-avatars="true"
        :clickable="true"
        :show-project-info="true"
        :show-vendor-info="false"
        :pending-tasks-expanded="false"
        :show-notifications="false"
        title="Tasks"
        empty-message="No tasks upcoming for this vendor."
    />
</div>
