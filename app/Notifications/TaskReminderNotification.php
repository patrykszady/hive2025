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
        // Hi {$user->first_name}!
        $taskCount = count($this->tasks);

        // Use Str::plural for singular/plural task text
        $message = Str::upper(Str::plural('task', $taskCount)) . " for TOMORROW:\n";

        $message .= "{$this->date->format('l, M j, Y')}\n\n";

        foreach ($this->tasks as $index => $task) {
            // Add empty line between tasks if there are multiple tasks
            if ($index > 0) {
                $message .= "\n";
            }

            $message .= "{$task->title}\n";
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
