<?php

namespace App\Jobs;

use App\Channels\TwilioChannel;
use App\Models\ClientScheduleSmsLog;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ClientScheduleNotification;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendClientScheduleChangeSms implements ShouldQueue
{
    use Queueable;

    public int $projectId;

    /**
     * Throttle window in minutes - don't send more than one "changed" SMS per client per project.
     */
    protected int $throttleMinutes = 30;

    /**
     * Business hours (in project's vendor timezone).
     */
    protected int $businessHourStart = 8;  // 8 AM
    protected int $businessHourEnd = 18;   // 6 PM

    /**
     * Create a new job instance.
     */
    public function __construct(int $projectId)
    {
        $this->projectId = $projectId;
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $log = Log::channel('vendor_sms');

        $project = Project::with(['client', 'client.users', 'createdByVendor'])->find($this->projectId);

        if (! $project) {
            $log->warning("SendClientScheduleChangeSms: Project not found", ['project_id' => $this->projectId]);

            return;
        }

        // Check if within business hours (using vendor's timezone)
        $timezone = $project->createdByVendor?->timezone ?? config('app.timezone');
        $now = Carbon::now($timezone);

        if (! $this->isBusinessHours($now)) {
            $log->info("SendClientScheduleChangeSms: Outside business hours, skipping", [
                'project_id' => $this->projectId,
                'hour' => $now->hour,
                'timezone' => $timezone,
            ]);

            return;
        }

        $todayStr = Carbon::today($timezone)->format('Y-m-d');

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

        $currentHash = ClientScheduleSmsLog::generateTasksHash($tasks);

        foreach ($clientUsers as $user) {
            // Check throttle - don't send if recently notified
            if (ClientScheduleSmsLog::wasRecentlyNotified($this->projectId, $user->id, $this->throttleMinutes)) {
                $log->info("SendClientScheduleChangeSms: Throttled, recently notified", [
                    'project_id' => $this->projectId,
                    'user_id' => $user->id,
                ]);

                continue;
            }

            // Check if tasks actually changed since last notification
            $lastLog = ClientScheduleSmsLog::where('project_id', $this->projectId)
                ->where('user_id', $user->id)
                ->where('target_date', $todayStr)
                ->latest()
                ->first();

            if ($lastLog && $lastLog->tasks_hash === $currentHash) {
                $log->info("SendClientScheduleChangeSms: Tasks unchanged, skipping", [
                    'project_id' => $this->projectId,
                    'user_id' => $user->id,
                ]);

                continue;
            }

            // Send the notification
            $this->sendNotification($project, $user, $tasks, $todayStr, $currentHash);
        }
    }

    /**
     * Check if current time is within business hours.
     */
    protected function isBusinessHours(Carbon $now): bool
    {
        $hour = $now->hour;
        $dayOfWeek = $now->dayOfWeek;

        // Skip weekends
        if ($dayOfWeek === Carbon::SATURDAY || $dayOfWeek === Carbon::SUNDAY) {
            return false;
        }

        return $hour >= $this->businessHourStart && $hour < $this->businessHourEnd;
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
        string $tasksHash
    ): void {
        $log = Log::channel('vendor_sms');

        try {
            $notification = new ClientScheduleNotification(
                $project,
                $user->first_name ?? 'there',
                'changed',
                $tasks
            );

            // Use the user directly - it has routeNotificationForTwilio() method
            $channel = app(TwilioChannel::class);
            $channel->send($user, $notification);

            // Log the send
            ClientScheduleSmsLog::create([
                'project_id' => $project->id,
                'user_id' => $user->id,
                'type' => 'changed',
                'target_date' => $todayStr,
                'tasks_hash' => $tasksHash,
            ]);

            $log->info("SendClientScheduleChangeSms: Sent successfully", [
                'project_id' => $project->id,
                'user_id' => $user->id,
                'user_phone' => $user->phone,
                'tasks_count' => $tasks->count(),
            ]);
        } catch (\Exception $e) {
            $log->error("SendClientScheduleChangeSms: Failed to send", [
                'project_id' => $project->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            report($e);
        }
    }
}
