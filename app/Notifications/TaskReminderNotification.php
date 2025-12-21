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

class TaskReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $tasks;
    protected $date;

    public function __construct($tasks, Carbon $date)
    {
        $this->tasks = $tasks;
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
        $tasks = $this->sortTasksByStartTime($this->tasks, $this->date);

        // Hi {$user->first_name}!
        $taskCount = count($tasks);

        // Use Str::plural for singular/plural task text
        $message = Str::upper(Str::plural('task', $taskCount)) . " for TOMORROW:\n";

        $message .= "{$this->date->format('l, M j, Y')}\n\n";

        foreach ($tasks as $index => $task) {
            // Add empty line between tasks if there are multiple tasks
            if ($index > 0) {
                $message .= "\n";
            }

            $startTime = $this->formatTaskStartTime($task, $this->date);
            $message .= ($startTime ? "{$startTime} - " : '')."{$task->title}\n";
            $message .= "{$task->project->client->name}\n";

            $fullAddress = strip_tags(str_replace('<br>', "\n", $task->project->full_address));
            $message .= "{$fullAddress}\n";

            // Add short link at the bottom
            // $message .= "\n🔗 https://dashboard.hive.contractors/‌"; // Added zero-width non-joiner

            // // Show task duration if it's a multi-day task
            // if ($task->start_date !== $task->end_date) {
            //     $startDate = Carbon::parse($task->start_date);
            //     $endDate = Carbon::parse($task->end_date);
            //     $message .= "⏰ Duration: {$startDate->format('M j')} - {$endDate->format('M j')}\n";
            // }
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
        Log::channel('task_reminder')->error('Task reminder notification failed', ApiErrorFormatter::format($exception, [
            'task_count' => count($this->tasks),
            'task_ids' => collect($this->tasks)->pluck('id')->toArray(),
            'date' => $this->date->format('Y-m-d'),
        ]));
    }
}
