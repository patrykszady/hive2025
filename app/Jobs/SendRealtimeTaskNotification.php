<?php

namespace App\Jobs;

use App\Mail\TaskNotificationDigest;
use App\Models\PushSubscription;
use App\Models\SmsLog;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ClientScheduleSmsNotification;
use App\Notifications\TeamTaskSmsNotification;
use App\Services\TaskNotificationService;
use App\Services\WebPushService;
use App\Support\SmsChannel;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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

        $throttleMinutes = $service->getThrottleMinutes();

        foreach ($recipients as $recipientData) {
            $user = $recipientData['user'];
            $userTasks = $recipientData['tasks'];
            $roles = $recipientData['roles'];

            try {
                $this->sendSms($service, $user, $userTasks, $roles, $todayStr, $today, $vendorTimezone, $throttleMinutes);
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

            $user->notify(new TeamTaskSmsNotification(
                $userTasks->all(),
                $today,
                'update',
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

        // Client SMS
        if (in_array('client', $roles, true)) {
            $tasksByProject = $userTasks->groupBy('project_id');

            foreach ($tasksByProject as $projectId => $projectTasks) {
                $project = $projectTasks->first()?->project;

                if (! $project) {
                    continue;
                }

                if (SmsLog::wasRecentlyNotified(SmsLog::CHANNEL_CLIENT, $user->id, $throttleMinutes, $projectId)) {
                    continue;
                }

                $currentHash = SmsLog::generateTasksHash($projectTasks);
                $lastLog = SmsLog::where('channel', SmsLog::CHANNEL_CLIENT)
                    ->where('project_id', $projectId)
                    ->where('user_id', $user->id)
                    ->where('target_date', $todayStr)
                    ->latest()
                    ->first();

                if ($lastLog && $lastLog->content_hash === $currentHash) {
                    continue;
                }

                $notification = new ClientScheduleSmsNotification(
                    $project,
                    $user->first_name ?? 'there',
                    'changed',
                    $projectTasks,
                );

                $channel = app(SmsChannel::get());
                $channel->send($user, $notification);

                SmsLog::logSent([
                    'channel' => SmsLog::CHANNEL_CLIENT,
                    'type' => 'changed',
                    'user_id' => $user->id,
                    'project_id' => $projectId,
                    'target_date' => $todayStr,
                    'content_hash' => $currentHash,
                ]);
            }
        }
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
