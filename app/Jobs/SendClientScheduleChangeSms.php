<?php

namespace App\Jobs;

use App\Models\NotificationSetting;
use App\Models\Project;
use App\Models\SmsLog;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ClientScheduleSmsNotification;
use App\Services\SmsScheduleService;
use App\Support\SmsChannel;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendClientScheduleChangeSms implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    /**
     * The number of seconds after which the job's unique lock will be released.
     * This allows consolidation of multiple task changes for the same project.
     */
    public int $uniqueFor = 900; // 15 minutes

    public int $projectId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $projectId)
    {
        $this->projectId = $projectId;
        $this->onQueue('default');
    }

    /**
     * The unique ID of the job - prevents duplicate jobs for the same project.
     */
    public function uniqueId(): string
    {
        return 'client_schedule_sms_' . $this->projectId;
    }

    /**
     * Execute the job.
     */
    public function handle(SmsScheduleService $smsService): void
    {
        $log = $smsService->getLogger('client');

        $project = Project::with(['client', 'client.users', 'createdByVendor'])->find($this->projectId);

        if (! $project) {
            $log->warning("SendClientScheduleChangeSms: Project not found", ['project_id' => $this->projectId]);

            return;
        }

        if (! $this->smsEnabledForProject($project)) {
            return;
        }

        // Check if within business hours
        if (! $smsService->isWithinBusinessHours()) {
            // Re-queue for next business hours
            $nextStart = $smsService->getNextBusinessHoursStart();
            self::dispatch($this->projectId)->delay($nextStart);

            return;
        }

        $todayStr = $smsService->getToday()->format('Y-m-d');

        // Get tasks for today
        $tasks = Task::where('project_id', $this->projectId)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $todayStr)
            ->whereDate('end_date', '>=', $todayStr)
            ->get()
            ->filter(function (Task $task) use ($todayStr) {
                $selectedDates = (array) data_get($task->options, 'dates', []);

                if (! empty($selectedDates)) {
                    return in_array($todayStr, $selectedDates);
                }

                return $task->start_date->format('Y-m-d') === $todayStr;
            });

        if ($tasks->isEmpty()) {
            $log->info("SendClientScheduleChangeSms: No tasks for today after change", [
                'project_id' => $this->projectId,
            ]);

            return;
        }

        // Get client users with phone numbers
        $clientUsers = $this->getClientUsersWithPhone($project);

        if ($clientUsers->isEmpty()) {
            $log->info("SendClientScheduleChangeSms: No client users with phone numbers", [
                'project_id' => $this->projectId,
            ]);

            return;
        }

        $currentHash = SmsLog::generateTasksHash($tasks);
        $throttleMinutes = $smsService->getThrottleMinutes();

        foreach ($clientUsers as $user) {
            // Check user-level notification preferences
            $settings = $user->notificationSetting;
            if (! $settings || ! $settings->realtime_sms) {
                $log->info("SendClientScheduleChangeSms: Client user has realtime SMS disabled or no settings", [
                    'project_id' => $this->projectId,
                    'user_id' => $user->id,
                ]);

                continue;
            }

            // Check throttle - don't send if recently notified
            if (SmsLog::wasRecentlyNotified(SmsLog::CHANNEL_CLIENT, $user->id, $throttleMinutes, $this->projectId)) {
                $log->info("SendClientScheduleChangeSms: Throttled, recently notified", [
                    'project_id' => $this->projectId,
                    'user_id' => $user->id,
                ]);

                continue;
            }

            // Check if tasks actually changed since last notification
            $lastLog = SmsLog::where('channel', SmsLog::CHANNEL_CLIENT)
                ->where('project_id', $this->projectId)
                ->where('user_id', $user->id)
                ->where('target_date', $todayStr)
                ->latest()
                ->first();

            if ($lastLog && $lastLog->content_hash === $currentHash) {
                $log->info("SendClientScheduleChangeSms: Tasks unchanged, skipping", [
                    'project_id' => $this->projectId,
                    'user_id' => $user->id,
                ]);

                continue;
            }

            // Send the notification
            $this->sendNotification($project, $user, $tasks, $todayStr, $currentHash, $smsService);
        }
    }

    /**
     * Get client users with phone numbers for a project.
     */
    protected function getClientUsersWithPhone(Project $project): \Illuminate\Support\Collection
    {
        $client = $project->client;

        if (! $client) {
            return collect();
        }

        return $client->users->filter(function (User $user) {
            return ! empty($user->cell_phone);
        });
    }

    /**
     * Send the notification and log it.
     */
    protected function sendNotification(
        Project $project,
        User $user,
        \Illuminate\Support\Collection $tasks,
        string $todayStr,
        string $tasksHash,
        SmsScheduleService $smsService
    ): void {
        $log = $smsService->getLogger('client');

        try {
            $notification = new ClientScheduleSmsNotification(
                $project,
                $user->first_name ?? 'there',
                'changed',
                $tasks
            );

            // Use the user directly - it has routeNotificationForTwilio() method
            $channel = app(SmsChannel::get());
            $channel->send($user, $notification);

            // Log the send
            SmsLog::logSent([
                'channel' => SmsLog::CHANNEL_CLIENT,
                'type' => SmsLog::TYPE_CHANGED,
                'user_id' => $user->id,
                'project_id' => $project->id,
                'target_date' => $todayStr,
                'content_hash' => $tasksHash,
            ]);
        } catch (\Exception $e) {
            $log->error("SendClientScheduleChangeSms: Failed", [
                'project_id' => $project->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            report($e);
        }
    }

    private function smsEnabledForProject(Project $project): bool
    {
        $vendor = $project->createdByVendor;

        if (! $vendor) {
            return true;
        }

        $baseEnabled = (bool) data_get($vendor->options, 'sms_enabled', true);

        return (bool) data_get($vendor->options, 'sms_client_enabled', $baseEnabled);
    }
}
