<?php

namespace App\Console\Commands;

use App\Models\NotificationSetting;
use App\Models\Project;
use App\Models\SmsLog;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ClientScheduleSmsNotification;
use App\Notifications\TeamTaskSmsNotification;
use App\Notifications\VendorScheduleSmsNotification;
use App\Services\ScheduleSmsService;
use App\Services\SmsScheduleService;
use App\Support\ApiErrorFormatter;
use App\Support\SmsChannel;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class SendScheduleSms extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'schedule:send-sms 
                            {audience : client, vendor, team, or all}
                            {type : today or tomorrow}
                            {--dry-run : Show what would be sent without actually sending}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send scheduled SMS notifications for client, vendor, or team reminders.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $audience = $this->argument('audience');
        $type = $this->argument('type');
        $dryRun = $this->option('dry-run');

        if (! in_array($audience, ['client', 'vendor', 'team', 'all'], true)) {
            $this->error("Invalid audience. Must be 'client', 'vendor', 'team', or 'all'.");

            return Command::FAILURE;
        }

        if (! in_array($type, ['today', 'tomorrow'], true)) {
            $this->error("Invalid type. Must be 'today' or 'tomorrow'.");

            return Command::FAILURE;
        }

        if ($audience === 'team' && $type === 'today') {
            $this->warn('Team reminders are only sent for tomorrow.');

            return Command::SUCCESS;
        }

        if ($audience === 'all') {
            $this->sendClientNotifications($type, $dryRun);
            $this->sendVendorNotifications($type, $dryRun);

            if ($type === 'tomorrow') {
                $this->sendTeamTomorrowReminders($dryRun);
            } else {
                $this->warn('Skipping team reminders for today.');
            }

            return Command::SUCCESS;
        }

        return match ($audience) {
            'client' => $this->sendClientNotifications($type, $dryRun),
            'vendor' => $this->sendVendorNotifications($type, $dryRun),
            'team' => $this->sendTeamTomorrowReminders($dryRun),
            default => Command::SUCCESS,
        };
    }

    private function sendClientNotifications(string $type, bool $dryRun): int
    {
        $scheduleSmsService = app(ScheduleSmsService::class);

        $timezone = 'America/Chicago';
        $targetDate = $type === 'today'
            ? Carbon::today($timezone)
            : Carbon::tomorrow($timezone);
        $targetDateStr = $targetDate->format('Y-m-d');

        $this->info("Sending '{$type}' client notifications for {$targetDateStr} ({$timezone})...");

        if ($dryRun) {
            $this->warn('DRY RUN MODE - no SMS will be sent.');
        }

        $tasks = $scheduleSmsService->getTasksForDate(
            $targetDate,
            ['project', 'project.client', 'project.client.users', 'project.createdByVendor'],
            null,
            true
        );

        $projectsWithTasks = $tasks->groupBy('project_id')->map(function (Collection $tasksForProject) {
            $project = $tasksForProject->first()?->project;

            if (! $project) {
                return null;
            }

            return [
                'project' => $project,
                'tasks' => $tasksForProject,
            ];
        })->filter();

        if ($projectsWithTasks->isEmpty()) {
            $this->info('No projects with tasks on this date.');

            return Command::SUCCESS;
        }

        $this->info("Found {$projectsWithTasks->count()} project(s) with tasks.");

        $sentCount = 0;
        $skippedCount = 0;

        foreach ($projectsWithTasks as $projectData) {
            $project = $projectData['project'];
            $tasks = $projectData['tasks'];

            $clientUsers = $this->getClientUsersWithPhone($project);

            if ($clientUsers->isEmpty()) {
                $this->line("  ⏭ Project #{$project->id}: No client users with phone numbers.");
                $skippedCount++;

                continue;
            }

            foreach ($clientUsers as $user) {
                // Check user-level notification preferences
                $settings = $user->notificationSetting;
                if (! $settings) {
                    $this->line("  ⏭ Project #{$project->id} → {$user->first_name}: No notification settings.");
                    $skippedCount++;

                    continue;
                }

                $settingKey = $type === 'today' ? 'morning_sms' : 'evening_sms';
                if (! $settings->{$settingKey}) {
                    $this->line("  ⏭ Project #{$project->id} → {$user->first_name}: {$settingKey} disabled.");
                    $skippedCount++;

                    continue;
                }

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

                $result = $this->sendClientNotification($project, $user, $type, $tasks, $targetDateStr);

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

        return Command::SUCCESS;
    }

    private function sendVendorNotifications(string $type, bool $dryRun): int
    {
        // Vendor scheduled SMS notifications are currently disabled
        $this->info('Vendor SMS notifications are currently disabled.');

        return Command::SUCCESS;

        $scheduleSmsService = app(ScheduleSmsService::class);

        $timezone = 'America/Chicago';
        $targetDate = $type === 'today'
            ? Carbon::today($timezone)
            : Carbon::tomorrow($timezone);
        $targetDateStr = $targetDate->format('Y-m-d');

        $this->info("Sending '{$type}' vendor notifications for {$targetDateStr} ({$timezone})...");

        if ($dryRun) {
            $this->warn('DRY RUN MODE - no SMS will be sent.');
        }

        $tasks = $scheduleSmsService->getTasksForDate(
            $targetDate,
            ['project', 'vendor', 'owner'],
            function ($query) {
                $query->whereNotNull('vendor_id')
                    ->where(function ($query) {
                        $query->whereNull('vendor_status')
                            ->orWhere('vendor_status', '!=', Task::VENDOR_STATUS_REJECTED);
                    });
            }
        );

        if ($tasks->isEmpty()) {
            $this->info('No vendor tasks found for this date.');

            return Command::SUCCESS;
        }

        $this->info("Found {$tasks->count()} task(s) for vendors.");

        $sentCount = 0;
        $skippedCount = 0;

        $tasksByVendor = $tasks->groupBy('vendor_id');

        foreach ($tasksByVendor as $vendorId => $vendorTasks) {
            $vendor = $vendorTasks->first()?->vendor;

            if (! $vendor) {
                $this->line("  ⏭ Vendor #{$vendorId}: Vendor not found.");
                $skippedCount++;

                continue;
            }

            $adminUsers = $vendor->getAdminUsersWithCellPhones();

            if ($adminUsers->isEmpty()) {
                $this->line("  ⏭ Vendor #{$vendor->id}: No admin users with phone numbers.");
                $skippedCount++;

                continue;
            }

            foreach ($adminUsers as $user) {
                $alreadySent = SmsLog::where('channel', SmsLog::CHANNEL_VENDOR)
                    ->where('type', $type)
                    ->where('user_id', $user->id)
                    ->where('vendor_id', $vendor->id)
                    ->where('target_date', $targetDateStr)
                    ->exists();

                if ($alreadySent) {
                    $this->line("  ⏭ Vendor #{$vendor->id} → {$user->first_name}: Already sent.");
                    $skippedCount++;

                    continue;
                }

                if ($dryRun) {
                    $this->info("  📱 Would send to {$user->first_name} ({$user->cell_phone}): {$vendorTasks->count()} task(s)");
                    $sentCount++;

                    continue;
                }

                try {
                    $user->notify(new VendorScheduleSmsNotification($vendor, $vendorTasks, $targetDate, $type));

                    SmsLog::logSent([
                        'channel' => SmsLog::CHANNEL_VENDOR,
                        'type' => $type,
                        'user_id' => $user->id,
                        'vendor_id' => $vendor->id,
                        'target_date' => $targetDateStr,
                        'content_hash' => SmsLog::generateTasksHash($vendorTasks),
                    ]);

                    $this->info("  ✅ Vendor #{$vendor->id} → {$user->first_name}: Sent!");
                    $sentCount++;
                } catch (\Exception $e) {
                    $this->error("  ❌ Vendor #{$vendor->id} → {$user->first_name}: Failed.");
                    report($e);
                }
            }
        }

        $this->newLine();
        $this->info("Done! Sent: {$sentCount}, Skipped: {$skippedCount}");

        return Command::SUCCESS;
    }

    private function sendTeamTomorrowReminders(bool $dryRun): int
    {
        $smsService = app(SmsScheduleService::class);
        $scheduleSmsService = app(ScheduleSmsService::class);
        $tomorrow = $smsService->getTomorrow();

        $this->info('Sending team reminders for tomorrow...');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - no SMS will be sent.');
        }

        $tasks = $scheduleSmsService->getTasksForDate(
            $tomorrow,
            ['project.client']
        )->filter(function (Task $task) {
            return ! empty($task->user_ids) && is_array($task->user_ids);
        });

        $userTasks = $scheduleSmsService->buildUserTaskMap($tasks, $tomorrow);

        $successCount = 0;
        $errorCount = 0;

        foreach ($userTasks as $userData) {
            $user = $userData['user'];
            $userTaskList = $userData['tasks'];

            try {
                $tomorrowStr = $tomorrow->format('Y-m-d');
                if (SmsLog::wasAlreadySent(SmsLog::CHANNEL_TEAM, SmsLog::TYPE_REMINDER, $user->id, $tomorrowStr)) {
                    continue;
                }

                // Check user-level notification preferences for evening SMS digest
                $settings = $user->notificationSetting;
                if (! $settings || ! $settings->evening_sms) {
                    $this->info("  ⏭ Skipping {$user->first_name}: evening SMS disabled or no settings");
                    continue;
                }

                if ($dryRun) {
                    $this->info("  📱 Would send to {$user->first_name} ({$user->cell_phone}): " . count($userTaskList) . " task(s)");
                    $successCount++;

                    continue;
                }

                if (app()->environment(['production', 'local', 'development'])) {
                    $user->notify(new TeamTaskSmsNotification($userTaskList, $tomorrow, 'reminder'));

                    SmsLog::logSent([
                        'channel' => SmsLog::CHANNEL_TEAM,
                        'type' => SmsLog::TYPE_REMINDER,
                        'user_id' => $user->id,
                        'target_date' => $tomorrowStr,
                    ]);
                }

                $successCount++;
            } catch (\Exception $e) {
                $errorCount++;
                $smsService->getLogger('team')->error('Failed to queue task reminder notification', ApiErrorFormatter::format($e, [
                    'user_id' => $user->id,
                    'user_name' => $user->full_name,
                    'phone' => $user->cell_phone,
                ]));
            }
        }

        $this->info('Task reminders sent for tomorrow');
        $this->info('Total tasks: ' . $tasks->count());
        $this->info('Users notified: ' . $successCount);

        if ($errorCount > 0) {
            $this->warn("Errors encountered: {$errorCount}");
        }

        return Command::SUCCESS;
    }

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

    protected function sendClientNotification(
        Project $project,
        User $user,
        string $type,
        Collection $tasks,
        string $targetDateStr
    ): bool {
        try {
            $notification = new ClientScheduleSmsNotification(
                $project,
                $user->nickname ?: ($user->first_name ?? 'there'),
                $type,
                $tasks
            );

            $channel = app(SmsChannel::get());
            $channel->send($user, $notification);

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
