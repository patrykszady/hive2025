<?php

namespace App\Notifications;

use App\Channels\TwilioChannel;
use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

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

        $vendorName = $notifiable->short_name ?? $notifiable->name ?? 'there';
        $ownerName = $task->owner?->short_name ?? $task->owner?->name ?? 'Your contractor';
        $project = $task->project;

        $address = $this->formatFullAddress($project);

        $dateRange = $task->start_date->format('M j');
        if ($task->end_date && ! $task->start_date->eq($task->end_date)) {
            $dateRange .= ' - ' . $task->end_date->format('M j');
        }

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
        $vendorName = $notifiable->short_name ?? $notifiable->name ?? 'there';
        $firstTask = $this->tasks->first();
        $ownerName = $firstTask->owner?->short_name ?? $firstTask->owner?->name ?? 'Your contractor';

        $lines = [
            "{$vendorName}",
            "{$ownerName} assigned {$this->tasks->count()} tasks:",
            "",
        ];

        foreach ($this->tasks as $task) {
            $project = $task->project;
            $address = $this->formatFullAddress($project);

            $dateRange = $task->start_date->format('M j');
            if ($task->end_date && ! $task->start_date->eq($task->end_date)) {
                $dateRange .= ' - ' . $task->end_date->format('M j');
            }

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
     * Shorten a URL using TinyURL API.
     */
    protected function shortenUrl(string $url): ?string
    {
        try {
            $response = Http::timeout(5)->get('https://tinyurl.com/api-create.php', [
                'url' => $url,
            ]);

            if ($response->successful()) {
                return $response->body();
            }
        } catch (\Exception $e) {
            // Silently fail and return original URL
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
