<?php

namespace App\Notifications;

use App\Channels\TwilioChannel;
use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VendorAvailabilityNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @var Collection<int, Task>|Task */
    public Collection|Task $tasks;

    /** @var array<int, string>|string */
    public array|string $tokens;

    /**
     * Create a new notification instance.
     *
     * @param  Collection<int, Task>|Task  $tasks  Single task or collection of tasks
     * @param  array<int, string>|string  $tokens  Single token or map of task_id => token
     */
    public function __construct(Collection|Task $tasks, array|string $tokens)
    {
        $this->tasks = $tasks;
        $this->tokens = $tokens;
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
        // Handle both single task and collection of tasks
        if ($this->tasks instanceof Task) {
            return $this->formatSingleTaskMessage($notifiable);
        }

        if ($this->tasks->count() === 1) {
            return $this->formatSingleTaskMessage($notifiable, $this->tasks->first());
        }

        return $this->formatMultiTaskMessage($notifiable);
    }

    /**
     * Format message for a single task.
     */
    protected function formatSingleTaskMessage(object $notifiable, ?Task $task = null): string
    {
        $task = $task ?? $this->tasks;
        $token = is_array($this->tokens) ? ($this->tokens[$task->id] ?? reset($this->tokens)) : $this->tokens;

        $vendorName = $task->vendor?->short_name ?? $task->vendor?->name ?? 'Hi';
        $ownerName = $task->owner?->short_name ?? $task->owner?->name ?? 'Your contractor';
        $project = $task->project;

        $address = $this->formatFullAddress($project);

        $dateRange = $this->formatDateWithTime($task);

        $baseUrl = config('app.dev_webhook_url') ?: config('app.url');
        $responseUrl = $baseUrl . "/vendor/availability/{$token}";

        // Try to shorten the URL
        $shortUrl = $this->shortenUrl($responseUrl) ?? $responseUrl;

        // Strip https:// to avoid rich link preview on iOS
        $displayUrl = preg_replace('#^https?://#', '', $shortUrl);

        return "{$vendorName}\n"
            . "{$ownerName} assigned\n"
            . "\"{$task->title}\"\n"
            . "{$address}\n"
            . "📅 {$dateRange}\n"
            . "\n"
            . "Tap to respond 👇\n"
            . "{$displayUrl}";
    }

    /**
     * Format message for multiple tasks.
     */
    protected function formatMultiTaskMessage(object $notifiable): string
    {
        $firstTask = $this->tasks->first();
        $vendorName = $firstTask->vendor?->short_name ?? $firstTask->vendor?->name ?? 'Hi';
        $ownerName = $firstTask->owner?->short_name ?? $firstTask->owner?->name ?? 'Your contractor';

        $lines = [
            "{$vendorName}",
            "{$ownerName} assigned {$this->tasks->count()} tasks:",
            "",
        ];

        foreach ($this->tasks as $task) {
            $project = $task->project;
            $address = $this->formatFullAddress($project);

            $dateRange = $this->formatDateWithTime($task);

            $lines[] = "\"{$task->title}\"";
            $lines[] = $address;
            $lines[] = "📅 {$dateRange}";
            $lines[] = "";
        }

        // Use first task's token - the page shows all pending tasks for this vendor
        $firstToken = $this->tokens[$firstTask->id] ?? reset($this->tokens);
        $baseUrl = config('app.dev_webhook_url') ?: config('app.url');
        $responseUrl = $baseUrl . "/vendor/availability/{$firstToken}";
        $shortUrl = $this->shortenUrl($responseUrl) ?? $responseUrl;
        $displayUrl = preg_replace('#^https?://#', '', $shortUrl);

        $lines[] = "Tap to respond 👇";
        $lines[] = $displayUrl;

        return implode("\n", $lines);
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
     * Format date range with optional time from task options.
     * Outputs: "Jan 13" or "Jan 13 @ 7AM" or "Jan 13 - Jan 15"
     */
    protected function formatDateWithTime(Task $task): string
    {
        $startDate = $task->start_date;
        $endDate = $task->end_date;
        $hasMultipleDays = $endDate && ! $startDate->eq($endDate);

        // Check for time settings in options (may be object or array)
        $options = $task->options;
        $timeSettings = is_object($options) ? ($options->time_settings ?? null) : ($options['time_settings'] ?? null);
        $dateKey = $startDate->format('Y-m-d');
        $startTime = null;

        if ($timeSettings) {
            $daySettings = is_object($timeSettings) ? ($timeSettings->$dateKey ?? null) : ($timeSettings[$dateKey] ?? null);
            if ($daySettings) {
                $useTime = is_object($daySettings) ? ($daySettings->use_time ?? false) : ($daySettings['use_time'] ?? false);
                if ($useTime) {
                    $startTime = is_object($daySettings) ? ($daySettings->start_time ?? null) : ($daySettings['start_time'] ?? null);
                }
            }
        }

        // Format base date
        $dateStr = $startDate->format('M j');

        // Add time if available and single day
        if ($startTime && ! $hasMultipleDays) {
            // Convert "07:00" to "7AM" format
            $timeFormatted = \Carbon\Carbon::createFromFormat('H:i', $startTime)->format('gA');
            $dateStr .= " @ {$timeFormatted}";
        }

        // Add end date for multi-day tasks
        if ($hasMultipleDays) {
            $dateStr .= ' - ' . $endDate->format('M j');
        }

        return $dateStr;
    }

    /**
     * Shorten a URL using TinyURL API.
     */
    protected function shortenUrl(string $url): ?string
    {
        try {
            $response = Http::timeout(5)->get('https://tinyurl.com/api-create.php', [
                'url' => $url,
            ]);

            if ($response->successful()) {
                $shortUrl = $response->body();
                
                Log::channel('vendor_sms')->info("URL shortened", [
                    'original_url' => $url,
                    'short_url' => $shortUrl,
                ]);
                
                return $shortUrl;
            }
            
            Log::channel('vendor_sms')->warning("TinyURL API returned non-success", [
                'original_url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Exception $e) {
            Log::channel('vendor_sms')->error("TinyURL shortening failed", [
                'original_url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        if ($this->tasks instanceof Task) {
            return [
                'task_id' => $this->tasks->id,
                'token' => $this->tokens,
            ];
        }

        return [
            'task_ids' => $this->tasks->pluck('id')->toArray(),
            'tokens' => $this->tokens,
        ];
    }
}
