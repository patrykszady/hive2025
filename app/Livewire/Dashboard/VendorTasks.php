<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HasLaterTasks;
use App\Models\Vendor;
use App\Services\Dashboard\VendorTaskAgenda;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class VendorTasks extends Component
{
    use \App\Livewire\Concerns\TogglesTaskChecklist;

    use HasLaterTasks;
    protected $listeners = ['refreshComponent' => 'refreshTasks'];

    public Vendor $vendor;

    public function refreshTasks(): void
    {
        unset($this->groupedTasks, $this->laterTasks, $this->taskCount, $this->unscheduledTasks, $this->distinctProjectCount);
    }

    /**
     * Get tasks grouped by date across all vendor projects.
     *
     * @return Collection<string, Collection<int, \App\Models\Task>>
     */
    #[Computed]
    public function groupedTasks(): Collection
    {
        return app(VendorTaskAgenda::class)->groupedTasks($this->vendor, (int) auth()->id(), browser_today());
    }

    protected function laterTasksBaseQuery(): Builder
    {
        return app(VendorTaskAgenda::class)->laterTasksBaseQuery($this->vendor, (int) auth()->id());
    }

    protected function laterTasksWindowEnd(): string
    {
        return browser_today()->addDays(6)->format('Y-m-d');
    }

    /**
     * Get total task count for the badge.
     */
    #[Computed]
    public function taskCount(): int
    {
        return app(VendorTaskAgenda::class)->taskCount($this->vendor, (int) auth()->id(), browser_today());
    }

    /**
     * Get tasks with no scheduled dates across vendor projects.
     *
     * @return Collection<int, \App\Models\Task>
     */
    #[Computed]
    public function unscheduledTasks(): Collection
    {
        return app(VendorTaskAgenda::class)->unscheduledTasks($this->vendor, (int) auth()->id());
    }

    /**
     * Count distinct projects that have tasks in the displayed window.
     */
    #[Computed]
    public function distinctProjectCount(): int
    {
        return app(VendorTaskAgenda::class)->distinctProjectCount($this->groupedTasks);
    }

    public function render()
    {
        return view('livewire.dashboard.vendor-tasks');
    }

    public function placeholder()
    {
        return view('livewire.partials.tasks-placeholder', [
            'title' => 'Your Tasks',
            'showProjectInfo' => true,
            'count' => 2,
            'showAddTask' => true,
        ]);
    }
}
