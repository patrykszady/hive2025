<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\Task;
use App\Support\SmsChannel;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClientScheduleSmsNotification extends Notification implements ShouldQueue
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
        return [SmsChannel::get()];
    }

    /**
     * Get the SMS message content.
     */
    public function toTelnyx(object $notifiable): string
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
            'today' => "Upcoming {$taskWord} for today:",
            'tomorrow' => "Upcoming {$taskWord} for tomorrow:",
            'changed' => "Your project schedule for today has been updated:",
            default => "Upcoming {$taskWord}:",
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
        // Use DEV_WEBHOOK_URL in dev, APP_URL in prod
        $devWebhookUrl = config('app.dev_webhook_url');
        $baseUrl = $devWebhookUrl ?: (string) config('app.url');

        $token = $project->getOrCreateScheduleToken();

        // Use short /s/{token} route
        return app(\App\Services\UrlShortener::class)->shorten($baseUrl . "/s/{$token}");
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
     * Get the vendor's timezone for this project.
     */
    protected function getVendorTimezone(): string
    {
        $vendor = $this->project->createdByVendor;

        if ($vendor && is_string($vendor->timezone) && $vendor->timezone !== '') {
            return $vendor->timezone;
        }

        return (string) config('app.timezone');
    }

    /**
     * Format task date and arrival time for SMS display.
     */
    protected function formatTaskDateTime(object $task): ?string
    {
        $tz = $this->getVendorTimezone();

        // Determine target date from type (changed is always today)
        $targetDate = ($this->type === 'today' || $this->type === 'changed')
            ? Carbon::today($tz)
            : Carbon::tomorrow($tz);

        // Get the actual scheduled date(s) from task options
        $selectedDates = (array) data_get($task->options, 'dates', []);
        $targetDateStr = $targetDate->format('Y-m-d');

        // Find which date in the task matches our target day
        $matchingDateStr = null;
        foreach ($selectedDates as $dateStr) {
            if ($dateStr === $targetDateStr) {
                $matchingDateStr = $dateStr;
                break;
            }
        }

        // Fallback to task start_date if no matching date found in options
        if (! $matchingDateStr && $task->start_date) {
            $matchingDateStr = Carbon::parse($task->start_date)->format('Y-m-d');
        }

        if (! $matchingDateStr) {
            return null;
        }

        // Format date nicely (e.g., "Wed, Jan 14")
        $dateLabel = Carbon::parse($matchingDateStr, $tz)->format('D, M j');

        // Try to get arrival time from task options->time_settings for this specific date
        $arrivalTime = null;
        if (isset($task->options)) {
            $options = is_object($task->options) ? $task->options : (object) $task->options;
            $timeSettings = $options->time_settings ?? null;

            if ($timeSettings) {
                $daySettings = is_object($timeSettings)
                    ? ($timeSettings->{$matchingDateStr} ?? null)
                    : ($timeSettings[$matchingDateStr] ?? null);

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
