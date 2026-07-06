<?php

namespace App\Notifications;

use App\Models\Project;
use App\Support\SmsChannel;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ClientServiceAvailabilityNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, array{date: string, time: string}>  $slots
     */
    public function __construct(
        public Project $project,
        public array $slots,
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
        $project = $this->project;
        $clientName = trim((string) ($project->client?->name ?? 'A homeowner'));
        $address = trim((string) ($project->address ?? ''));

        $lines = [
            "{$clientName} shared preferred service times:",
        ];

        if ($address !== '') {
            $lines[] = $address;
        }

        $lines[] = '';

        foreach ($this->slots as $slot) {
            $date = Carbon::parse($slot['date'])->format('D, M j');
            $lines[] = "- {$date} · {$slot['time']}";
        }

        $lines[] = '';
        $lines[] = 'View: ' . $this->scheduleUrl();

        return implode("\n", $lines);
    }

    protected function scheduleUrl(): string
    {
        $devWebhookUrl = config('app.dev_webhook_url');
        $baseUrl = $devWebhookUrl ?: (string) config('app.url');

        $token = $this->project->getOrCreateScheduleToken();

        return app(\App\Services\UrlShortener::class)->shorten($baseUrl . "/s/{$token}");
    }
}
