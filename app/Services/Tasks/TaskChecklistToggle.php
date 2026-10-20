<?php

namespace App\Services\Tasks;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Ticks (or unticks) one checklist item on a task — the exact logic behind
 * App\Livewire\Concerns\TogglesTaskChecklist::toggleChecklistItem(), pulled
 * out so the Hive Contractors app's Home tab can tick the same checklist the
 * web Hub's task cards show (resources/views/components/schedule/
 * task-details.blade.php), without forking the rule: team members only,
 * own vendor's tasks only, and only from the task's scheduled day onward —
 * work gets checked off on site, not in advance. The trait now just calls
 * this.
 *
 * $today is passed in for the same reason as VendorTaskAgenda/
 * MonthlyFinancialSummary: the web caller passes today(browser_timezone()),
 * the app caller passes Carbon::now('America/Chicago')->startOfDay() (it
 * carries no such cookie).
 */
class TaskChecklistToggle
{
    /** Returns the updated Task, or null if the toggle was refused (not found, wrong vendor, not yet on-site, no such item) — never throws, mirroring the trait's silent no-op. */
    public function toggle(User $user, int $taskId, int $index, Carbon $today): ?Task
    {
        if ($user->is_browsing_as_client || ! $user->vendor) {
            return null;
        }

        $task = Task::withoutGlobalScopes()
            ->where('belongs_to_vendor_id', $user->vendor->id)
            ->find($taskId);

        if (! $task) {
            return null;
        }

        if (! $task->start_date || $task->start_date->copy()->startOfDay()->gt($today->copy()->startOfDay())) {
            return null;
        }

        $options = json_decode(json_encode($task->options ?? []), true) ?: [];

        if (! isset($options['checklist'][$index])) {
            return null;
        }

        $item = (array) $options['checklist'][$index];
        $item['completed'] = ! (bool) ($item['completed'] ?? false);
        $options['checklist'][$index] = $item;

        $task->options = $options;
        $task->saveQuietly();

        return $task;
    }
}
