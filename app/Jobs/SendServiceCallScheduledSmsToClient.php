<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ClientServiceScheduledNotification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Text the homeowner the scheduled service-call tasks after a contractor sets
 * times (via the planner or the vendor availability page).
 *
 * Dispatched with a 5-minute delay and ShouldBeUnique (uniqueFor=300) per
 * project so multiple tasks scheduled in quick succession are consolidated into
 * a single text listing every scheduled task.
 */
class SendServiceCallScheduledSmsToClient implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $uniqueFor = 300; // 5 minutes

    public function __construct(public int $projectId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return "service_call_scheduled_sms_{$this->projectId}";
    }

    public function handle(): void
    {
        $project = Project::with(['latestStatus', 'createdByVendor'])
            ->find($this->projectId);

        if (! $project) {
            return;
        }

        // Only for Service Call projects (status_code 8).
        if ((int) ($project->latestStatus?->status_code ?? 0) !== 8) {
            return;
        }

        $client = $project->client_id
            ? Client::withoutGlobalScopes()->with('users')->find($project->client_id)
            : null;

        if (! $client) {
            return;
        }

        $tasks = Task::where('project_id', $this->projectId)
            ->whereNotNull('start_date')
            ->orderBy('start_date')
            ->orderBy('order')
            ->get();

        if ($tasks->isEmpty()) {
            return;
        }

        /** @var \Illuminate\Support\Collection<int, User> $clientUsers */
        $clientUsers = $client->users->filter(fn (User $user) => ! empty($user->cell_phone));

        if ($clientUsers->isEmpty()) {
            return;
        }

        foreach ($clientUsers as $user) {
            $recipientName = trim((string) ($user->nickname ?: $user->first_name ?: 'there'));

            $user->notify(new ClientServiceScheduledNotification($project, $recipientName, $tasks));

            Log::channel('client_sms')->info('SendServiceCallScheduledSmsToClient: queued scheduled service SMS', [
                'project_id' => $project->id,
                'user_id' => $user->id,
                'task_count' => $tasks->count(),
            ]);
        }
    }
}
