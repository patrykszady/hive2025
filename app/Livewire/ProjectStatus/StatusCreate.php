<?php

namespace App\Livewire\ProjectStatus;

use App\Livewire\Concerns\HasToJsonMethod;
use App\Models\Project;
use App\Models\ProjectStatus;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class StatusCreate extends Component
{
    use HasToJsonMethod;

    // use AuthorizesRequests;

    public Project $project;

    public $statuses = [];

    public $project_status = null;

    public $project_status_date = null;

    public $editingStatusId = null;

    public $editingStatusCode = null;

    public $editingStatusDate = null;

    public function mount(Project $project)
    {
        $this->project = $project;
        $this->project_status_date = today()->format('Y-m-d');
        $this->statuses = $this->project->statuses()->orderBy('start_date', 'ASC')->get();
    }

    public function update_project()
    {
        if (auth()->user()?->is_browsing_as_client) {
            abort(403);
        }

        $this->validate([
            'project_status' => 'required',
        ]);
        
        // project_status is already a status_code integer from the select dropdown
        $statusCode = (int) $this->project_status;
        
        // Always use current date/time for new status
        $this->project_status_date = today()->format('Y-m-d');
        
        $status =
            ProjectStatus::create([
                'project_id' => $this->project->id,
                'belongs_to_vendor_id' => auth()->user()->vendor->id,
                'status_code' => $statusCode,
                'start_date' => $this->project_status_date,
            ]);

        // Check if cancelled using the code (10)
        if ($statusCode === 10) {
            $this->project->estimates()->delete();
        }
        
        // If changing to Active (6) or any non-cancelled status, restore estimates that were deleted during cancellation
        // Only restore estimates where deleted_at is after the most recent "Cancelled" status change
        if ($statusCode !== 10) {
            // Find the most recent cancelled status before this new status (using created_at timestamps)
            $lastCancelledStatus = $this->project->statuses()
                ->where('status_code', 10)
                ->where('created_at', '<', $status->created_at)
                ->orderBy('created_at', 'desc')
                ->first();
            
            if ($lastCancelledStatus) {
                // Only restore estimates that were deleted after the cancellation (meaning they were active before)
                $this->project->estimates()
                    ->onlyTrashed()
                    ->where('deleted_at', '>=', $lastCancelledStatus->created_at)
                    ->restore();
            }
        }

        $this->project_status = null;
        $this->mount($this->project);
        $this->render();

        $this->dispatch('refreshComponent')->to('projects.project-show');
        $this->dispatch('refreshComponent')->to('estimates.estimates-index');

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Status Update',
            // route / href / wire:click
            text: '',
        );
    }

    public function editStatus($statusId)
    {
        if (auth()->user()?->is_browsing_as_client) {
            abort(403);
        }

        $status = ProjectStatus::findOrFail($statusId);
        
        // Authorize - only owner or admin can edit
        if ($status->belongs_to_vendor_id !== auth()->user()->vendor->id && !auth()->user()->hasRole('Super Admin')) {
            Flux::toast(
                duration: 5000,
                position: 'top right',
                variant: 'danger',
                heading: 'Unauthorized',
                text: 'You cannot edit this status.',
            );
            return;
        }
        
        $this->editingStatusId = $statusId;
        $this->editingStatusCode = $status->status_code;
        $this->editingStatusDate = $status->start_date->format('Y-m-d');
        
        $this->modal('edit_status_modal')->show();
    }

    public function updateStatus()
    {
        if (auth()->user()?->is_browsing_as_client) {
            abort(403);
        }

        $this->validate([
            'editingStatusCode' => 'required',
            'editingStatusDate' => 'required|date',
        ]);
        
        $status = ProjectStatus::findOrFail($this->editingStatusId);
        
        $status->update([
            'status_code' => (int) $this->editingStatusCode,
            'start_date' => $this->editingStatusDate,
        ]);
        
        $this->editingStatusId = null;
        $this->editingStatusCode = null;
        $this->editingStatusDate = null;
        
        $this->mount($this->project);
        Flux::modal('edit_status_modal')->close();
        $this->dispatch('refreshComponent')->to('projects.project-show');
        
        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Status Updated',
            text: '',
        );
    }

    public function deleteStatus()
    {
        if (auth()->user()?->is_browsing_as_client) {
            abort(403);
        }

        if (!$this->editingStatusId) {
            return;
        }
        
        $status = ProjectStatus::findOrFail($this->editingStatusId);
        
        // Prevent deleting if it's the only status
        if ($this->statuses->count() <= 1) {
            Flux::toast(
                duration: 5000,
                position: 'top right',
                variant: 'danger',
                heading: 'Cannot Delete',
                text: 'Project must have at least one status.',
            );
            return;
        }
        
        // Authorize - only owner or admin can delete
        if ($status->belongs_to_vendor_id !== auth()->user()->vendor->id && !auth()->user()->hasRole('Super Admin')) {
            Flux::toast(
                duration: 5000,
                position: 'top right',
                variant: 'danger',
                heading: 'Unauthorized',
                text: 'You cannot delete this status.',
            );
            return;
        }
        
        $status->delete();
        
        $this->editingStatusId = null;
        $this->editingStatusCode = null;
        $this->editingStatusDate = null;
        
        $this->mount($this->project);
        Flux::modal('edit_status_modal')->close();
        $this->dispatch('refreshComponent')->to('projects.project-show');
        
        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Status Deleted',
            text: '',
        );
    }

    public function render()
    {
        return view('livewire.project-status.create');
    }

    public function placeholder()
    {
        return view('livewire.project-status.status-create-placeholder');
    }
}
