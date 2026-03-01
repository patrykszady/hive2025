<?php

namespace App\Jobs;

use App\Mail\PendingTasksReminderEmail;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Send a batched pending-task reminder email to unregistered client users.
 *
 * Dispatched with a 15-minute delay so that multiple tasks created in quick
 * succession are consolidated into a single email per project.
 *
 * ShouldBeUnique with uniqueFor=900 ensures that only one job per project
 * runs within any 15-minute window, matching SendRealtimeTaskNotification.
 */
class SendPendingTaskReminderToClients implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $uniqueFor = 900; // 15 minutes

    public function __construct(public int $projectId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return "pending_task_reminder_{$this->projectId}";
    }

    public function handle(): void
    {
        $project = Project::with(['client.users', 'latestStatus'])->find($this->projectId);

        if (! $project) {
            Log::warning('SendPendingTaskReminderToClients: Project not found', ['project_id' => $this->projectId]);

            return;
        }

        // Only send for Service Call projects (status_code 8)
        if ($project->latestStatus?->status_code !== 8) {
            return;
        }

        $client = $project->client;

        if (! $client) {
            Log::info('SendPendingTaskReminderToClients: Project has no client', ['project_id' => $project->id]);

            return;
        }

        /** @var \Illuminate\Support\Collection<int, User> $unregisteredUsers */
        $unregisteredUsers = $client->users
            ->filter(fn (User $user) => ! ($user->registration['registered'] ?? false))
            ->filter(fn (User $user) => ! empty($user->email));

        if ($unregisteredUsers->isEmpty()) {
            Log::info('SendPendingTaskReminderToClients: No unregistered client users with email', [
                'project_id' => $project->id,
                'client_id' => $client->id,
            ]);

            return;
        }

        // Gather all pending tasks created in the last 20 minutes for this project
        // (slightly wider than the 15-min delay to avoid race conditions)
        $pendingTasks = Task::where('project_id', $this->projectId)
            ->where('created_at', '>=', now()->subMinutes(20))
            ->orderBy('created_at')
            ->get();

        if ($pendingTasks->isEmpty()) {
            Log::info('SendPendingTaskReminderToClients: No recent pending tasks', [
                'project_id' => $this->projectId,
            ]);

            return;
        }

        foreach ($unregisteredUsers as $user) {
            $recipientName = trim((string) ($user->first_name ?? ''));

            Mail::to($user->email)
                ->send(new PendingTasksReminderEmail($pendingTasks, $project, $recipientName));

            Log::info('SendPendingTaskReminderToClients: Sent batched pending task reminder', [
                'project_id' => $project->id,
                'task_count' => $pendingTasks->count(),
                'user_id' => $user->id,
                'email' => $user->email,
            ]);
        }
    }
}
