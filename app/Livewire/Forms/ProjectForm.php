<?php

namespace App\Livewire\Forms;

use App\Models\Client;
use App\Models\Project;
use App\Services\Projects\ProjectWriter;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Rule;
use Livewire\Form;

class ProjectForm extends Form
{
    use AuthorizesRequests;

    public ?Project $project;

    #[Rule('required', as: 'Client')]
    public $client_id = null;

    #[Rule('required|min:3', as: 'Project Name')]
    public $project_name = null;

    #[Rule('required', as: 'Address')]
    public $project_existing_address = null;

    public function setProject(Project $project)
    {
        $this->project = $project;

        $this->client_id = $project->client_id;
        $this->project_name = $project->project_name;
        $this->project_existing_address = 'NEW';

        $this->component->address_1 = $project->address;
        $this->component->address_2 = $project->address_2;
        $this->component->city = $project->city;
        $this->component->state = $project->state;
        $this->component->zip_code = $project->zip_code;
    }

    /**
     * client_id is a client-chosen selector — check it against this tenant's
     * own clients (ClientScope) rather than trusting whatever id arrives.
     */
    protected function assertClientAccessible(): void
    {
        if (! Client::whereKey($this->client_id)->exists()) {
            throw ValidationException::withMessages([
                'client_id' => 'Invalid client selected.',
            ]);
        }
    }

    public function update()
    {
        $this->validate();
        $this->assertClientAccessible();

        $oldClientId = $this->project->client_id;
        $newClientId = $this->client_id;

        $this->project->update([
            'project_name' => $this->project_name,
            'client_id' => $this->client_id,
            'address' => $this->component->address_1,
            'address_2' => $this->component->address_2,
            'city' => $this->component->city,
            'state' => $this->component->state,
            'zip_code' => $this->component->zip_code,
        ]);

        // If client changed, update the project_vendor pivot table
        if ($oldClientId != $newClientId) {
            \DB::table('project_vendor')
                ->where('project_id', $this->project->id)
                ->update(['client_id' => $newClientId]);

            $this->project->searchable();
        }

        return $this->project;
    }

    /**
     * Soft delete — NEVER forceDelete here. A hard delete took project 427 and
     * its client with it in Aug 2026, unrecoverably: the row was gone from
     * every backup and left an estimate pointing at nothing. The project keeps
     * its statuses, images, and history while trashed; ProjectObserver
     * cascades the estimates so they stop showing on /estimates, and restores
     * them if the project comes back. Permanent removal stays available via
     * forceDelete() in tinker for a deliberate purge.
     */
    public function delete()
    {
        $this->project->delete();

        return $this->project;
    }

    /**
     * The actual create logic — address resolution, the client-accessible
     * check, the double-submit guard, and the Project::create() call — lives
     * in App\Services\Projects\ProjectWriter so the Hive Contractors app's
     * create-project endpoint creates identical rows. This form's own job is
     * just gathering what the writer needs from the component's address
     * fields/validation before handing off.
     */
    public function store()
    {
        $this->validate();

        return app(ProjectWriter::class)->create($this->client_id, (string) $this->project_name, [
            'project_existing_address' => (string) $this->project_existing_address,
            'address' => $this->component->address_1,
            'address_2' => $this->component->address_2,
            'city' => $this->component->city,
            'state' => $this->component->state,
            'zip_code' => $this->component->zip_code,
        ]);
    }
}
