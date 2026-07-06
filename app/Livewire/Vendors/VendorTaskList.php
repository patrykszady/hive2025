<?php

namespace App\Livewire\Vendors;

use App\Livewire\Concerns\HasLaterTasks;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Upcoming + pending tasks assigned to a specific vendor, rendered with the
 * shared task-card list on the vendor profile page.
 */
class VendorTaskList extends Component
{
    use HasLaterTasks;

    protected $listeners = ['refreshComponent' => 'refreshTasks'];

    public Vendor $vendor;

    public function refreshTasks(): void
    {
        unset($this->groupedTasks, $this->laterTasks, $this->taskCount, $this->unscheduledTasks);
    }

    /**
     * Base query for tasks assigned to this vendor (excludes reminders).
     */
    protected function vendorTaskQuery(): Builder
    {
        return Task::withTrashed()
            ->where('vendor_id', $this->vendor->id)
            ->where(function (Builder $query): void {
                $query->where('type', '!=', 'Reminder')->orWhereNull('type');
            });
    }

    /**
     * Scheduled tasks grouped by date across an 8-day window (yesterday .. +6).
     *
     * @return Collection<string, Collection<int, Task>>
     */
    #[Computed]
    public function groupedTasks(): Collection
    {
        $today = browser_today();
        $cutoffStr = $today->copy()->subDay()->format('Y-m-d');
        $windowEndStr = $today->copy()->addDays(6)->format('Y-m-d');

        $tasks = $this->vendorTaskQuery()
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $windowEndStr)
            ->whereDate('end_date', '>=', $cutoffStr)
            ->with(['vendor', 'project.client', 'project.latestStatus'])
            ->orderBy('start_date')
            ->orderBy('end_date')
            ->get();

        $this->hydrateAssignedUsers($tasks);

        $grouped = collect();

        foreach ($tasks as $task) {
            $selectedDates = (array) data_get($task->options, 'dates', []);

            if (! empty($selectedDates)) {
                foreach ($selectedDates as $dateStr) {
                    if ($dateStr >= $cutoffStr && $dateStr <= $windowEndStr) {
                        $grouped[$dateStr] ??= collect();
                        $grouped[$dateStr]->push($task);
                    }
                }
            } else {
                $dateStr = $task->start_date->format('Y-m-d');
                if ($dateStr >= $cutoffStr && $dateStr <= $windowEndStr) {
                    $grouped[$dateStr] ??= collect();
                    $grouped[$dateStr]->push($task);
                }
            }
        }

        $grouped = $grouped->map(function (Collection $dayTasks, string $dateStr) {
            return $dayTasks->sortBy(function (Task $task) use ($dateStr) {
                $startTime = (string) data_get($task->options, "time_settings.$dateStr.start_time", '');
                $usesTime = (bool) data_get($task->options, "time_settings.$dateStr.use_time", false);

                return $usesTime && $startTime !== '' ? '0_' . $startTime : '1';
            })->values();
        });

        // Ensure the full 8-day window is present (yesterday through +6 days).
        for ($i = -1; $i < 7; $i++) {
            $dateStr = $today->copy()->addDays($i)->format('Y-m-d');
            $grouped[$dateStr] ??= collect();
        }

        return $grouped
            ->filter(fn (Collection $dayTasks, string $date) => $date >= $cutoffStr && $date <= $windowEndStr)
            ->sortKeys();
    }

    protected function laterTasksBaseQuery(): Builder
    {
        return $this->vendorTaskQuery()
            ->whereNotNull('start_date')
            ->whereNotNull('end_date');
    }

    protected function laterTasksWindowEnd(): string
    {
        return browser_today()->addDays(6)->format('Y-m-d');
    }

    /**
     * Total scheduled tasks in the display window (for the badge).
     */
    #[Computed]
    public function taskCount(): int
    {
        $today = browser_today();

        return $this->vendorTaskQuery()
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $today->copy()->addDays(6)->format('Y-m-d'))
            ->whereDate('end_date', '>=', $today->format('Y-m-d'))
            ->count();
    }

    /**
     * Unscheduled (no dates) tasks assigned to this vendor.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function unscheduledTasks(): Collection
    {
        $tasks = $this->vendorTaskQuery()
            ->whereNull('start_date')
            ->with(['vendor', 'project.client', 'project.latestStatus'])
            ->orderBy('created_at')
            ->get();

        $this->hydrateAssignedUsers($tasks);

        return $tasks;
    }

    /**
     * Attach the assigned User models onto each task's "users" relation.
     *
     * @param  Collection<int, Task>  $tasks
     */
    protected function hydrateAssignedUsers(Collection $tasks): void
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
                ->map(fn ($userId) => $usersById->get($userId))
                ->filter()
                ->values();

            $task->setRelation('users', $assignedUsers);
        }
    }

    public function render()
    {
        return view('livewire.vendors.task-list');
    }
}
