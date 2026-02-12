<?php

namespace App\Notifications;

use App\Support\SmsChannel;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Support\ApiErrorFormatter;

class TeamTaskSmsNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $tasks;
    protected $removedTasks;
    protected $date;
    protected $type; // 'reminder' or 'update'

    /**
     * Create a new notification instance.
     *
     * @param  mixed  $tasks  Current tasks for this date
     * @param  mixed  $removedTasks  Tasks removed (only for 'update' type)
     * @param  Carbon  $date  The date these tasks are for
     * @param  string  $type  'reminder' (tomorrow) or 'update' (today changed)
     */
    public function __construct($tasks, Carbon $date, string $type = 'reminder', $removedTasks = [])
    {
        $this->tasks = $tasks;
        $this->removedTasks = $removedTasks;
        $this->date = $date;
        $this->type = $type;
    }

    /**
     * Get the tasks collection.
     *
     * @return mixed
     */
    public function getTasks(): mixed
    {
        return $this->tasks;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable)
    {
        return [SmsChannel::get()];
    }

    /**
     * Get the SMS message content.
     */
    public function toTelnyx($notifiable)
    {
        return $this->type === 'reminder' 
            ? $this->buildReminderMessage($notifiable)
            : $this->buildUpdateMessage($notifiable);
    }

    /**
     * Build the reminder message (tomorrow's tasks).
     */
    private function buildReminderMessage($user)
    {
        $tasks = $this->sortTasksByStartTime($this->tasks, $this->date);

        $taskCount = count($tasks);
        $message = Str::upper(Str::plural('task', $taskCount)) . " for TOMORROW:\n";
        $message .= "{$this->date->format('l, M j, Y')}\n\n";

        foreach ($tasks as $index => $task) {
            $taskNumber = $index + 1;
            $message .= "{$taskNumber}. {$task->title}";

            // Add arrival time if available
            $startTime = $this->formatTaskStartTime($task, $this->date);
            if ($startTime) {
                $message .= " @ {$startTime}";
            }

            $message .= "\n";

            // Add project address
            $fullAddress = strip_tags(str_replace('<br>', "\n", $task->project->full_address));
            $message .= "{$fullAddress}\n";
        }

        // Add schedule link at the bottom
        $dashboardUrl = $this->getDashboardUrl();
        $message .= "\nSchedule: {$dashboardUrl}";

        return $message;
    }

    /**
     * Build the update message (today's tasks changed).
     */
    private function buildUpdateMessage($user)
    {
        $currentTasks = $this->sortTasksByStartTime($this->tasks, $this->date);
        $removedTasks = $this->sortTasksByStartTime($this->removedTasks, $this->date);

        $message = "UPDATED TASKS for TODAY:\n";
        $message .= "{$this->date->format('l, M j, Y')}\n\n";

        // Check if there are no current tasks but there are removed tasks
        if (count($currentTasks) === 0 && count($removedTasks) > 0) {
            $message .= "All tasks for today have been removed or rescheduled.\n";
            return $message;
        }

        // Current tasks
        foreach ($currentTasks as $index => $task) {
            $taskNumber = $index + 1;
            $message .= "{$taskNumber}. {$task->title}";

            // Add arrival time if available
            $startTime = $this->formatTaskStartTime($task, $this->date);
            if ($startTime) {
                $message .= " @ {$startTime}";
            }

            $message .= "\n";

            // Add project address
            $fullAddress = strip_tags(str_replace('<br>', "\n", $task->project->full_address));
            $message .= "{$fullAddress}\n";
        }

        // Removed tasks - uncomment to show removed tasks in the notification
        foreach ($removedTasks as $task) {
            // $message .= "❌ {$task->title} (Removed)\n";
            // $fullAddress = strip_tags(str_replace('<br>', "\n", $task->project->full_address));
            // $message .= "{$fullAddress}\n";
        }

        return $message;
    }

    /**
     * Get the dashboard URL.
     */
    protected function getDashboardUrl(): string
    {
        $devWebhookUrl = config('app.dev_webhook_url');
        $baseUrl = $devWebhookUrl ?: (string) config('app.url');

        return $baseUrl . '/hub';
    }

    /**
     * Sort tasks by their start time for the given date.
     *
     * @param  iterable<int, mixed>  $tasks
     * @return array<int, mixed>
     */
    private function sortTasksByStartTime(iterable $tasks, Carbon $date): array
    {
        $dateKey = $date->format('Y-m-d');

        return collect($tasks)
            ->sort(function ($a, $b) use ($dateKey) {
                $aTime = data_get($a->options, "time_settings.{$dateKey}.start_time");
                $bTime = data_get($b->options, "time_settings.{$dateKey}.start_time");
                $aUseTime = data_get($a->options, "time_settings.{$dateKey}.use_time") === true;
                $bUseTime = data_get($b->options, "time_settings.{$dateKey}.use_time") === true;

                // Tasks with time come before tasks without time
                if ($aUseTime && ! $bUseTime) {
                    return -1;
                }
                if (! $aUseTime && $bUseTime) {
                    return 1;
                }

                // If both have time, sort by time
                if ($aUseTime && $bUseTime) {
                    return strcmp($aTime ?? '', $bTime ?? '');
                }

                // Both have no time, maintain original order
                return 0;
            })
            ->values()
            ->all();
    }

    /**
     * Format task start time for display.
     */
    private function formatTaskStartTime($task, Carbon $date): ?string
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

    /**
     * Handle notification failure.
     */
    public function failed($exception)
    {
        Log::channel('team_sms')->error('Team task SMS notification failed', ApiErrorFormatter::format($exception, [
            'type' => $this->type,
            'current_tasks' => count($this->tasks),
            'removed_tasks' => count($this->removedTasks),
            'date' => $this->date->format('Y-m-d'),
        ]));
    }
}
