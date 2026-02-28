<?php

namespace App\Jobs;

use App\Mail\TaskReminderEmail;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendTaskReminderToClients implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $taskId)
    {
    }

    /**
     * Send a task reminder email to each unregistered client user on the project.
     */
    public function handle(): void
    {
        $task = Task::with('project.client.users', 'project.latestStatus')->find($this->taskId);

        if (! $task) {
            Log::warning('SendTaskReminderToClients: Task not found', ['task_id' => $this->taskId]);

            return;
        }

        $project = $task->project;

        if (! $project) {
            Log::warning('SendTaskReminderToClients: Task has no project', ['task_id' => $this->taskId]);

            return;
        }

        // Only send for Service Call projects (status_code 8)
        if ($project->latestStatus?->status_code !== 8) {
            return;
        }

        $client = $project->client;

        if (! $client) {
            Log::info('SendTaskReminderToClients: Project has no client', ['project_id' => $project->id]);

            return;
        }

        /** @var \Illuminate\Support\Collection<int, User> $unregisteredUsers */
        $unregisteredUsers = $client->users
            ->filter(fn (User $user) => ! ($user->registration['registered'] ?? false))
            ->filter(fn (User $user) => ! empty($user->email));

        if ($unregisteredUsers->isEmpty()) {
            Log::info('SendTaskReminderToClients: No unregistered client users with email', [
                'project_id' => $project->id,
                'client_id' => $client->id,
            ]);

            return;
        }

        foreach ($unregisteredUsers as $user) {
            $recipientName = trim((string) ($user->first_name ?? ''));

            Mail::to($user->email)
                ->send(new TaskReminderEmail($task, $recipientName));

            Log::info('SendTaskReminderToClients: Sent task reminder email', [
                'task_id' => $task->id,
                'user_id' => $user->id,
                'email' => $user->email,
            ]);
        }
    }
}
