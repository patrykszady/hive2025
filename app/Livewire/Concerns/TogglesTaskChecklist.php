<?php

namespace App\Livewire\Concerns;

use App\Services\Tasks\TaskChecklistToggle;

/**
 * Tick a task checklist item from any card that renders the shared
 * checklist sub-card. Same rules everywhere: team members only, own
 * vendor's tasks only, and only from the task's scheduled day onward —
 * work gets checked off on site, not in advance. The rule itself now lives
 * in App\Services\Tasks\TaskChecklistToggle, shared with the Hive
 * Contractors app's Home tab (App\Http\Controllers\Api\App\V1\
 * HomeController::toggleChecklistItem()), so a card ticked from the app and
 * one ticked from the web obey exactly the same checks.
 */
trait TogglesTaskChecklist
{
    public function toggleChecklistItem(int $taskId, int $index): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        app(TaskChecklistToggle::class)->toggle($user, $taskId, $index, today(browser_timezone()));
    }
}
