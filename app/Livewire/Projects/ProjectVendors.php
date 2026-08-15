<?php

namespace App\Livewire\Projects;

use App\Mail\VendorProjectInvite;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Vendor;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Computed;
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

        $vendor->projects()->attach($this->project->id, ['client_id' => $this->project->client_id]);

        $statusCode = ProjectStatus::getCodeForLabel('Invited') ?? 1;
        ProjectStatus::create([
            'project_id' => $this->project->id,
            'belongs_to_vendor_id' => $vendor->id,
            'status_code' => $statusCode,
            'start_date' => today()->format('Y-m-d'),
        ]);

        // Send invite notification to dev email (patryk@hive.contractors) instead of vendor
        $devEmail = config('mail.dev_email') ?? 'patryk@hive.contractors';
        Mail::to($devEmail)->send(new VendorProjectInvite(
            invitingVendor: auth()->user()->vendor,
            invitedVendor: $vendor,
            project: $this->project,
        ));

        $this->dispatch('notify', type: 'success', content: $vendor->name . ' invited to project.');
        $this->showModal = false;
        $this->dispatch('refreshComponent');
    }

    /** The vendor currently picked in the invite select (blade queried this every render). */
    #[Computed]
    public function selectedVendor(): ?Vendor
    {
        return $this->vendor_id ? Vendor::find($this->vendor_id) : null;
    }

    /**
     * #[Computed] memoizes per request; the legacy getXProperty style re-ran
     * this year-of-expenses withSum query on every access and every render
     * (the component also listens to the global refreshComponent event).
     */
    #[Computed]
    public function availableVendors()
    {
        $vendors = auth()->user()->vendor
            ->vendors()
            ->where('vendors.business_type', 'Sub')
            ->withSum([
                'expenses as ytd_expense_sum' => function ($query) {
                    $query->where('date', '>=', today()->subYear());
                },
            ], 'amount')
            ->orderByDesc('ytd_expense_sum')
            ->orderBy('business_name')
            ->get()
            ->unique('id')
            ->values();

            $invitedVendorIds = $this->project->vendors->pluck('id')->flip();

            return $vendors
                ->sortBy(fn (Vendor $vendor) => $invitedVendorIds->has($vendor->id) ? 0 : 1)
                ->values();
    }

    public function isVendorInvited(Vendor $vendor): bool
    {
        return $this->project->vendors->contains($vendor->id);
    }

    public function render()
    {
        return view('livewire.projects.project-vendors');
    }
}
