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
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Unified digest job for morning/evening task notifications.
 *
 * Replaces SendScheduleSms (client+team), SendTaskPushNotifications (today/tomorrow),
 * and sends email via TaskNotificationDigest.
 *
 * Channels: SMS, Email, Browser Push
 * Audiences: Team members + Client users
 */
class SendDigestNotifications implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $timing  'morning' or 'evening'
     */
    public function __construct(
        public string $timing = 'morning',
    ) {}

    public function handle(TaskNotificationService $service): void
    {
        $targetDate = $this->timing === 'morning' ? Carbon::today() : Carbon::tomorrow();
        $dateStr = $targetDate->format('Y-m-d');
        $smsType = $this->timing === 'morning' ? 'today' : 'tomorrow';

        Log::info("SendDigestNotifications: Starting {$this->timing} digest for {$dateStr}");

        // Fetch all tasks for the target date
        $tasks = $service->getTasksForDate($targetDate, [
            'project.client.users.notificationSetting',
            'project.createdByVendor',
            'vendor',
        ]);

        if ($tasks->isEmpty()) {
            Log::info("SendDigestNotifications: No tasks found for {$dateStr}");

            return;
        }

        // Build unified recipient map (team + client users)
        $recipients = $service->buildRecipientMap($tasks, $targetDate);

        Log::info("SendDigestNotifications: Found {$tasks->count()} tasks, " . count($recipients) . ' recipients');

        // Process each recipient
        foreach ($recipients as $recipientData) {
            $user = $recipientData['user'];
            $userTasks = $recipientData['tasks'];
            $roles = $recipientData['roles'];

            try {
                $this->sendSms($service, $user, $userTasks, $roles, $smsType, $dateStr, $targetDate);
                $this->sendEmail($service, $user);
                // Push is queued separately and flushed after the loop
            } catch (\Throwable $e) {
                Log::error("SendDigestNotifications: Failed for user {$user->id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Browser push in one batch
        $this->sendPushNotifications($service, $targetDate, $dateStr);

        Log::info("SendDigestNotifications: Completed {$this->timing} digest");
    }

    // ─── SMS ─────────────────────────────────────────────────

    protected function sendSms(
        TaskNotificationService $service,
        User $user,
        \Illuminate\Support\Collection $userTasks,
        array $roles,
        string $smsType,
        string $dateStr,
        Carbon $targetDate,
    ): void {
        $settingKey = $this->timing === 'morning' ? 'morning_sms' : 'evening_sms';

        if (! $service->shouldNotify($user, 'sms', $this->timing === 'morning' ? 'morning' : 'evening')) {
            return;
        }

        if (empty($user->cell_phone)) {
            return;
        }

        // Send team-style SMS if user is a team member
        if (in_array('team', $roles, true)) {
            $channel = SmsLog::CHANNEL_TEAM;
            $type = $this->timing === 'evening' ? 'reminder' : $smsType;

            if (SmsLog::wasAlreadySent($channel, $type, $user->id, $dateStr)) {
                return;
            }

            $user->notify(new TeamTaskSmsNotification(
                $userTasks->all(),
                $targetDate,
                'reminder',
            ));

            SmsLog::logSent([
                'channel' => $channel,
                'type' => SmsLog::TYPE_REMINDER,
                'user_id' => $user->id,
                'target_date' => $dateStr,
            ]);

            return; // Don't also send client SMS to the same user
        }

        // Send client-style SMS if user is a client
        if (in_array('client', $roles, true)) {
            // Group tasks by project for client notifications
            $tasksByProject = $userTasks->groupBy('project_id');

            foreach ($tasksByProject as $projectId => $projectTasks) {
                $project = $projectTasks->first()?->project;

                if (! $project) {
                    continue;
                }

                if (SmsLog::wasAlreadySent(SmsLog::CHANNEL_CLIENT, $smsType, $user->id, $dateStr, $projectId)) {
                    continue;
                }

                $notification = new ClientScheduleSmsNotification(
                    $project,
                    $user->nickname ?: ($user->first_name ?? 'there'),
                    $smsType,
                    $projectTasks,
                );

                $channel = app(SmsChannel::get());
                $channel->send($user, $notification);

                SmsLog::logSent([
                    'channel' => SmsLog::CHANNEL_CLIENT,
                    'type' => $smsType,
                    'user_id' => $user->id,
                    'project_id' => $projectId,
                    'target_date' => $dateStr,
                    'content_hash' => SmsLog::generateTasksHash($projectTasks),
                ]);
            }
        }
    }

    // ─── Email ───────────────────────────────────────────────

    protected function sendEmail(TaskNotificationService $service, User $user): void
    {
        $emailTiming = $this->timing === 'morning' ? 'morning' : 'evening';

        if (! $service->shouldNotify($user, 'email', $emailTiming)) {
            return;
        }

        if (empty($user->email)) {
            return;
        }

        Mail::to($user->email)->send(
            new TaskNotificationDigest($user, $emailTiming)
        );
    }

    // ─── Browser Push ────────────────────────────────────────

    protected function sendPushNotifications(
        TaskNotificationService $service,
        Carbon $targetDate,
        string $dateStr,
    ): void {
        $webPushService = app(WebPushService::class);
        $browserTiming = $this->timing === 'morning' ? 'morning' : 'evening';
        $pushTitle = $this->timing === 'morning' ? "Today's Tasks" : "Tomorrow's Tasks";

        $subscriptions = PushSubscription::with('user.notificationSetting')->get();

        // Group eligible subscriptions with their per-user payloads
        $eligible = collect();

        foreach ($subscriptions as $pushSub) {
            $user = $pushSub->user;

            if (! $user) {
                continue;
            }

            if (! $service->shouldNotify($user, 'browser', $browserTiming)) {
                continue;
            }

            $userTasks = $service->getTasksForUser($user, $targetDate);

            if ($userTasks->isEmpty()) {
                continue;
            }

            $count = $userTasks->count();
            $taskWord = $count === 1 ? 'task' : 'tasks';
            $body = "You have {$count} {$taskWord} scheduled for " . ($this->timing === 'morning' ? 'today' : 'tomorrow') . '.';

            $firstTask = $userTasks->first();
            if ($count === 1 && $firstTask) {
                $body = $firstTask->title ?? 'Task';
                if ($firstTask->project) {
                    $body .= "\n" . ($firstTask->project->short_address ?? $firstTask->project->name);
                }
            }

            $eligible->push([
                'subscription' => $pushSub,
                'payload' => [
                    'title' => $pushTitle,
                    'body' => $body,
                    'tag' => "task-digest-{$this->timing}-{$dateStr}",
                    'data' => ['url' => '/hub'],
                ],
            ]);
        }

        // Send each eligible subscription through WebPushService individually
        // (different payloads per user require individual sends)
        foreach ($eligible as $item) {
            $webPushService->sendToSubscriptions(
                collect([$item['subscription']]),
                $item['payload'],
            );
        }
    }
}
