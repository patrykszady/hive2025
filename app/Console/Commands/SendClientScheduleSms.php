<?php

namespace App\Console\Commands;

use App\Channels\TwilioChannel;
use App\Models\Project;
use App\Models\SmsLog;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ClientScheduleSmsNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class SendClientScheduleSms extends Command
{
    protected $signature = 'client:send-schedule-sms 
                            {type : Type of notification: today or tomorrow}
                            {--dry-run : Show what would be sent without actually sending}';

    protected $description = 'Send scheduled client SMS notifications for today or tomorrow tasks.';

    public function handle(): int
    {
        $type = $this->argument('type');
        $dryRun = $this->option('dry-run');

        if (! in_array($type, ['today', 'tomorrow'])) {
            $this->error("Invalid type. Must be 'today' or 'tomorrow'.");

            return 1;
        }

        // Use Central timezone for date calculations
        $timezone = 'America/Chicago';
        $targetDate = $type === 'today' 
            ? Carbon::today($timezone) 
            : Carbon::tomorrow($timezone);
        $targetDateStr = $targetDate->format('Y-m-d');

        $this->info("Sending '{$type}' notifications for {$targetDateStr} ({$timezone})...");

        if ($dryRun) {
            $this->warn('DRY RUN MODE - no SMS will be sent.');
        }

        // Get all projects with tasks on the target date
        $projectsWithTasks = $this->getProjectsWithTasksOnDate($targetDateStr);

        if ($projectsWithTasks->isEmpty()) {
            $this->info('No projects with tasks on this date.');

            return 0;
        }

        $this->info("Found {$projectsWithTasks->count()} project(s) with tasks.");

        $sentCount = 0;
        $skippedCount = 0;

        foreach ($projectsWithTasks as $projectData) {
            $project = $projectData['project'];
            $tasks = $projectData['tasks'];

            // Get client users with phone numbers
            $clientUsers = $this->getClientUsersWithPhone($project);

            if ($clientUsers->isEmpty()) {
                $this->line("  ⏭ Project #{$project->id}: No client users with phone numbers.");
                $skippedCount++;

                continue;
            }

            foreach ($clientUsers as $user) {
                // Check if already sent
                if (SmsLog::wasAlreadySent(SmsLog::CHANNEL_CLIENT, $type, $user->id, $targetDateStr, $project->id)) {
                    $this->line("  ⏭ Project #{$project->id} → {$user->first_name}: Already sent.");
                    $skippedCount++;

                    continue;
                }

                if ($dryRun) {
                    $this->info("  📱 Would send to {$user->first_name} ({$user->cell_phone}): {$tasks->count()} task(s)");
                    $sentCount++;

                    continue;
                }

                // Send the notification
                $result = $this->sendNotification($project, $user, $type, $tasks, $targetDateStr);

                if ($result) {
                    $this->info("  ✅ Project #{$project->id} → {$user->first_name}: Sent!");
                    $sentCount++;
                } else {
                    $this->error("  ❌ Project #{$project->id} → {$user->first_name}: Failed.");
                }
            }
        }

        $this->newLine();
        $this->info("Done! Sent: {$sentCount}, Skipped: {$skippedCount}");

        return 0;
    }

    /**
     * Get all projects that have tasks scheduled on the target date.
     */
    protected function getProjectsWithTasksOnDate(string $targetDateStr): Collection
    {
        $projects = Project::with(['client', 'client.users', 'createdByVendor'])
            ->whereHas('tasks', function ($query) use ($targetDateStr) {
                $query->whereNotNull('start_date')
                    ->whereNotNull('end_date')
                    ->whereDate('start_date', '<=', $targetDateStr)
                    ->whereDate('end_date', '>=', $targetDateStr);
            })
            ->get();

        return $projects->map(function (Project $project) use ($targetDateStr) {
            // Get tasks for this date
            $tasks = Task::where('project_id', $project->id)
                ->whereNotNull('start_date')
                ->whereNotNull('end_date')
                ->whereDate('start_date', '<=', $targetDateStr)
                ->whereDate('end_date', '>=', $targetDateStr)
                ->get()
                ->filter(function (Task $task) use ($targetDateStr) {
                    $selectedDates = (array) data_get($task->options, 'dates', []);

                    if (! empty($selectedDates)) {
                        return in_array($targetDateStr, $selectedDates);
                    }

                    return $task->start_date->format('Y-m-d') === $targetDateStr;
                });

            if ($tasks->isEmpty()) {
                return null;
            }

            return [
                'project' => $project,
                'tasks' => $tasks,
            ];
        })->filter();
    }

    /**
     * Get client users with phone numbers for a project.
     */
    protected function getClientUsersWithPhone(Project $project): Collection
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
        string $type,
        Collection $tasks,
        string $targetDateStr
    ): bool {
        try {
            $notification = new ClientScheduleSmsNotification(
                $project,
                $user->first_name ?? 'there',
                $type,
                $tasks
            );

            // Use the user directly - it has routeNotificationForTwilio() method
            $channel = app(TwilioChannel::class);
            $channel->send($user, $notification);

            // Log the send
            SmsLog::logSent([
                'channel' => SmsLog::CHANNEL_CLIENT,
                'type' => $type,
                'user_id' => $user->id,
                'project_id' => $project->id,
                'target_date' => $targetDateStr,
                'content_hash' => SmsLog::generateTasksHash($tasks),
            ]);

            return true;
        } catch (\Exception $e) {
            report($e);

            return false;
        }
    }
}
