<?php

namespace App\Livewire\Agents;

use App\Models\VendorDoc;
use App\Services\Agents\AgentBusinessGrouping;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

class AgentsIndex extends Component
{
    public bool $showVendorDocsModal = false;

    public string $selectedBusinessName = '';

    public string $selectedAgentName = '';

    /** @var array<int> */
    public array $selectedAgentIds = [];

    /**
     * Grouping is shared with the app's Agents screen
     * (App\Http\Controllers\Api\App\V1\AgentController) through
     * AgentBusinessGrouping, so both read business names the same way.
     */
    #[Computed]
    public function agents()
    {
        return app(AgentBusinessGrouping::class)->grouped();
    }

    #[Computed]
    public function selectedVendorDocs()
    {
        if ($this->selectedAgentIds === []) {
            return collect();
        }

        return VendorDoc::withoutGlobalScopes()
            ->with('vendor')
            ->whereIn('agent_id', $this->selectedAgentIds)
            ->orderByDesc('expiration_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param array<int> $agentIds
     */
    public function openVendorDocsModal(string $businessName, array $agentIds, string $agentName = ''): void
    {
        $this->selectedBusinessName = $businessName;
        $this->selectedAgentName = $agentName;
        $this->selectedAgentIds = array_values(array_map('intval', $agentIds));
        $this->showVendorDocsModal = true;
    }

    #[Title('Insurance Agents')]
    public function render()
    {
        abort_unless(auth()->id() === 1, 403);

        return view('livewire.agents.index');
    }
}
