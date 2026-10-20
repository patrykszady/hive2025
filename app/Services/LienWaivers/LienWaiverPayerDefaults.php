<?php

namespace App\Services\LienWaivers;

use App\Models\Project;

/**
 * The GC's own lien waiver names the project client as payer — the shared
 * body of App\Livewire\LienWaivers\Index::prefillPayerFromProject() and the
 * Hive Contractors app's "Request a Waiver" screen, so both default a new
 * waiver's payer fields identically. A sub/supplier waiver's payer is the
 * GC itself (filled in separately — see LienWaiverController::store()), so
 * this is only ever used for the non-sub branch.
 */
class LienWaiverPayerDefaults
{
    /**
     * Defaults to the project client's billing address, falling back to the
     * project's own site address when no client is on file — exactly like
     * Index::prefillPayerFromProject().
     *
     * @return array{name: string, address: string, city_state_zip: string, has_client: bool}
     */
    public function forProject(Project $project): array
    {
        $client = $project->client;

        return [
            'name' => $client?->name ?? '',
            'address' => trim((string) ($client?->address ?? $project->address ?? '')),
            'city_state_zip' => trim(sprintf(
                '%s%s %s',
                $client?->city ?? $project->city ?? '',
                ($client?->state ?? $project->state)
                    ? ', '.($client?->state ?? $project->state)
                    : '',
                $client?->zip_code ?? $project->zip_code ?? '',
            )),
            'has_client' => (bool) $client,
        ];
    }
}
