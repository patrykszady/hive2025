@php $isClientUser = auth()->user()->is_client_user; @endphp
<div wire:transition>
    <x-upcoming-tasks-list
        title="Tasks"
        :grouped-tasks="$this->groupedTasks"
        :next-task-info="$this->nextTaskInfo"
        :task-count="$this->taskCount"
        :unscheduled-tasks="$this->unscheduledTasks"
        :show-avatars="true"
        :clickable="!$isClientUser"
        :show-vendor-info="!$isClientUser"
        :project-id="$project->id"
    />
</div>

