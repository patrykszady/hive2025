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

/**
 * Text the homeowner the scheduled service-call tasks once the contractor has
 * set times. Dispatched (and batched) by SendServiceCallScheduledSmsToClient.
 */
class ClientServiceScheduledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  Collection<int, Task>  $tasks
     */
    public function __construct(
        public Project $project,
        public string $recipientFirstName,
        public Collection $tasks,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [SmsChannel::get()];
    }

    public function toTelnyx(object $notifiable): string
    {
        $owner = $this->project->createdByVendor;
        $vendorName = trim((string) ($owner?->short_name ?? $owner?->name ?? ''));

        $taskLines = $this->tasks
            ->flatMap(fn (Task $task): array => $this->taskLines($task))
            ->all();

        $lines = [
            "Hi {$this->recipientFirstName},",
            'Your service has been scheduled:',
            '',
            ...$taskLines,
            '',
            'View Schedule: ' . $this->scheduleUrl(),
        ];

        if ($vendorName !== '') {
            $lines[] = $vendorName;
        }

        return implode("\n", $lines);
    }

    /**
     * One line per scheduled date for the task.
     *
     * @return array<int, string>
     */
    protected function taskLines(Task $task): array
    {
        $dates = (array) data_get($task->options, 'dates', []);

        if ($dates === [] && $task->start_date) {
            $dates = [$task->start_date->format('Y-m-d')];
        }

        $title = trim((string) ($task->title ?? 'Task'));

        return collect($dates)
            ->filter(fn ($date) => is_string($date) && $date !== '')
            ->unique()
            ->sort()
            ->map(function (string $date) use ($task, $title): string {
                $label = Carbon::parse($date)->format('D, M j');
                $arrival = $task->getArrivalTimeLabel($date);

                return $arrival
                    ? "- {$title} · {$label} @ {$arrival}"
                    : "- {$title} · {$label}";
            })
            ->values()
            ->all();
    }

    protected function scheduleUrl(): string
    {
        $devWebhookUrl = config('app.dev_webhook_url');
        $baseUrl = $devWebhookUrl ?: (string) config('app.url');

        $token = $this->project->getOrCreateScheduleToken();

        return app(\App\Services\UrlShortener::class)->shorten($baseUrl . "/s/{$token}");
    }
}
