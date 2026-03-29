<div>
    <x-upcoming-tasks-list-skeleton
        :title="$title ?? 'Tasks'"
        :show-project-info="$showProjectInfo ?? false"
        :actions-width="$actionsWidth ?? 'w-32'"
        :count="$count ?? null"
        :show-header-skeleton="$showHeaderSkeleton ?? true"
    />
</div>
