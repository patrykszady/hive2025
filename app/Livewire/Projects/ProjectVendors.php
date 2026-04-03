<?php

namespace App\Livewire\Projects;

use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Vendor;
use Livewire\Component;

class ProjectVendors extends Component
{
    public Project $project;

    public ?int $vendor_id = null;

    public bool $showModal = false;

    protected $listeners = ['refreshComponent' => '$refresh'];

    public function rules(): array
    {
        return [
            'vendor_id' => 'required|exists:vendors,id',
        ];
    }

    public function messages(): array
    {
        return [
            'vendor_id.required' => 'Please select a vendor.',
        ];
    }

    public function openInviteModal(): void
    {
        $this->reset('vendor_id');
        $this->resetValidation();
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate();

        $vendor = Vendor::findOrFail($this->vendor_id);

        if ($vendor->projects->contains($this->project->id)) {
            $this->dispatch('notify', type: 'warning', content: 'Vendor is already part of this project.');
            $this->showModal = false;

            return;
        }

        $client = Client::withoutGlobalScopes()
            ->where('vendor_id', auth()->user()->vendor->id)
            ->first();

        $vendor->projects()->attach($this->project->id, ['client_id' => $client?->id]);

        $statusCode = ProjectStatus::getCodeForLabel('Invited') ?? 1;
        ProjectStatus::create([
            'project_id' => $this->project->id,
            'belongs_to_vendor_id' => $vendor->id,
            'status_code' => $statusCode,
            'start_date' => today()->format('Y-m-d'),
        ]);

        $this->dispatch('notify', type: 'success', content: $vendor->name . ' invited to project.');
        $this->showModal = false;
        $this->dispatch('refreshComponent');
    }

    public function getAvailableVendorsProperty()
    {
        $existingVendorIds = $this->project->vendors->pluck('id')->toArray();

        return auth()->user()->vendor
            ->vendors()
            ->whereJsonContains('registration', ['registered' => true])
            ->whereNotIn('vendors.id', $existingVendorIds)
            ->orderBy('business_name')
            ->get();
    }

    public function render()
    {
        return view('livewire.projects.project-vendors');
    }
}
