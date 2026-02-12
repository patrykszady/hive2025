<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\SmsGroupThread;
use App\Models\Task;
use App\Notifications\ClientScheduleSmsNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TestGroupMmsSms extends Command
{
    protected $signature = 'test:group-mms 
                            {project_id : Project ID to test}
                            {--phones=* : Phone numbers to send to (E.164 format without +, e.g., 18474304439)}
                            {--type=changed : Type of notification: today, tomorrow, or changed}
                            {--name= : Override recipient first name}
                            {--fake-tasks= : Number of fake test tasks to use instead of real ones}
                            {--sms : Send as individual SMS instead of group MMS}
                            {--media= : Add a media URL to the MMS}';

    protected $description = 'Test sending client task update as a group MMS/SMS via Telnyx (sends to specified phone numbers).';

    public function handle(): int
    {
        $projectId = $this->argument('project_id');
        $phones = $this->option('phones');
        $type = $this->option('type');
        $overrideName = $this->option('name');
        $fakeTasks = $this->option('fake-tasks');
        $useSms = $this->option('sms');

        // Default test phone numbers if none provided
        if (empty($phones)) {
            $phones = ['18474304439', '12249993880'];
        }

        // Ensure numbers are in E.164 format
        $phones = array_map(function ($phone) {
            $phone = preg_replace('/[^0-9]/', '', $phone);
            if (!str_starts_with($phone, '1')) {
                $phone = '1' . $phone;
            }
            return '+' . $phone;
        }, $phones);

        $messageType = $useSms ? 'Group SMS' : 'Group MMS';
        $this->info("Testing {$messageType} via Telnyx");
        $this->info('Recipients: ' . implode(', ', $phones));
        $this->newLine();

        $project = Project::with(['client', 'client.users', 'createdByVendor'])->find($projectId);

        if (!$project) {
            $this->error("Project {$projectId} not found.");
            return 1;
        }

        $this->info("Project: {$project->address}");
        $this->info("Client: " . ($project->client?->name ?? 'N/A'));

        // Determine target date based on type
        $targetDate = ($type === 'today' || $type === 'changed') ? Carbon::today() : Carbon::tomorrow();
        $targetDateStr = $targetDate->format('Y-m-d');
        $this->info("Looking for tasks on: {$targetDateStr} ({$type})");

        // Find tasks for the target date
        $tasks = Task::where('project_id', $projectId)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $targetDate)
            ->whereDate('end_date', '>=', $targetDate)
            ->get()
            ->filter(function (Task $task) use ($targetDateStr) {
                $selectedDates = (array) data_get($task->options, 'dates', []);

                if (!empty($selectedDates)) {
                    return in_array($targetDateStr, $selectedDates);
                }

                return $task->start_date->format('Y-m-d') === $targetDateStr;
            });

        if ($tasks->isEmpty()) {
            $this->warn("No tasks found for {$type} ({$targetDateStr}).");
            $this->info('Creating test message with placeholder tasks...');
            $tasks = collect([
                $this->createFakeTask('Plumbing Install', $targetDateStr, '09:00'),
                $this->createFakeTask('Electrical Rough-In', $targetDateStr, '10:30'),
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
        $baseUrl = $devWebhookUrl ?: (string) config('app.url');
        $url = $baseUrl . "/s/{$token}";

        $this->info("Schedule URL: {$url}");

        // Create the notification to get the message content
        $notification = new ClientScheduleSmsNotification(
            $project,
            $recipientFirstName,
            $type,
            $tasks
        );

        // Get the message content
        $message = $notification->toTelnyx($project);

        $this->newLine();
        $this->info('Message content:');
        $this->line('─────────────────────────────────────────────');
        $this->line($message);
        $this->line('─────────────────────────────────────────────');
        $this->info('Message length: ' . strlen($message) . ' characters');

        $this->newLine();
        $this->info("Sending {$messageType} to: " . implode(', ', $phones));

        try {
            if ($useSms) {
                $result = $this->sendGroupSms($phones, $message);
            } else {
                $mediaUrl = $this->option('media');
                $result = $this->sendGroupMms($phones, $message, $mediaUrl);
            }

            $this->newLine();
            $this->info("✅ {$messageType} sent successfully!");
            $this->info("Telnyx Message ID: " . ($result['id'] ?? 'N/A'));

            // Store group thread for reply forwarding (only for group MMS with 2+ recipients)
            if (!$useSms && count($phones) >= 2) {
                $thread = SmsGroupThread::create([
                    'from_number' => config('services.telnyx.from'),
                    'participants' => $phones,
                    'project_id' => $projectId,
                    'telnyx_message_id' => $result['id'] ?? null,
                    'last_activity_at' => now(),
                ]);
                $this->info("Group thread created (ID: {$thread->id}) - replies will be forwarded");
            }

            $this->newLine();
            $this->info("View schedule at: {$url}");

            return 0;
        } catch (\Exception $e) {
            $this->error("❌ Failed to send {$messageType}: " . $e->getMessage());
            Log::channel('client_sms')->error("{$messageType} test failed", [
                'error' => $e->getMessage(),
                'phones' => $phones,
            ]);

            return 1;
        }
    }

    /**
     * Send group MMS via Telnyx API.
     *
     * @param array<string> $recipients Phone numbers in E.164 format
     * @param string $text Message text
     * @param string|null $mediaUrl Optional media URL to attach
     * @return array Response data
     */
    protected function sendGroupMms(array $recipients, string $text, ?string $mediaUrl = null): array
    {
        $apiKey = config('services.telnyx.api_key');
        $from = config('services.telnyx.from');
        $messagingProfileId = config('services.telnyx.messaging_profile_id');

        if (!$apiKey || !$from) {
            throw new \Exception('Telnyx API key or from number not configured');
        }

        $payload = [
            'from' => $from,
            'to' => $recipients,
            'text' => $text,
        ];

        if ($mediaUrl) {
            $payload['media_urls'] = [$mediaUrl];
            $this->info("Including media: {$mediaUrl}");
        } else {
            // Subject only needed when no media - makes it MMS
            $payload['subject'] = 'Task Update';
        }

        if ($messagingProfileId) {
            $payload['messaging_profile_id'] = $messagingProfileId;
        }

        $this->info('Sending to Telnyx group_mms endpoint...');
        $this->line('Payload: ' . json_encode($payload, JSON_PRETTY_PRINT));

        $response = Http::withToken($apiKey)
            ->post('https://api.telnyx.com/v2/messages/group_mms', $payload);

        if ($response->failed()) {
            $error = $response->json();
            throw new \Exception('Telnyx API error: ' . json_encode($error));
        }

        return $response->json('data');
    }

    /**
     * Send group SMS via Telnyx API (sends individual SMS to each recipient).
     * Note: True "group SMS" doesn't exist - SMS is point-to-point.
     * This sends the same message to multiple recipients as separate SMS messages.
     *
     * @param array<string> $recipients Phone numbers in E.164 format
     * @param string $text Message text
     * @return array Response data from last send
     */
    protected function sendGroupSms(array $recipients, string $text): array
    {
        $apiKey = config('services.telnyx.api_key');
        $from = config('services.telnyx.from');
        $messagingProfileId = config('services.telnyx.messaging_profile_id');

        if (!$apiKey || !$from) {
            throw new \Exception('Telnyx API key or from number not configured');
        }

        $this->info('Sending individual SMS to each recipient...');
        $lastResult = [];

        foreach ($recipients as $recipient) {
            $payload = [
                'from' => $from,
                'to' => $recipient,
                'text' => $text,
            ];

            if ($messagingProfileId) {
                $payload['messaging_profile_id'] = $messagingProfileId;
            }

            $this->line("Sending to {$recipient}...");

            $response = Http::withToken($apiKey)
                ->post('https://api.telnyx.com/v2/messages', $payload);

            if ($response->failed()) {
                $error = $response->json();
                $this->error("Failed to send to {$recipient}: " . json_encode($error));
                continue;
            }

            $lastResult = $response->json('data');
            $this->info("  ✓ Sent to {$recipient} - ID: " . ($lastResult['id'] ?? 'N/A'));
        }

        return $lastResult;
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
