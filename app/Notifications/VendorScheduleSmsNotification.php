<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\Vendor;
use App\Support\SmsChannel;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class VendorScheduleSmsNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @var Collection<int, Task> */
    public Collection $tasks;

    /**
     * Create a new notification instance.
     *
     * @param Collection<int, Task> $tasks
     */
    public function __construct(
        public Vendor $vendor,
        Collection $tasks,
        public Carbon $date,
        public string $type
    ) {
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

    public function toTelnyx(object $notifiable): string
    {
        $header = $this->type === 'today' ? 'TODAY' : 'TOMORROW';
        $vendorName = $this->vendor->short_name ?? $this->vendor->name ?? 'Hi';

        $lines = [
            $vendorName,
            "TASKS for {$header}:",
            $this->date->format('l, M j, Y'),
            '',
        ];

        foreach ($this->tasks as $index => $task) {
            $taskNumber = $index + 1;
            $lines[] = "{$taskNumber}. {$task->title}";

            $startTime = $this->formatTaskStartTime($task, $this->date);
            if ($startTime) {
                $lines[] = "@ {$startTime}";
            }

            $lines[] = $this->formatFullAddress($task->project);
            $lines[] = '';
        }

        $lines[] = 'Details: ' . $this->vendorAvailabilityUrl();

        return implode("\n", $lines);
    }

    protected function vendorAvailabilityUrl(): string
    {
        $devWebhookUrl = config('app.dev_webhook_url');
        $baseUrl = $devWebhookUrl ?: (string) config('app.url');

        $token = $this->vendor->getOrCreateAvailabilityToken();

        return app(\App\Services\UrlShortener::class)->shorten($baseUrl . "/v/{$token}");
    }

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

    protected function formatTaskStartTime(Task $task, Carbon $date): ?string
    {
        $dateKey = $date->format('Y-m-d');
        $time = data_get($task->options, "time_settings.{$dateKey}.start_time");
        $useTime = data_get($task->options, "time_settings.{$dateKey}.use_time") === true;

        if (! $useTime || ! is_string($time) || $time === '') {
            return null;
        }

        try {
            $parsed = Carbon::createFromFormat('H:i', $time);

            return $parsed->format('g:i A');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
