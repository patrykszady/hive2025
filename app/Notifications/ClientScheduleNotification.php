<?php

namespace App\Notifications;

use App\Channels\TwilioChannel;
use App\Models\Project;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClientScheduleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public Project $project;

    public string $recipientFirstName;

    public string $type; // 'today' or 'tomorrow'

    /** @var Collection<int, Task> */
    public Collection $tasks;

    /**
     * Create a new notification instance.
     *
     * @param  Collection<int, Task>  $tasks
     */
    public function __construct(
        Project $project,
        string $recipientFirstName,
        string $type,
        Collection $tasks
    ) {
        $this->project = $project;
        $this->recipientFirstName = $recipientFirstName;
        $this->type = $type;
        $this->tasks = $tasks;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [TwilioChannel::class];
    }

    /**
     * Get the SMS message content.
     */
    public function toTwilio(object $notifiable): string
    {
        $project = $this->project;
        $owner = $project->createdByVendor;

        $ownerName = $owner?->short_name ?? $owner?->name ?? 'Your contractor';

        $url = $this->clientScheduleUrl($project);

        // Build task list
        $taskCount = $this->tasks->count();
        $taskWord = $taskCount === 1 ? 'task' : 'tasks';

        // Determine the intro line based on type
        $introLine = match ($this->type) {
            'today' => "Your project upcoming {$taskWord} for today:",
            'tomorrow' => "Your project upcoming {$taskWord} for tomorrow:",
            'changed' => "Your project schedule for today has been updated:",
            default => "Your project upcoming {$taskWord}:",
        };

        $taskLines = $this->tasks->map(function ($task) {
            $line = '- ' . ($task->title ?? 'Task');

            // Add date and arrival time if available
            $dateInfo = $this->formatTaskDateTime($task);
            if ($dateInfo) {
                $line .= "\n  " . $dateInfo;
            }

            return $line;
        })->implode("\n");

        $vendorName = $owner?->short_name ?? $owner?->name ?? '';

        return "Hi {$this->recipientFirstName},\n"
            . "{$introLine}\n"
            . "\n"
            . "{$taskLines}\n"
            . "\n"
            . "View Schedule: {$url}\n"
            . $vendorName;
    }

    /**
     * Generate the client schedule URL.
     */
    protected function clientScheduleUrl(Project $project): string
    {
        // Use ngrok in dev, dashboard.hive.contractors in prod
        $devWebhookUrl = config('app.dev_webhook_url');
        $baseUrl = $devWebhookUrl ?: 'https://dashboard.hive.contractors';

        $token = $project->getOrCreateScheduleToken();

        // Use short /s/{token} route
        return $baseUrl . "/s/{$token}";
    }

    /**
     * Format full address including city, state, and zip.
     */
    protected function formatFullAddress(?object $project): string
    {
        if (! $project) {
            return 'a project';
        }

        $lines = [];

        if ($project->address) {
            $lines[] = $project->address;
        }

        $cityStateZip = collect([
            $project->city,
            $project->state,
            $project->zip_code,
        ])->filter()->implode(', ');

        if ($cityStateZip) {
            $lines[] = $cityStateZip;
        }

        return ! empty($lines) ? implode("\n", $lines) : 'a project';
    }

    /**
     * Format task date and arrival time for SMS display.
     */
    protected function formatTaskDateTime(object $task): ?string
    {
        // Determine target date from type (changed is always today)
        $targetDate = ($this->type === 'today' || $this->type === 'changed')
            ? Carbon::today()
            : Carbon::tomorrow();
        $targetDateStr = $targetDate->format('Y-m-d');

        // Format date nicely (e.g., "Wed, Jan 14")
        $dateLabel = $targetDate->format('D, M j');

        // Try to get arrival time from task options
        $arrivalTime = null;
        if (isset($task->options)) {
            $options = is_object($task->options) ? $task->options : (object) $task->options;
            $timeSettings = $options->time_settings ?? null;

            if ($timeSettings) {
                $daySettings = is_object($timeSettings)
                    ? ($timeSettings->{$targetDateStr} ?? null)
                    : ($timeSettings[$targetDateStr] ?? null);

                if ($daySettings) {
                    $useTime = is_object($daySettings)
                        ? ($daySettings->use_time ?? false)
                        : ($daySettings['use_time'] ?? false);
                    $startTime = is_object($daySettings)
                        ? ($daySettings->start_time ?? null)
                        : ($daySettings['start_time'] ?? null);

                    if ($useTime && $startTime) {
                        try {
                            $arrivalTime = Carbon::createFromFormat('H:i', $startTime)->format('g:i A');
                        } catch (\Exception $e) {
                            // Ignore invalid time format
                        }
                    }
                }
            }
        }

        if ($arrivalTime) {
            return "{$dateLabel} @ {$arrivalTime}";
        }

        return $dateLabel;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'project_id' => $this->project->id,
        ];
    }
}
