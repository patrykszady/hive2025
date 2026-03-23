<?php

namespace App\Jobs;

use App\Mail\TaskNotificationDigest;
use App\Models\PushSubscription;
use App\Models\SmsGroupThread;
use App\Models\SmsLog;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TeamTaskSmsNotification;
use App\Services\GroupSmsService;
use App\Services\TaskNotificationService;
use App\Services\WebPushService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

/**
 * Unified realtime notification job — dispatched when tasks change.
 *
 * Replaces SendTeamScheduleChangeSms + SendClientScheduleChangeSms.
 * Sends via all enabled channels (SMS, Email, Browser Push).
 *
 * Consolidation key: project_id (client changes) + each user_id (team changes).
 * ShouldBeUnique prevents duplicate jobs within the 15-minute window.
 */
class SendRealtimeTaskNotification implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $uniqueFor = 900; // 15 minutes

    /**
     * @param  int  $projectId  The project whose tasks changed
     * @param  array<int>  $affectedUserIds  Specific team member IDs to notify (empty = project-only / client)
     */
    public function __construct(
        public int $projectId,
        public array $affectedUserIds = [],
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        $userPart = ! empty($this->affectedUserIds)
            ? '_users_' . implode('_', array_unique($this->affectedUserIds))
            : '';

        return "realtime_task_notification_{$this->projectId}{$userPart}";
    }

    public function handle(TaskNotificationService $service): void
    {
        $log = $service->getLogger('notification');
        $today = Carbon::today();
        $todayStr = $today->format('Y-m-d');

        // Get today's tasks for this project
        $tasks = $service->getTasksForProject($this->projectId, $today);

        // Determine the owning vendor's timezone for business hours
        $project = $tasks->first()?->project;
        $vendorTimezone = $project?->createdByVendor?->timezone;

        // Collect all recipients
        $recipients = [];

        // Team members
        $userIds = ! empty($this->affectedUserIds)
            ? $this->affectedUserIds
            : $tasks->flatMap(fn (Task $t) => $t->user_ids ?? [])->unique()->all();

        foreach ($userIds as $userId) {
            $user = User::with('notificationSetting')->find($userId);

            if (! $user) {
                continue;
            }

            $userTasks = $service->getTasksForUser($user, $today);

            $recipients[$user->id] = [
                'user' => $user,
                'tasks' => $userTasks,
                'roles' => ['team'],
            ];
        }

        // Client users
        if ($project?->client) {
            foreach ($project->client->users as $clientUser) {
                $clientUser->load('notificationSetting');

                if (isset($recipients[$clientUser->id])) {
                    // User is already a team member, add client role
                    $recipients[$clientUser->id]['roles'][] = 'client';
                } else {
                    $recipients[$clientUser->id] = [
                        'user' => $clientUser,
                        'tasks' => $tasks, // Client sees all project tasks
                        'roles' => ['client'],
                    ];
                }
            }
        }

        // Detect today's changes once for reuse by both client thread and team SMS
        $changes = $this->detectTodayChanges($tasks, $todayStr);

        // Send update to the client's group SMS thread (replaces 1:1 client SMS)
        $this->sendClientThreadMessage($today, $changes);

        $throttleMinutes = $service->getThrottleMinutes();

        foreach ($recipients as $recipientData) {
            $user = $recipientData['user'];
            $userTasks = $recipientData['tasks'];
            $roles = $recipientData['roles'];

            try {
                $this->sendSms($service, $user, $userTasks, $roles, $todayStr, $today, $vendorTimezone, $throttleMinutes, $changes);
                $this->sendEmail($service, $user);
                $this->sendPush($service, $user, $userTasks, $todayStr);
            } catch (\Throwable $e) {
                Log::error("SendRealtimeTaskNotification: Failed for user {$user->id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // ─── SMS ─────────────────────────────────────────────────

    protected function sendSms(
        TaskNotificationService $service,
        User $user,
        \Illuminate\Support\Collection $userTasks,
        array $roles,
        string $todayStr,
        Carbon $today,
        ?string $vendorTimezone,
        int $throttleMinutes,
        array $changes = [],
    ): void {
        if (! $service->shouldNotify($user, 'sms', 'realtime')) {
            return;
        }

        if (empty($user->cell_phone)) {
            return;
        }

        if (! $service->isWithinRealtimeWindow($user, $vendorTimezone)) {
            return;
        }

        // Team SMS
        if (in_array('team', $roles, true)) {
            if (SmsLog::wasRecentlyNotified(SmsLog::CHANNEL_TEAM, $user->id, $throttleMinutes)) {
                return;
            }

            $currentHash = SmsLog::generateTasksHash($userTasks);
            $lastLog = SmsLog::where('channel', SmsLog::CHANNEL_TEAM)
                ->where('user_id', $user->id)
                ->where('target_date', $todayStr)
                ->latest()
                ->first();

            if ($lastLog && $lastLog->content_hash === $currentHash) {
                return;
            }

            // Build removed tasks list from change detection
            $removedTasksList = collect($changes['removedTasks'] ?? [])
                ->map(fn ($title) => (object) ['title' => $title])
                ->all();

            $user->notify(new TeamTaskSmsNotification(
                $userTasks->all(),
                $today,
                'update',
                $removedTasksList,
                $changes['timeChanges'] ?? [],
            ));

            SmsLog::logSent([
                'channel' => SmsLog::CHANNEL_TEAM,
                'type' => SmsLog::TYPE_UPDATE,
                'user_id' => $user->id,
                'target_date' => $todayStr,
                'content_hash' => $currentHash,
            ]);

            return; // Don't also send client SMS to same user
        }

        // Client SMS is now handled via sendClientThreadMessage() (group thread)
        // Individual client users still receive email and push notifications below
    }

    // ─── Client Thread SMS ────────────────────────────────────

    protected function sendClientThreadMessage(Carbon $today, array $changes = []): void
    {
        $thread = SmsGroupThread::where('project_id', $this->projectId)
            ->whereNotNull('welcome_sent_at')
            ->latest('last_activity_at')
            ->first();

        if (! $thread) {
            return;
        }

        $message = $this->buildThreadUpdateMessage($thread, $today, $changes);

        if (empty($message)) {
            return;
        }

        // Content-hash dedup: skip if the last automated message in thread is identical
        $lastAutoMessage = $thread->messages()
            ->where('direction', 'outbound')
            ->whereNull('sent_by_user_id')
            ->latest()
            ->first();

        if ($lastAutoMessage && $lastAutoMessage->text === $message) {
            return;
        }

        app(GroupSmsService::class)->sendToThread($thread, $message);

        Log::channel('notification')->info('SendRealtimeTaskNotification: Sent client thread message', [
            'project_id' => $this->projectId,
            'thread_id' => $thread->id,
        ]);
    }

    protected function buildThreadUpdateMessage(SmsGroupThread $thread, Carbon $today, array $changes = []): string
    {
        $todayStr = $today->format('Y-m-d');

        // Fetch today's project tasks
        $allTasks = Task::query()
            ->with(['project'])
            ->where('project_id', $this->projectId)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $todayStr)
            ->whereDate('end_date', '>=', $todayStr)
            ->get()
            ->filter(function (Task $task) use ($todayStr) {
                $selectedDates = (array) data_get($task->options, 'dates', []);

                if (! empty($selectedDates)) {
                    return in_array($todayStr, $selectedDates, true);
                }

                return $task->start_date->format('Y-m-d') === $todayStr;
            });

        // Sort tasks: tasks with time first, then by time
        $todayTasks = $allTasks->sortBy(function ($task) use ($todayStr) {
            $startTime = (string) data_get($task->options, "time_settings.$todayStr.start_time", '');
            $usesTime = (bool) data_get($task->options, "time_settings.$todayStr.use_time", false);

            return ($usesTime && $startTime !== '') ? '0_' . $startTime : '1';
        })->values();

        // Use pre-computed changes (or detect if not provided)
        if (empty($changes)) {
            $changes = $this->detectTodayChanges($todayTasks, $todayStr);
        }

        if ($todayTasks->isEmpty() && empty($changes['removedTasks'])) {
            return '';
        }

        // Build greeting from client user first names
        $names = $thread->client?->users?->pluck('first_name')->filter()->values()->all() ?? [];
        $greeting = count($names) > 0
            ? 'Hi ' . implode(' & ', $names) . ','
            : 'Hi,';

        $intro = "TASKS UPDATED TODAY!";

        // Build today's section
        $daySections = [];

        if ($todayTasks->isNotEmpty()) {
            $shortDate = $today->format('D n/j');
            $dateLabel = "Today {$shortDate}";

            $taskLines = $todayTasks->map(function (Task $task) use ($todayStr, $changes) {
                $line = '- ' . trim($task->title ?? 'Task');

                $arrivalTime = $task->getArrivalTimeLabel($todayStr);
                if ($arrivalTime) {
                    $line .= " @ {$arrivalTime}";

                    $oldTime = $changes['timeChanges'][$task->id] ?? null;
                    if ($oldTime) {
                        $line .= " (was {$oldTime})";
                    }
                }

                return $line;
            })->implode("\n");

            $daySections[] = "{$dateLabel}:\n{$taskLines}";
        }

        // Removed tasks section
        if (! empty($changes['removedTasks'])) {
            $removedLines = collect($changes['removedTasks'])
                ->map(fn ($title) => "- {$title}")
                ->implode("\n");
            $daySections[] = "Removed from today:\n{$removedLines}";
        }

        $body = implode("\n", $daySections);

        $message = "{$greeting}\n{$intro}\n\n{$body}";

        // Schedule link
        $project = $allTasks->first()?->project;
        if ($project) {
            $baseUrl = config('app.dev_webhook_url') ?: rtrim((string) config('app.url'), '/');
            $token = $project->getOrCreateScheduleToken();
            $message .= "\n\nView Schedule: {$baseUrl}/s/{$token}";
        }

        $message .= "\n-GSC";

        return $message;
    }

    /**
     * Detect time changes and removed tasks from the activity log.
     *
     * @return array{timeChanges: array<int, string>, removedTasks: array<int, string>}
     */
    protected function detectTodayChanges(
        \Illuminate\Support\Collection $currentTasks,
        string $dateStr,
    ): array {
        $timeChanges = [];
        $removedTasks = [];

        $allProjectTaskIds = Task::withTrashed()
            ->where('project_id', $this->projectId)
            ->pluck('id')
            ->all();

        if (empty($allProjectTaskIds)) {
            return ['timeChanges' => $timeChanges, 'removedTasks' => $removedTasks];
        }

        $currentTaskIds = $currentTasks->pluck('id')->all();

        // Look back far enough to cover the 15-min consolidation window + buffer
        $since = now()->subHours(2);

        $activities = Activity::where('subject_type', Task::class)
            ->whereIn('subject_id', $allProjectTaskIds)
            ->where('created_at', '>=', $since)
            ->orderBy('created_at', 'asc')
            ->get();

        foreach ($activities as $activity) {
            $props = $activity->properties->toArray();
            $taskId = $activity->subject_id;

            if ($activity->description === 'deleted') {
                $oldOptions = data_get($props, 'old.options', []);
                $oldDates = (array) (is_object($oldOptions) ? ($oldOptions->dates ?? []) : ($oldOptions['dates'] ?? []));

                if (in_array($dateStr, $oldDates)) {
                    $removedTasks[$taskId] = data_get($props, 'old.title', 'Task');
                }

                continue;
            }

            if ($activity->description !== 'updated') {
                continue;
            }

            $oldOptions = data_get($props, 'old.options');
            $newOptions = data_get($props, 'attributes.options');

            if ($oldOptions === null && $newOptions === null) {
                continue;
            }

            // Detect time changes for today
            $oldTimeSetting = (array) data_get($oldOptions, "time_settings.{$dateStr}", []);
            $newTimeSetting = (array) data_get($newOptions, "time_settings.{$dateStr}", []);

            if ($oldTimeSetting != $newTimeSetting && ! isset($timeChanges[$taskId])) {
                $oldLabel = Task::formatTimeSettingsLabel($oldTimeSetting);
                if ($oldLabel) {
                    $timeChanges[$taskId] = $oldLabel;
                }
            }

            // Detect today removed from dates
            $oldDates = (array) (is_object($oldOptions) ? ($oldOptions->dates ?? []) : ($oldOptions['dates'] ?? []));
            $newDates = (array) (is_object($newOptions) ? ($newOptions->dates ?? []) : ($newOptions['dates'] ?? []));

            if (in_array($dateStr, $oldDates) && ! in_array($dateStr, $newDates)) {
                $title = data_get($props, 'old.title')
                    ?? data_get($props, 'attributes.title')
                    ?? Task::withTrashed()->find($taskId)?->title
                    ?? 'Task';
                $removedTasks[$taskId] = $title;
            }
        }

        // Don't list tasks as removed if they're back in current tasks
        foreach ($currentTaskIds as $id) {
            unset($removedTasks[$id]);
        }

        return [
            'timeChanges' => $timeChanges,
            'removedTasks' => array_values($removedTasks),
        ];
    }

    // ─── Email ───────────────────────────────────────────────

    protected function sendEmail(TaskNotificationService $service, User $user): void
    {
        if (! $service->shouldNotify($user, 'email', 'realtime')) {
            return;
        }

        if (empty($user->email)) {
            return;
        }

        Mail::to($user->email)->send(
            new TaskNotificationDigest($user, 'update')
        );
    }

    // ─── Browser Push ────────────────────────────────────────

    protected function sendPush(
        TaskNotificationService $service,
        User $user,
        \Illuminate\Support\Collection $userTasks,
        string $dateStr,
    ): void {
        if (! $service->shouldNotify($user, 'browser', 'realtime')) {
            return;
        }

        // Check if tasks actually changed (hash comparison)
        $currentHash = md5(json_encode($userTasks->pluck('id')->sort()->values()->toArray()));
        $cacheKey = "push_tasks_hash:{$user->id}:{$dateStr}";
        $lastHash = Cache::get($cacheKey);

        if ($lastHash === $currentHash) {
            return;
        }

        Cache::put($cacheKey, $currentHash, now()->endOfDay());

        // Skip push if this is the first check (morning digest will handle it)
        if ($lastHash === null) {
            return;
        }

        $subscriptions = PushSubscription::where('user_id', $user->id)
            ->where('realtime_enabled', true)
            ->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $count = $userTasks->count();

        app(WebPushService::class)->sendToSubscriptions($subscriptions, [
            'title' => 'Schedule Updated',
            'body' => "Your schedule for today has been updated. You have {$count} " . ($count === 1 ? 'task' : 'tasks') . '.',
            'tag' => "task-update-{$dateStr}",
            'data' => ['url' => '/hub'],
        ]);
    }
}
