<?php

namespace App\Jobs;

use App\Models\SmsLog;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TeamTaskSmsNotification;
use App\Services\SmsScheduleService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendTeamScheduleChangeSms implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    /**
     * The number of seconds after which the job's unique lock will be released.
     * This allows consolidation of multiple task changes for the same user.
     */
    public int $uniqueFor = 900; // 15 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $userId
    ) {
        $this->onQueue('default');
    }

    /**
     * The unique ID of the job - prevents duplicate jobs for the same user.
     */
    public function uniqueId(): string
    {
        return 'team_schedule_sms_' . $this->userId;
    }

    /**
     * Execute the job.
     */
    public function handle(SmsScheduleService $smsService): void
    {
        $log = $smsService->getLogger('team');

        $user = User::find($this->userId);

        if (! $user) {
            $log->warning("SendTeamScheduleChangeSms: User not found", ['user_id' => $this->userId]);
            return;
        }

        if (! $user->cell_phone) {
            $log->info("SendTeamScheduleChangeSms: User has no cell phone", ['user_id' => $this->userId]);
            return;
        }

        $today = $smsService->getToday();
        $todayStr = $today->format('Y-m-d');

        // Get current tasks for this user for today
        $tasks = Task::whereJsonContains('user_ids', $this->userId)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $todayStr)
            ->whereDate('end_date', '>=', $todayStr)
            ->with(['project.client'])
            ->get()
            ->filter(function (Task $task) use ($todayStr) {
                $selectedDates = (array) data_get($task->options, 'dates', []);

                if (! empty($selectedDates)) {
                    return in_array($todayStr, $selectedDates);
                }

                return true; // If no specific dates, task spans the range
            });

        if ($tasks->isEmpty()) {
            return;
        }

        $owningVendor = $tasks->first()?->project?->createdByVendor;
        if (! $this->smsEnabledForVendor($owningVendor)) {
            return;
        }

        $vendorTimezone = $owningVendor?->timezone;

        // Check if within business hours
        if (! $smsService->isWithinBusinessHours($vendorTimezone)) {
            $nextStart = $smsService->getNextBusinessHoursStart($vendorTimezone);
            self::dispatch($this->userId)->delay($nextStart);
            return;
        }

        // Check throttle - don't send if recently notified with same content
        $currentHash = SmsLog::generateTasksHash($tasks);
        $throttleMinutes = $smsService->getThrottleMinutes();

        if (SmsLog::wasRecentlyNotified(SmsLog::CHANNEL_TEAM, $this->userId, $throttleMinutes)) {
            $log->info("SendTeamScheduleChangeSms: Throttled, recently notified", [
                'user_id' => $this->userId,
            ]);
            return;
        }

        // Check if tasks actually changed since last notification
        $lastLog = SmsLog::where('channel', SmsLog::CHANNEL_TEAM)
            ->where('user_id', $this->userId)
            ->where('target_date', $todayStr)
            ->latest()
            ->first();

        if ($lastLog && $lastLog->content_hash === $currentHash) {
            $log->info("SendTeamScheduleChangeSms: Tasks unchanged, skipping", [
                'user_id' => $this->userId,
            ]);
            return;
        }

        // Send the notification
        try {
            $user->notify(new TeamTaskSmsNotification($tasks, $today, 'update'));

            // Log each task
            foreach ($tasks as $task) {
                SmsLog::logSent([
                    'channel' => SmsLog::CHANNEL_TEAM,
                    'type' => SmsLog::TYPE_UPDATE,
                    'user_id' => $this->userId,
                    'project_id' => $task->project_id,
                    'task_id' => $task->id,
                    'target_date' => $todayStr,
                    'content_hash' => $currentHash,
                ]);
            }

            // If no tasks, still log the send
            if ($tasks->isEmpty()) {
                SmsLog::logSent([
                    'channel' => SmsLog::CHANNEL_TEAM,
                    'type' => SmsLog::TYPE_UPDATE,
                    'user_id' => $this->userId,
                    'target_date' => $todayStr,
                    'content_hash' => $currentHash,
                ]);
            }

            $log->info("SendTeamScheduleChangeSms: Sent", [
                'user_id' => $this->userId,
                'task_count' => $tasks->count(),
            ]);
        } catch (\Exception $e) {
            $log->error("SendTeamScheduleChangeSms: Failed", [
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
            ]);

            report($e);
        }
    }

    private function smsEnabledForVendor(?\App\Models\Vendor $vendor): bool
    {
        if (! $vendor) {
            return true;
        }

        $baseEnabled = (bool) data_get($vendor->options, 'sms_enabled', true);

        return (bool) data_get($vendor->options, 'sms_team_enabled', $baseEnabled);
    }
}
