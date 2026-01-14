<?php

namespace App\Console\Commands;

use App\Channels\TwilioChannel;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ClientScheduleSmsNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;

class TestClientScheduleSms extends Command
{
    protected $signature = 'test:client-schedule-sms 
                            {project_id : Project ID to test}
                            {--phone= : Override phone number (defaults to dev test number)}
                            {--type=tomorrow : Type of notification: today, tomorrow, or changed}
                            {--name= : Override recipient first name}
                            {--fake-tasks= : Number of fake test tasks to use instead of real ones}';

    protected $description = 'Test client schedule SMS notification (sends to dev number).';

    public function handle(): int
    {
        $projectId = $this->argument('project_id');
        $overridePhone = $this->option('phone');
        $type = $this->option('type'); // 'today' or 'tomorrow'
        $overrideName = $this->option('name');
        $fakeTasks = $this->option('fake-tasks');

        $project = Project::with(['client', 'client.users', 'createdByVendor'])->find($projectId);

        if (! $project) {
            $this->error("Project {$projectId} not found.");

            return 1;
        }

        $this->info("Testing client schedule SMS for project: {$project->address}");
        $this->info("Client: " . ($project->client?->name ?? 'N/A'));

        // Determine target date based on type (changed is always today)
        $targetDate = ($type === 'today' || $type === 'changed') ? Carbon::today() : Carbon::tomorrow();
        $targetDateStr = $targetDate->format('Y-m-d');
        $this->info("Looking for tasks on: {$targetDateStr} ({$type})");

        // Find tasks for the target date
        // Tasks use options->dates array to store selected dates
        $tasks = Task::where('project_id', $projectId)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $targetDate)
            ->whereDate('end_date', '>=', $targetDate)
            ->get()
            ->filter(function (Task $task) use ($targetDateStr) {
                $selectedDates = (array) data_get($task->options, 'dates', []);

                if (! empty($selectedDates)) {
                    return in_array($targetDateStr, $selectedDates);
                }

                // Fallback: single day task using start_date
                return $task->start_date->format('Y-m-d') === $targetDateStr;
            });

        if ($tasks->isEmpty()) {
            $this->warn("No tasks found for {$type} ({$targetDateStr}).");
            $this->info('Creating a test message with placeholder task...');
            $tasks = collect([
                $this->createFakeTask('Example Task', $targetDateStr, '09:00'),
            ]);
        } else {
            $this->info("Found {$tasks->count()} task(s) for {$type}:");
            foreach ($tasks as $task) {
                $this->line("  - {$task->title}");
            }
        }

        // Override with fake tasks if requested
        if ($fakeTasks && (int) $fakeTasks > 0) {
            $fakeTaskNames = ['Plumbing', 'Electrical', 'Drywall', 'Painting', 'Flooring', 'Inspection', 'Trim Work', 'Cleanup'];
            $fakeTimes = ['07:00', '08:00', '09:00', '10:00', '13:00', '14:00', null, null];
            $tasks = collect();

            for ($i = 0; $i < (int) $fakeTasks; $i++) {
                $taskName = $fakeTaskNames[$i % count($fakeTaskNames)];
                $taskTime = $fakeTimes[$i % count($fakeTimes)];
                $tasks->push($this->createFakeTask($taskName, $targetDateStr, $taskTime));
            }

            $this->info("Using {$fakeTasks} fake test task(s):");
            foreach ($tasks as $task) {
                $time = data_get($task->options, "time_settings.{$targetDateStr}.start_time");
                $this->line("  - {$task->title}" . ($time ? " @ {$time}" : ''));
            }
        }

        // Determine recipient first name
        $recipientFirstName = $overrideName
            ?? $project->client?->users?->first()?->first_name
            ?? $project->client?->first_names
            ?? 'there';

        $this->info("Recipient first name: {$recipientFirstName}");

        // Generate token and URL
        $token = $project->getOrCreateScheduleToken();
        $devWebhookUrl = config('app.dev_webhook_url');
        $baseUrl = $devWebhookUrl ?: 'https://dashboard.hive.contractors';
        $url = $baseUrl . "/s/{$token}";

        $this->info("Schedule URL: {$url}");

        // Create the notification
        $notification = new ClientScheduleSmsNotification(
            $project,
            $recipientFirstName,
            $type,
            $tasks
        );

        // Get the message content
        $message = $notification->toTwilio($project);

        $this->newLine();
        $this->info('Message content:');
        $this->line('─────────────────────────────────────────────');
        $this->line($message);
        $this->line('─────────────────────────────────────────────');
        $this->info('Message length: ' . strlen($message) . ' characters');

        // Use dev test number or override
        $testPhone = $overridePhone ?? '2249993880';

        $this->newLine();
        $this->info("Sending to phone: {$testPhone}");

        // Create a mock notifiable with the test phone
        $mockNotifiable = new class($testPhone) {
            public string $phone;

            public function __construct(string $phone)
            {
                $this->phone = $phone;
            }

            public function routeNotificationFor(string $channel): string
            {
                return $this->phone;
            }

            public function routeNotificationForTwilio(): string
            {
                return $this->phone;
            }
        };

        try {
            $channel = app(TwilioChannel::class);
            $channel->send($mockNotifiable, $notification);

            $this->info('✅ SMS sent successfully!');
            $this->newLine();
            $this->info("View schedule at: {$url}");

            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Failed to send SMS: ' . $e->getMessage());

            return 1;
        }
    }

    /**
     * Create a fake task object for testing.
     */
    protected function createFakeTask(string $title, string $date, ?string $startTime = null): object
    {
        $options = (object) [
            'dates' => [$date],
            'time_settings' => (object) [],
        ];

        if ($startTime) {
            $options->time_settings->{$date} = (object) [
                'use_time' => true,
                'start_time' => $startTime,
                'end_time' => $startTime,
            ];
        }

        return (object) [
            'title' => $title,
            'options' => $options,
        ];
    }
}
