<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\Task;
use App\Support\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Notification;

class VendorClientTimesRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  Collection<int, Task>  $tasks  Pending tasks assigned to the vendor
     */
    public function __construct(
        public Collection $tasks,
        public Project $project,
    ) {}

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
        $vendorName = $this->tasks->first()?->vendor?->short_name
            ?? $this->tasks->first()?->vendor?->name
            ?? 'Hi';

        $clientName = trim((string) ($this->project->client?->name ?? 'A homeowner'));
        $address = trim((string) ($this->project->address ?? ''));

        $lines = [
            $vendorName,
            "{$clientName} shared preferred times. Pick when you're available:",
            '',
        ];

        foreach ($this->tasks as $task) {
            $lines[] = "- \"{$task->title}\"";
        }

        if ($address !== '') {
            $lines[] = $address;
        }

        $lines[] = '';
        $lines[] = 'Select: ' . $this->vendorAvailabilityUrl();

        return implode("\n", $lines);
    }

    protected function vendorAvailabilityUrl(): string
    {
        $vendor = $this->tasks->first()?->vendor;

        $devWebhookUrl = config('app.dev_webhook_url');
        $baseUrl = $devWebhookUrl ?: (string) config('app.url');

        if (! $vendor) {
            return $baseUrl . '/v';
        }

        $token = $vendor->getOrCreateAvailabilityToken();

        return app(\App\Services\UrlShortener::class)->shorten($baseUrl . "/v/{$token}");
    }
}
