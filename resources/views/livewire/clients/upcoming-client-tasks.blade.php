@php
    $isClientUser = auth()->user()->is_client_user;
    $showProjectInfo = $this->distinctProjectCount > 1;
@endphp
<div wire:transition>
    <x-upcoming-tasks-list
        :grouped-tasks="$this->groupedTasks"
        :next-task-info="$this->nextTaskInfo"
        :task-count="$this->taskCount"
        :unscheduled-tasks="$this->unscheduledTasks"
        :show-avatars="true"
        :clickable="!$isClientUser"
        :show-project-info="$showProjectInfo"
        :show-vendor-info="!$isClientUser"
        title="Upcoming Project Tasks"
        empty-message="No upcoming tasks for this client."
    />
</div>
