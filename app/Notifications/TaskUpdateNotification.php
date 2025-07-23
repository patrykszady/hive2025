<?php

namespace App\Notifications;

use App\Channels\TwilioChannel;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        $message = "UPDATED TASKS for TODAY:\n";
        $message .= "{$this->date->format('l, M j, Y')}\n\n";

        // Check if there are no current tasks but there are removed tasks
        if (count($this->currentTasks) === 0 && count($this->removedTasks) > 0) {
            $message .= "You no longer have any tasks scheduled for today.\n";
        }

        // Current tasks
        foreach ($this->currentTasks as $index => $task) {
            if ($index > 0) {
                $message .= "\n";
            }

            $message .= "{$task->title}\n";
            $message .= "{$task->project->client->name}\n";

            $fullAddress = strip_tags(str_replace('<br>', "\n", $task->project->full_address));
            $message .= "{$fullAddress}\n";
        }

        // Removed tasks - uncomment to show removed tasks in the notification
        foreach ($this->removedTasks as $task) {
            $message .= "\n(REMOVED):\n";
            $message .= "{$task->title}\n";
            $message .= "{$task->project->client->name}\n";

            $fullAddress = strip_tags(str_replace('<br>', "\n", $task->project->full_address));
            $message .= "{$fullAddress}\n";
        }

        return $message;
    }

    /**
     * Handle notification failure
     */
    public function failed($exception)
    {
        Log::channel('task_reminder')->error("Task update notification failed", [
            'current_tasks' => count($this->currentTasks),
            'removed_tasks' => count($this->removedTasks),
            'date' => $this->date->format('Y-m-d'),
            'error' => $exception->getMessage()
        ]);
    }
}