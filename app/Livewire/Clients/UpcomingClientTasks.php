<?php

namespace App\Livewire\Clients;

use App\Models\Client;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class UpcomingClientTasks extends Component
{
    protected $listeners = ['refreshComponent' => '$refresh'];

    public Client $client;

    /**
     * Get today's date in the app timezone.
     */
    protected function getToday(): Carbon
    {
        return Carbon::today((string) config('app.timezone'));
    }

    /**
     * Get project IDs for this client.
     */
    protected function getProjectIds(): Collection
    {
        return $this->client->projects()->pluck('id');
    }

    /**
     * Get today's date string (Y-m-d) for the view.
     * Uses browser timezone so "Today" badge reflects the user's local time.
     */
    #[Computed]
    public function todayDate(): string
    {
        return browser_today()->format('Y-m-d');
    }

    /**
     * Get tomorrow's date string (Y-m-d) for the view.
     * Uses browser timezone so "Tomorrow" badge reflects the user's local time.
     */
    #[Computed]
    public function tomorrowDate(): string
    {
        return browser_today()->addDay()->format('Y-m-d');
    }

    /**
     * Get tasks grouped by date across all client projects.
     *
     * @return Collection<string, Collection<int, Task>>
     */
    #[Computed]
    public function groupedTasks(): Collection
    {
        $today = $this->getToday();
        $startOfWeek = $today->copy()->startOfWeek(Carbon::MONDAY);
        $endOfWeek = $today->copy()->endOfWeek(Carbon::SUNDAY);

        $startOfWeekStr = $startOfWeek->format('Y-m-d');
        $endOfWeekStr = $endOfWeek->format('Y-m-d');

        $projectIds = $this->getProjectIds();

        if ($projectIds->isEmpty()) {
            return collect();
        }

        $tasks = Task::query()
            ->whereIn('project_id', $projectIds)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $endOfWeekStr)
            ->whereDate('end_date', '>=', $startOfWeekStr)
            ->with(['vendor', 'project.client'])
            ->orderBy('start_date')
            ->orderBy('end_date')
            ->get();

        $allUserIds = $tasks
            ->pluck('user_ids')
            ->flatten()
            ->filter()
            ->unique()
            ->values();

        $usersById = $allUserIds->isNotEmpty()
            ? User::query()->whereIn('id', $allUserIds)->get()->keyBy('id')
            : collect();

        foreach ($tasks as $task) {
            $assignedUsers = collect($task->user_ids ?? [])
                ->map(fn ($userId) => $usersById->get($userId))
                ->filter()
                ->values();

            $task->setRelation('users', $assignedUsers);
        }

        $grouped = collect();

        $currentDate = $startOfWeek->copy();
        while ($currentDate->lte($endOfWeek)) {
            $grouped[$currentDate->format('Y-m-d')] = collect();
            $currentDate->addDay();
        }

        foreach ($tasks as $task) {
            $selectedDates = (array) data_get($task->options, 'dates', []);

            if (! empty($selectedDates)) {
                foreach ($selectedDates as $dateStr) {
                    if ($dateStr >= $startOfWeekStr && $dateStr <= $endOfWeekStr && $grouped->has($dateStr)) {
                        $grouped[$dateStr]->push($task);
                    }
                }
            } else {
                $dateStr = $task->start_date->format('Y-m-d');
                if ($dateStr >= $startOfWeekStr && $dateStr <= $endOfWeekStr && $grouped->has($dateStr)) {
                    $grouped[$dateStr]->push($task);
                }
            }
        }

        return $grouped->sortKeys();
    }

    /**
     * Get info about the next task after the displayed week.
     */
    #[Computed]
    public function nextTaskInfo(): ?object
    {
        $today = $this->getToday();
        $weekEnd = $today->copy()->endOfWeek(Carbon::SUNDAY);
        $weekEndStr = $weekEnd->format('Y-m-d');

        $projectIds = $this->getProjectIds();

        if ($projectIds->isEmpty()) {
            return null;
        }

        $tasks = Task::query()
            ->whereIn('project_id', $projectIds)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '>', $weekEndStr)
            ->get();

        $futureDates = collect();
        foreach ($tasks as $task) {
            $selectedDates = (array) data_get($task->options, 'dates', []);
            if (! empty($selectedDates)) {
                foreach ($selectedDates as $dateStr) {
                    if ($dateStr > $weekEndStr) {
                        $futureDates->push($dateStr);
                    }
                }
            } else {
                $taskStartStr = $task->start_date->format('Y-m-d');
                if ($taskStartStr > $weekEndStr) {
                    $futureDates->push($taskStartStr);
                }
            }
        }

        if ($futureDates->isEmpty()) {
            return null;
        }

        $nextDateStr = $futureDates->sort()->first();
        $nextDate = Carbon::parse($nextDateStr, (string) config('app.timezone'))->startOfDay();

        $daysUntil = 0;
        $current = $weekEnd->copy()->addDay()->startOfDay();
        while ($current->lte($nextDate)) {
            if (! $current->isWeekend()) {
                $daysUntil++;
            }
            $current->addDay();
        }

        if ($daysUntil <= 0) {
            return null;
        }

        return (object) [
            'days' => $daysUntil,
            'label' => $daysUntil === 1 ? 'Next task in 1 day' : "Next task in {$daysUntil} days",
            'date' => $nextDate->format('D, M j'),
        ];
    }

    /**
     * Get total task count for the badge.
     */
    #[Computed]
    public function taskCount(): int
    {
        $today = $this->getToday();
        $startOfWeek = $today->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
        $endOfWeek = $today->copy()->endOfWeek(Carbon::SUNDAY)->format('Y-m-d');

        $projectIds = $this->getProjectIds();

        if ($projectIds->isEmpty()) {
            return 0;
        }

        return Task::query()
            ->whereIn('project_id', $projectIds)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $endOfWeek)
            ->whereDate('end_date', '>=', $startOfWeek)
            ->count();
    }

    /**
     * Get tasks with no scheduled dates across client projects.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function unscheduledTasks(): Collection
    {
        $projectIds = $this->getProjectIds();

        if ($projectIds->isEmpty()) {
            return collect();
        }

        return Task::query()
            ->whereIn('project_id', $projectIds)
            ->whereNull('start_date')
            ->with(['vendor', 'project.client'])
            ->orderBy('created_at')
            ->get();
    }

    public function render()
    {
        return view('livewire.clients.upcoming-client-tasks');
    }

    public function placeholder()
    {
        return view('livewire.clients.upcoming-client-tasks-placeholder');
    }
}
