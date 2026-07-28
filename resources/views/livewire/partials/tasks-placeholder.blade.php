<div>
    <x-upcoming-tasks-list-skeleton
        :title="$title ?? 'Tasks'"
        :show-project-info="$showProjectInfo ?? false"
        :count="$count ?? null"
        :task-counts="$taskCounts ?? null"
        :pending-cards="$pendingCards ?? []"
        :project-id="$projectId ?? null"
        :client-id="$clientId ?? null"
        :show-add-task="$showAddTask ?? false"
        :clickable="$clickable ?? true"
        :show-notifications="$showNotifications ?? true"
    />
</div>
