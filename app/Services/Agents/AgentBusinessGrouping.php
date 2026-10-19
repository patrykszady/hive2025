<?php

namespace App\Services\Agents;

use App\Models\Agent;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Groups Agent rows by business name, tolerating punctuation and legal-
 * suffix variants (LLC/Ltd/Inc/…) — extracted from
 * App\Livewire\Agents\AgentsIndex (which delegates to this) so the app's
 * Agents screen (App\Http\Controllers\Api\App\V1\AgentController) uses the
 * exact same grouping, never a fork of it. Covered by
 * tests/Feature/AgentsIndexGroupingTest.php via AgentsIndex.
 */
class AgentBusinessGrouping
{
    /**
     * Every agent, grouped by normalized business name.
     *
     * @return Collection<int, object{business_name: string, agents: Collection<int, object>}>
     */
    public function grouped(): Collection
    {
        return Agent::withCount('vendor_docs')
            ->orderBy('business_name', 'ASC')
            ->orderBy('name', 'ASC')
            ->get()
            ->groupBy(fn (Agent $agent) => $this->normalizedBusinessName($agent->business_name))
            ->map(function (Collection $businessAgents) {
                $agents = $businessAgents
                    ->map(fn (Agent $agent): object => (object) [
                        'id' => $agent->id,
                        'name' => $agent->name,
                        'email' => $agent->email,
                        'address' => $agent->address,
                        'vendor_docs_count' => $agent->vendor_docs_count,
                    ])
                    ->values();

                return (object) [
                    'business_name' => $this->canonicalBusinessName($businessAgents),
                    'agents' => $agents,
                ];
            })
            ->sortBy('business_name')
            ->values();
    }

    public function normalizedBusinessName(?string $businessName): string
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

    public function canonicalBusinessName(Collection $businessAgents): string
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

    public function cleanBusinessName(string $businessName): string
    {
        $clean = preg_replace('/\s+/', ' ', trim($businessName)) ?: '';

        return $clean !== '' ? $clean : 'No Business Name';
    }
}
