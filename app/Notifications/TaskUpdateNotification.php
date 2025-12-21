<?php

namespace App\Notifications;

use App\Channels\TwilioChannel;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Support\ApiErrorFormatter;

class TaskUpdateNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $currentTasks;
    protected $removedTasks;
    protected $date;

    public function __construct($currentTasks, $removedTasks, Carbon $date)
    {
        $this->currentTasks = $currentTasks;
        $this->removedTasks = $removedTasks;
        $this->date = $date;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable)
    {
        return [TwilioChannel::class];
    }

    /**
     * Get the SMS message content
     */
    public function toTwilio($notifiable)
    {
        return $this->buildMessage($notifiable);
    }

    /**
     * Build the SMS message content
     */
    private function buildMessage($user)
    {
        $currentTasks = $this->sortTasksByStartTime($this->currentTasks, $this->date);
        $removedTasks = $this->sortTasksByStartTime($this->removedTasks, $this->date);

        $message = "UPDATED TASKS for TODAY:\n";
        $message .= "{$this->date->format('l, M j, Y')}\n\n";

        // Check if there are no current tasks but there are removed tasks
        if (count($currentTasks) === 0 && count($removedTasks) > 0) {
            $message .= "You no longer have any tasks scheduled for today.\n";
        }

        // Current tasks
        foreach ($currentTasks as $index => $task) {
            if ($index > 0) {
                $message .= "\n";
            }

            $startTime = $this->formatTaskStartTime($task, $this->date);
            $message .= ($startTime ? "{$startTime} - " : '')."{$task->title}\n";
            $message .= "{$task->project->client->name}\n";

            $fullAddress = strip_tags(str_replace('<br>', "\n", $task->project->full_address));
            $message .= "{$fullAddress}\n";
        }

        // Removed tasks - uncomment to show removed tasks in the notification
        foreach ($removedTasks as $task) {
            $startTime = $this->formatTaskStartTime($task, $this->date);
            $message .= "\n(REMOVED):\n";
            $message .= ($startTime ? "{$startTime} - " : '')."{$task->title}\n";
            $message .= "{$task->project->client->name}\n";

            $fullAddress = strip_tags(str_replace('<br>', "\n", $task->project->full_address));
            $message .= "{$fullAddress}\n";
        }

        return $message;
    }

    /**
     * @param iterable<int, mixed> $tasks
     * @return array<int, mixed>
     */
    private function sortTasksByStartTime(iterable $tasks, Carbon $date): array
    {
        $dateKey = $date->format('Y-m-d');

        return collect($tasks)
            ->sortBy(function ($task) use ($dateKey) {
                $time = data_get($task->options, "time_settings.{$dateKey}.start_time");
                $useTime = data_get($task->options, "time_settings.{$dateKey}.use_time") === true;

                if (! $useTime || ! is_string($time) || $time === '') {
                    return 9999;
                }

                try {
                    $parsed = str_contains($time, ':')
                        ? (strlen($time) === 5
                            ? Carbon::createFromFormat('H:i', $time)
                            : Carbon::createFromFormat('H:i:s', $time))
                        : null;

                    return $parsed ? ($parsed->hour * 60 + $parsed->minute) : 9999;
                } catch (\Throwable $e) {
                    return 9999;
                }
            })
            ->values()
            ->all();
    }

    private function formatTaskStartTime($task, Carbon $date): ?string
    {
        $dateKey = $date->format('Y-m-d');
        $time = data_get($task->options, "time_settings.{$dateKey}.start_time");
        $useTime = data_get($task->options, "time_settings.{$dateKey}.use_time") === true;

        if (! $useTime || ! is_string($time) || $time === '') {
            return null;
        }

        try {
            $parsed = strlen($time) === 5
                ? Carbon::createFromFormat('H:i', $time)
                : Carbon::createFromFormat('H:i:s', $time);

            return $parsed->format('g:i A');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Handle notification failure
     */
    public function failed($exception)
    {
        Log::channel('task_reminder')->error('Task update notification failed', ApiErrorFormatter::format($exception, [
            'current_tasks' => count($this->currentTasks),
            'removed_tasks' => count($this->removedTasks),
            'date' => $this->date->format('Y-m-d'),
        ]));
    }
}