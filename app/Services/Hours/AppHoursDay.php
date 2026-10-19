<?php

namespace App\Services\Hours;

use App\Models\Hour;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The app's Hours tab: one day's worth of loggable projects, exactly as the
 * web's HourCreate::selectedDate() offers them, reshaped for a phone screen
 * instead of a reactive form. Project visibility is left entirely to
 * Project's own global scope (ProjectScope), same as AppProjectDirectory.
 */
class AppHoursDay
{
    /** "Today" for the lock window, independent of any browser-timezone cookie — the app carries no such cookie. */
    public const TIMEZONE = 'America/Chicago';

    public function __construct(private HourWeekLock $weekLock) {}

    /** The oldest date Hours/Timesheets reach back to — mirrors HourCreate::minDate(). */
    public function minDate(): Carbon
    {
        return Carbon::now(self::TIMEZONE)->subMonths(12)->startOfMonth()->startOfWeek(Carbon::MONDAY);
    }

    /** Every date already confirmed into a Timesheet for $user, from minDate() onward. */
    public function lockedDates(User $user): array
    {
        return $this->weekLock->confirmedWeekDates($user, $this->minDate());
    }

    /**
     * The full day payload the Hours screen needs: whether the day is
     * locked, and each offered project with its status, any hours already
     * logged that day, and the user's own tasks on that project that day.
     *
     * @return array{date: string, locked: bool, projects: list<array<string, mixed>>}
     */
    public function dayPayload(User $user, string $date): array
    {
        $locked = in_array($date, $this->lockedDates($user), true);
        $projects = $this->projectsForDate($user, $date);
        $existingByProject = $this->existingHours($user, $date);

        $rows = $projects->map(function (Project $project) use ($user, $existingByProject, $date): array {
            $existing = $existingByProject->get($project->id);
            $status = $project->latestStatus;

            return [
                'id' => $project->id,
                'name' => $project->project_name,
                'short_address' => $project->short_address,
                'status' => $status ? ['code' => $status->status_code, 'title' => $status->title, 'color' => $status->badge_color] : null,
                'hours' => $existing ? (float) $existing->hours : null,
                'tasks' => $this->userTasksFor($project, $user, $date),
            ];
        })->values()->all();

        return [
            'date' => $date,
            'locked' => $locked,
            'projects' => $rows,
        ];
    }

    /**
     * Other projects not already offered for $date, for the "log a different
     * project" picker — mirrors HourCreate::other_projects() exactly (every
     * project from the last 3 years, not already in the list).
     *
     * @param  list<int>  $excludeIds
     * @return list<array{id: int, name: ?string, short_address: ?string}>
     */
    public function otherProjects(array $excludeIds): array
    {
        return Project::query()
            ->whereNotIn('id', $excludeIds)
            ->whereYear('created_at', '>=', Carbon::now(self::TIMEZONE)->subYears(3)->year)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->project_name,
                'short_address' => $project->short_address,
            ])
            ->values()
            ->all();
    }

    /**
     * Projects offered for $date — the union of: projects Active/Service
     * Call on that date (Project::statusOnDate()), projects with any task
     * touching that date, and projects $user already logged hours against
     * that date — mirrors HourCreate::selectedDate() exactly, sorted the
     * same way (status title, then status start date).
     *
     * @return Collection<int, Project>
     */
    private function projectsForDate(User $user, string $date): Collection
    {
        $statusProjects = Project::statusOnDate([6, 8], $date)->get();

        $taskProjectIds = Task::query()
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->pluck('project_id')
            ->unique();

        $hourProjectIds = Hour::query()
            ->where('user_id', $user->id)
            ->where('date', $date)
            ->pluck('project_id')
            ->unique();

        $ids = $statusProjects->pluck('id')
            ->merge($taskProjectIds)
            ->merge($hourProjectIds)
            ->unique()
            ->values();

        return Project::query()
            ->whereIn('id', $ids)
            ->with(['tasks' => fn ($query) => $query->where('start_date', '<=', $date)->where('end_date', '>=', $date)])
            ->with('latestStatus')
            ->get()
            ->sortBy([
                ['latestStatus.title', 'asc'],
                ['latestStatus.start_date', 'desc'],
            ])
            ->values();
    }

    /**
     * $user's own hour entries for $date, keyed by project id.
     *
     * @return Collection<int, Hour>
     */
    private function existingHours(User $user, string $date): Collection
    {
        return Hour::query()
            ->where('user_id', $user->id)
            ->where('date', $date)
            ->get()
            ->keyBy('project_id');
    }

    /**
     * $user's own tasks on $project for $date — mirrors HourCreate's
     * `day_project_tasks` (every task on the project that date whose
     * user_ids includes the signed-in user).
     *
     * @return list<array{id: int, title: ?string}>
     */
    private function userTasksFor(Project $project, User $user, string $date): array
    {
        return $project->tasks
            ->filter(function (Task $task) use ($user): bool {
                $userIds = $task->user_ids ?? [];

                return in_array($user->id, $userIds, true) || in_array((string) $user->id, $userIds, true);
            })
            ->map(fn (Task $task): array => ['id' => $task->id, 'title' => $task->title])
            ->values()
            ->all();
    }
}
