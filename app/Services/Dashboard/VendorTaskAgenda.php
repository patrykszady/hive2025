<?php

namespace App\Services\Dashboard;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The query + grouping logic behind the web Hub's "Your Tasks" card
 * (App\Livewire\Dashboard\VendorTasks) — extracted so the Hive Contractors
 * app's Home tab can show the exact same tasks, counts and windowing
 * without forking that logic. VendorTasks' own Computed properties now
 * just delegate here; this service holds no Livewire/presentation state of
 * its own, so it can be called from a request lifecycle just as easily as
 * from a component.
 *
 * $today is always passed in rather than read from browser_today() here —
 * the web caller passes the browser-timezone cookie's date, the app caller
 * passes Carbon::now('America/Chicago')->startOfDay() (it carries no such
 * cookie), and tests can freeze whichever they need.
 */
class VendorTaskAgenda
{
    /** Project ids $vendor owns — every query below starts from this. */
    public function projectIds(Vendor $vendor): Collection
    {
        return Project::where('belongs_to_vendor_id', $vendor->id)->pluck('id');
    }

    /**
     * Tasks assigned to $userId across $vendor's projects, grouped by date,
     * across the 8-day window running from the day before $today through 6
     * days after it.
     *
     * @return Collection<string, Collection<int, Task>>
     */
    public function groupedTasks(Vendor $vendor, int $userId, Carbon $today): Collection
    {
        $cutoff = $today->copy()->subDay();
        $windowEnd = $today->copy()->addDays(6);

        $cutoffStr = $cutoff->format('Y-m-d');
        $windowEndStr = $windowEnd->format('Y-m-d');

        $projectIds = $this->projectIds($vendor);

        if ($projectIds->isEmpty()) {
            return collect();
        }

        $tasks = Task::withTrashed()
            ->whereIn('project_id', $projectIds)
            ->where($this->assignedToUser($userId))
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $windowEndStr)
            ->whereDate('end_date', '>=', $cutoffStr)
            ->with(['vendor', 'project.client', 'project.latestStatus'])
            ->orderBy('start_date')
            ->orderBy('end_date')
            ->get();

        $this->primeTaskUsers($tasks);
        Task::primeUpdateActivities($tasks->pluck('id'));

        $grouped = collect();

        foreach ($tasks as $task) {
            $selectedDates = (array) data_get($task->options, 'dates', []);

            if (! empty($selectedDates)) {
                foreach ($selectedDates as $dateStr) {
                    if ($dateStr >= $cutoffStr && $dateStr <= $windowEndStr) {
                        if (! $grouped->has($dateStr)) {
                            $grouped[$dateStr] = collect();
                        }
                        $grouped[$dateStr]->push($task);
                    }
                }
            } else {
                $dateStr = $task->start_date->format('Y-m-d');
                if ($dateStr >= $cutoffStr && $dateStr <= $windowEndStr) {
                    if (! $grouped->has($dateStr)) {
                        $grouped[$dateStr] = collect();
                    }
                    $grouped[$dateStr]->push($task);
                }
            }
        }

        $grouped = $grouped->sortKeys();

        $grouped = $grouped->map(function (Collection $tasksForDay, string $dateStr) {
            return $tasksForDay->sortBy(function (Task $task) use ($dateStr) {
                $startTime = (string) data_get($task->options, "time_settings.$dateStr.start_time", '');
                $usesTime = (bool) data_get($task->options, "time_settings.$dateStr.use_time", false);
                $hasTime = $usesTime && $startTime !== '';

                return $hasTime ? '0_'.$startTime : '1';
            })->values();
        });

        // Ensure 8 consecutive days starting from the day before $today.
        for ($i = -1; $i < 7; $i++) {
            $dateStr = $today->copy()->addDays($i)->format('Y-m-d');
            if (! $grouped->has($dateStr)) {
                $grouped[$dateStr] = collect();
            }
        }

        $grouped = $grouped->filter(fn ($tasksForDay, $date) => $date >= $cutoffStr && $date <= $windowEndStr);

        return $grouped->sortKeys();
    }

    /** Total tasks due in the 7-day window starting today — the Hub's badge count. */
    public function taskCount(Vendor $vendor, int $userId, Carbon $today): int
    {
        $startOfWindow = $today->format('Y-m-d');
        $endOfWindow = $today->copy()->addDays(6)->format('Y-m-d');

        $projectIds = $this->projectIds($vendor);

        if ($projectIds->isEmpty()) {
            return 0;
        }

        return Task::withTrashed()
            ->whereIn('project_id', $projectIds)
            ->where($this->assignedToUser($userId))
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $endOfWindow)
            ->whereDate('end_date', '>=', $startOfWindow)
            ->count();
    }

    /**
     * Undated tasks assigned to $userId across $vendor's projects — the
     * Hub's "Pending Tasks" tray.
     *
     * @return Collection<int, Task>
     */
    public function unscheduledTasks(Vendor $vendor, int $userId): Collection
    {
        $projectIds = $this->projectIds($vendor);

        if ($projectIds->isEmpty()) {
            return collect();
        }

        $tasks = Task::withTrashed()
            ->whereIn('project_id', $projectIds)
            ->where($this->assignedToUser($userId))
            ->whereNull('start_date')
            ->with(['vendor', 'project.client', 'project.latestStatus'])
            ->orderBy('created_at')
            ->get();

        Task::primeAssignedUsers($tasks);
        Task::primeUpdateActivities($tasks->pluck('id'));

        return $tasks;
    }

    /** The base query App\Livewire\Concerns\HasLaterTasks groups into the Hub's "Later" accordion. */
    public function laterTasksBaseQuery(Vendor $vendor, int $userId): Builder
    {
        $projectIds = $this->projectIds($vendor);

        return Task::withTrashed()
            ->whereIn('project_id', $projectIds)
            ->where($this->assignedToUser($userId))
            ->whereNotNull('start_date')
            ->whereNotNull('end_date');
    }

    /**
     * Tasks beyond the 7-day window starting at $today, grouped by date —
     * the Hub's "Later" accordion. This duplicates (rather than calls into)
     * App\Livewire\Concerns\HasLaterTasks's own grouping body: that trait is
     * tied to a Livewire component (abstract query hooks + #[Computed]
     * caching) and is shared by several unrelated web lists beyond
     * VendorTasks, so changing its shape to be callable here would ripple
     * well outside the Hub. The grouping rule itself (bucket by
     * options.dates, else start_date, past the window end) is generic and
     * has no other state, which is why duplicating just that loop here is
     * low-risk — see the class docblock.
     *
     * @return Collection<string, Collection<int, Task>>
     */
    public function laterTasks(Vendor $vendor, int $userId, Carbon $today): Collection
    {
        $windowEndStr = $today->copy()->addDays(6)->format('Y-m-d');

        $tasks = $this->laterTasksBaseQuery($vendor, $userId)
            ->whereDate('start_date', '>', $windowEndStr)
            ->with(['vendor', 'project.client', 'project.latestStatus'])
            ->orderBy('start_date')
            ->orderBy('end_date')
            ->get();

        Task::primeUpdateActivities($tasks->pluck('id'));
        $this->primeTaskUsers($tasks);

        $grouped = collect();

        foreach ($tasks as $task) {
            $selectedDates = (array) data_get($task->options, 'dates', []);

            if (! empty($selectedDates)) {
                foreach ($selectedDates as $dateStr) {
                    if ($dateStr > $windowEndStr) {
                        if (! $grouped->has($dateStr)) {
                            $grouped[$dateStr] = collect();
                        }
                        $grouped[$dateStr]->push($task);
                    }
                }
            } else {
                $dateStr = $task->start_date->format('Y-m-d');
                if ($dateStr > $windowEndStr) {
                    if (! $grouped->has($dateStr)) {
                        $grouped[$dateStr] = collect();
                    }
                    $grouped[$dateStr]->push($task);
                }
            }
        }

        return $grouped->sortKeys();
    }

    /** Distinct projects represented in $groupedTasks — the Hub's project-count line. */
    public function distinctProjectCount(Collection $groupedTasks): int
    {
        return $groupedTasks->flatten(1)->pluck('project_id')->unique()->count();
    }

    /** @return \Closure(Builder): void */
    private function assignedToUser(int $userId): \Closure
    {
        return fn (Builder $query) => $query
            ->whereJsonContains('user_ids', (string) $userId)
            ->orWhereJsonContains('user_ids', $userId);
    }

    /** @param  Collection<int, Task>  $tasks */
    private function primeTaskUsers(Collection $tasks): void
    {
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
                ->map(fn ($id) => $usersById->get($id))
                ->filter()
                ->values();

            $task->setRelation('users', $assignedUsers);
        }
    }
}
