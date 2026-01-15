<?php

namespace App\Notifications;

use App\Channels\TelnyxChannel;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Example notification that sends a group SMS to all client users on a project.
 *
 * This uses Telnyx's group SMS/MMS feature - all recipients will be in the same
 * conversation thread, so they can see each other's replies.
 */
class ClientGroupSmsNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public Project $project;

    public string $type;

    /** @var Collection<int, Task> */
    public Collection $tasks;

    /**
     * Create a new notification instance.
     *
     * @param  Collection<int, Task>  $tasks
     */
    public function __construct(
        Project $project,
        string $type,
        Collection $tasks
    ) {
        $this->project = $project;
        $this->type = $type;
        $this->tasks = $tasks;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [TelnyxChannel::class];
    }

    /**
     * Get all recipient phone numbers for group SMS.
     *
     * The Telnyx channel will call this method to get all recipients.
     * All recipients will be in the same SMS thread/conversation.
     *
     * @return array<string> Phone numbers in E.164 format
     */
    public function getTelnyxRecipients(object $notifiable): array
    {
        // Get all client users with phone numbers
        return $this->project->client?->users
            ->filter(fn ($user) => ! empty($user->cell_phone))
            ->map(fn ($user) => $user->routeNotificationForTwilio())
            ->values()
            ->all() ?? [];
    }

    /**
     * Get the SMS message content for Telnyx.
     */
    public function toTelnyx(object $notifiable): string
    {
        $project = $this->project;
        $owner = $project->createdByVendor;

        $ownerName = $owner?->short_name ?? $owner?->name ?? 'Your contractor';

        $taskCount = $this->tasks->count();
        $taskWord = $taskCount === 1 ? 'task' : 'tasks';

        $introLine = match ($this->type) {
            'today' => "Your project {$taskWord} for today:",
            'tomorrow' => "Your project {$taskWord} for tomorrow:",
            'changed' => "Your project schedule has been updated:",
            default => "Your project {$taskWord}:",
        };

        $taskLines = $this->tasks->map(function ($task) {
            return '• ' . ($task->title ?? 'Task');
        })->implode("\n");

        return "{$ownerName}\n{$introLine}\n{$taskLines}";
    }

    /**
     * Optional: Include media URLs for MMS.
     *
     * These must be publicly accessible URLs.
     *
     * @return array<string> Public URLs to media files
     */
    public function getTelnyxMediaUrls(object $notifiable): array
    {
        // Example: return project images or documents
        // return ['https://example.com/project-schedule.png'];
        return [];
    }
}
