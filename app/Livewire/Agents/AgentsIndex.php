<?php

namespace App\Livewire\Agents;

use App\Models\Agent;
use App\Models\VendorDoc;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
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

    #[Computed]
    public function agents()
    {
        return Agent::withCount('vendor_docs')
            ->orderBy('business_name', 'ASC')
            ->orderBy('name', 'ASC')
            ->get()
            ->groupBy(fn (Agent $agent) => $this->normalizedBusinessName($agent->business_name))
            ->map(function (Collection $businessAgents) {
                $agents = $businessAgents
                    ->map(function (Agent $agent): object {
                        return (object) [
                            'id' => $agent->id,
                            'name' => $agent->name,
                            'email' => $agent->email,
                            'address' => $agent->address,
                            'vendor_docs_count' => $agent->vendor_docs_count,
                        ];
                    })
                    ->values();

                return (object) [
                    'business_name' => $this->canonicalBusinessName($businessAgents),
                    'agents' => $agents,
                ];
            })
            ->sortBy('business_name')
            ->values();
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

    private function normalizedBusinessName(?string $businessName): string
    {
        $clean = Str::of((string) $businessName)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();

        $tokens = collect(explode(' ', $clean))
            ->reject(fn (string $token) => in_array($token, [
                'llc', 'ltd', 'limited', 'inc', 'incorporated', 'corp', 'corporation', 'co', 'company',
            ], true))
            ->values();

        $clean = $tokens->implode(' ');

        return $clean !== '' ? $clean : 'no-business-name';
    }

    private function canonicalBusinessName(Collection $businessAgents): string
    {
        $ranked = $businessAgents
            ->groupBy(fn (Agent $agent) => $this->cleanBusinessName((string) $agent->business_name))
            ->map(function (Collection $group, string $name): array {
                return [
                    'name' => $name,
                    'count' => $group->count(),
                    'lowercase_score' => preg_match_all('/[a-z]/', $name) ?: 0,
                    'length' => strlen($name),
                ];
            })
            ->values()
            ->sort(function (array $a, array $b): int {
                return [$b['count'], $b['lowercase_score'], $b['length']] <=> [$a['count'], $a['lowercase_score'], $a['length']];
            })
            ->values();

        return (string) ($ranked->first()['name'] ?? 'No Business Name');
    }

    private function cleanBusinessName(string $businessName): string
    {
        $clean = preg_replace('/\s+/', ' ', trim($businessName)) ?: '';

        return $clean !== '' ? $clean : 'No Business Name';
    }

    #[Title('Insurance Agents')]
    public function render()
    {
        abort_unless(auth()->id() === 1, 403);

        return view('livewire.agents.index');
    }
}
