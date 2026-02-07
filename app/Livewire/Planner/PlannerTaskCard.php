<?php

namespace App\Livewire\Planner;

use App\Models\Task;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Livewire island for a single task card on the planner board.
 *
 * Each card independently loads its own data so that morph-diffing is
 * scoped to a tiny DOM tree, guaranteeing that team members, vendor
 * status and every other field update correctly after an edit.
 */
class PlannerTaskCard extends Component
{
    /** Task primary key. */
    public int $taskId;

    /** The day (Y-m-d) this card instance represents. */
    public string $dayFormat;

    /** Owning project id (used for structural context). */
    public int $projectId;

    /**
     * Explicitly clear computed caches so the next render
     * re-queries fresh data from the database.
     */
    #[On('refreshComponent')]
    public function refreshCard(): void
    {
        unset($this->task, $this->taskUsers, $this->dayCounter, $this->arrivalTimeLabel);
    }

    // ─── Computed ────────────────────────────────────────────

    #[Computed]
    public function task(): ?Task
    {
        return Task::with('vendor')->find($this->taskId);
    }

    #[Computed]
    public function taskUsers(): \Illuminate\Support\Collection
    {
        return $this->task?->users ?? collect();
    }

    #[Computed]
    public function dayCounter(): ?array
    {
        $task = $this->task;

        if (! $task) {
            return null;
        }

        $selectedDates = $task->options->dates ?? [];
        $totalDays = count($selectedDates);

        if ($totalDays <= 1) {
            return null;
        }

        sort($selectedDates);
        $currentDay = array_search($this->dayFormat, $selectedDates);

        if ($currentDay === false) {
            return null;
        }

        return [
            'current' => $currentDay + 1,
            'total' => $totalDays,
        ];
    }

    #[Computed]
    public function arrivalTimeLabel(): ?string
    {
        $task = $this->task;

        if (! $task) {
            return null;
        }

        $dayTimeSettings = data_get($task->options, "time_settings.{$this->dayFormat}");
        $dayUsesTime = (bool) data_get($dayTimeSettings, 'use_time', false);
        $dayStartTime = (string) data_get($dayTimeSettings, 'start_time', '');

        if (! $dayUsesTime || $dayStartTime === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('H:i', $dayStartTime)->format('g:i A');
        } catch (\Exception) {
            return null;
        }
    }

    // ─── Actions ─────────────────────────────────────────────

    public function editTask(): void
    {
        $this->dispatch('editTask', task: $this->taskId)->to('tasks.task-create');
    }

    // ─── Render ──────────────────────────────────────────────

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.planner.task-card');
    }
}
