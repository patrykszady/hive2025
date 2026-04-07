@php
    $isClientUser = auth()->user()->is_browsing_as_client;
    $showProjectInfo = $this->distinctProjectCount > 1;
@endphp
<div wire:transition>
    <x-upcoming-tasks-list
        :grouped-tasks="$this->groupedTasks"
        :later-tasks="$this->laterTasks"
        :task-count="$this->taskCount"
        :unscheduled-tasks="$this->unscheduledTasks"
        :show-avatars="true"
        :clickable="!$isClientUser"
        :show-project-info="$showProjectInfo"
        :show-vendor-info="!$isClientUser"
        :show-add-task="!$isClientUser"
        :client-id="$this->client->id"
        title="Tasks"
        empty-message="No tasks upcoming for this client."
    />
</div>
