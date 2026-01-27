<?php

namespace App\Livewire\Clients;

use App\Models\Client;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

class ClientsShow extends Component
{
    use AuthorizesRequests;

    public Client $client;

    protected $listeners = ['refreshComponent' => '$refresh'];

    /**
     * Check if the current user is a client-only user.
     */
    #[Computed]
    public function isClientUser(): bool
    {
        return auth()->user()->is_client_user;
    }

    /**
     * Get projects for client users (with upcoming tasks).
     */
    #[Computed]
    public function clientProjects(): Collection
    {
        if (!$this->isClientUser) {
            return collect();
        }

        return $this->client->projects()
            ->with(['latestStatus', 'tasks' => function ($query) {
                $query->whereDate('end_date', '>=', now())
                    ->orderBy('start_date');
            }])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Get upcoming tasks across all projects for client users.
     */
    #[Computed]
    public function upcomingTasks(): Collection
    {
        if (!$this->isClientUser) {
            return collect();
        }

        return $this->clientProjects
            ->flatMap(fn ($project) => $project->tasks)
            ->filter(fn ($task) => $task->start_date && $task->start_date->gte(now()->startOfDay()))
            ->sortBy('start_date')
            ->take(10);
    }

    #[Title('Client')]
    public function render()
    {
        $this->authorize('view', $this->client);

        return view('livewire.clients.show');
    }
}
