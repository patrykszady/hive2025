<?php

namespace App\Livewire\Concerns;

use App\Models\Task;

/**
 * Tick a task checklist item from any card that renders the shared
 * checklist sub-card. Same rules everywhere: team members only, own
 * vendor's tasks only, and only from the task's scheduled day onward —
 * work gets checked off on site, not in advance.
 */
trait TogglesTaskChecklist
{
    public function toggleChecklistItem(int $taskId, int $index): void
    {
        $user = auth()->user();

        if (! $user || $user->is_browsing_as_client || ! $user->vendor) {
            return;
        }

        $task = Task::withoutGlobalScopes()
            ->where('belongs_to_vendor_id', $user->vendor->id)
            ->find($taskId);

        if (! $task) {
            return;
        }

        if (! $task->start_date || $task->start_date->copy()->startOfDay()->gt(today(browser_timezone()))) {
            return;
        }

        $options = json_decode(json_encode($task->options ?? []), true) ?: [];

        if (! isset($options['checklist'][$index])) {
            return;
        }

        $item = (array) $options['checklist'][$index];
        $item['completed'] = ! (bool) ($item['completed'] ?? false);
        $options['checklist'][$index] = $item;

        $task->options = $options;
        $task->saveQuietly();
    }
}
