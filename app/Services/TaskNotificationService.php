<?php

namespace App\Services;

use App\Models\NotificationSetting;
use App\Models\PushSubscription;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TaskNotificationService
{
    public function __construct(
        protected ScheduleSmsService $scheduleSmsService,
        protected SmsScheduleService $smsScheduleService,
    ) {}

    // ─── Task Querying ───────────────────────────────────────

    /**
     * Get tasks that fall on a specific date, with optional eager loading and filters.
     *
     * @param  array<int, string>  $with
     * @param  callable|null  $queryCallback
     */
    public function getTasksForDate(
        Carbon $date,
        array $with = ['project.client', 'project.createdByVendor', 'vendor'],
        ?callable $queryCallback = null,
    ): Collection {
        return $this->scheduleSmsService->getTasksForDate($date, $with, $queryCallback);
    }

    /**
     * Get tasks for a user on a given date.
     */
    public function getTasksForUser(User $user, Carbon $date): Collection
    {
        $dateStr = $date->format('Y-m-d');

        return Task::query()
            ->with(['project.client', 'vendor'])
            ->whereJsonContains('user_ids', $user->id)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $dateStr)
            ->whereDate('end_date', '>=', $dateStr)
            ->orderBy('start_date')
            ->get()
            ->filter(function (Task $task) use ($dateStr) {
                $selectedDates = (array) data_get($task->options, 'dates', []);

                if (! empty($selectedDates)) {
                    return in_array($dateStr, $selectedDates, true);
                }

                return true;
            });
    }

    /**
     * Get tasks for a project on a given date.
     */
    public function getTasksForProject(int $projectId, Carbon $date): Collection
    {
        $dateStr = $date->format('Y-m-d');

        return Task::query()
            ->with(['project.client', 'vendor'])
            ->where('project_id', $projectId)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $dateStr)
            ->whereDate('end_date', '>=', $dateStr)
            ->get()
            ->filter(function (Task $task) use ($dateStr) {
                $selectedDates = (array) data_get($task->options, 'dates', []);

                if (! empty($selectedDates)) {
                    return in_array($dateStr, $selectedDates, true);
                }

                return $task->start_date->format('Y-m-d') === $dateStr;
            });
    }

    // ─── User Discovery ──────────────────────────────────────

    /**
     * Build a unified map of users → tasks for a target date.
     * Includes both team members (assigned via user_ids) and client users.
     *
     * @return array<int, array{user: User, tasks: Collection, roles: array<string>}>
     */
    public function buildRecipientMap(Collection $tasks, Carbon $date): array
    {
        $recipients = [];

        foreach ($tasks as $task) {
            if ($this->scheduleSmsService->shouldSkipWeekendTask($task, $date)) {
                continue;
            }

            // Team members (assigned users)
            foreach ($task->users as $user) {
                $this->addRecipient($recipients, $user, $task, 'team');
            }

            // Client users (via project → client → users)
            $client = $task->project?->client;
            if ($client) {
                foreach ($client->users as $user) {
                    $this->addRecipient($recipients, $user, $task, 'client');
                }
            }
        }

        return $recipients;
    }

    /**
     * Add a user to the recipients map with their role.
     */
    protected function addRecipient(array &$recipients, User $user, Task $task, string $role): void
    {
        if (! isset($recipients[$user->id])) {
            $recipients[$user->id] = [
                'user' => $user,
                'tasks' => collect(),
                'roles' => [],
            ];
        }

        // Avoid duplicate tasks
        if (! $recipients[$user->id]['tasks']->contains('id', $task->id)) {
            $recipients[$user->id]['tasks']->push($task);
        }

        if (! in_array($role, $recipients[$user->id]['roles'], true)) {
            $recipients[$user->id]['roles'][] = $role;
        }
    }

    // ─── Setting Checks ──────────────────────────────────────

    /**
     * Check whether a user should receive a notification on a specific channel + timing.
     *
     * @param  string  $channel  'sms'|'email'|'browser'
     * @param  string  $timing   'morning'|'evening'|'realtime'
     */
    public function shouldNotify(User $user, string $channel, string $timing): bool
    {
        // Browser notifications are per-subscription, check push_subscriptions directly
        if ($channel === 'browser') {
            $column = match ($timing) {
                'morning' => 'morning_enabled',
                'evening' => 'evening_enabled',
                default => 'realtime_enabled',
            };

            return PushSubscription::where('user_id', $user->id)
                ->where($column, true)
                ->exists();
        }

        $settings = $user->notificationSetting;

        if (! $settings) {
            return false;
        }

        $key = "{$timing}_{$channel}";

        return (bool) ($settings->{$key} ?? false);
    }

    /**
     * Check if current time is within the user's realtime notification window.
     */
    public function isWithinRealtimeWindow(User $user, ?string $timezone = null): bool
    {
        $settings = $user->notificationSetting;

        if (! $settings || ! $settings->realtime_start || ! $settings->realtime_end) {
            return true; // No custom window, allow
        }

        $tz = $timezone ?? config('sms.business_hours.timezone', 'America/Chicago');
        $now = Carbon::now($tz);
        $start = Carbon::parse($settings->realtime_start, $tz);
        $end = Carbon::parse($settings->realtime_end, $tz);

        return $now->between($start, $end);
    }

    // ─── Convenience Delegates ───────────────────────────────

    public function isWithinBusinessHours(?string $timezone = null): bool
    {
        return $this->smsScheduleService->isWithinBusinessHours($timezone);
    }

    public function getNextBusinessHoursStart(?string $timezone = null): Carbon
    {
        return $this->smsScheduleService->getNextBusinessHoursStart($timezone);
    }

    public function getThrottleMinutes(): int
    {
        return $this->smsScheduleService->getThrottleMinutes();
    }

    public function getLogger(string $channel): \Psr\Log\LoggerInterface
    {
        return $this->smsScheduleService->getLogger($channel);
    }
}
