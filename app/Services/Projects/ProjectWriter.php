<?php

namespace App\Services\Projects;

use App\Models\Client;
use App\Models\Project;
use Illuminate\Validation\ValidationException;

/**
 * Creates a project exactly like App\Livewire\Forms\ProjectForm::store() —
 * shared by the web's ProjectCreate modal and the Hive Contractors app's
 * create-project screen, so both produce the same row and trigger the same
 * ProjectObserver side effects (vendor attach, the initial Estimate status,
 * MarkClientLeadWon — Project is model-event driven, so nothing here
 * dispatches those directly).
 */
class ProjectWriter
{
    /**
     * client_id is a client-chosen selector — check it against this
     * tenant's own clients (ClientScope) rather than trusting whatever id
     * arrives. Mirrors ProjectForm::assertClientAccessible().
     */
    public function assertClientAccessible(int $clientId): void
    {
        if (! Client::whereKey($clientId)->exists()) {
            throw ValidationException::withMessages([
                'client_id' => 'Invalid client selected.',
            ]);
        }
    }

    /**
     * @param  array{project_existing_address: string, address?: ?string, address_2?: ?string, city?: ?string, state?: ?string, zip_code?: ?string}  $address
     */
    public function create(int $clientId, string $projectName, array $address): Project
    {
        $this->assertClientAccessible($clientId);

        $resolved = $this->resolveAddress($clientId, $address);

        // A double-tapped Create fires two identical requests a breath
        // apart, and both used to mint a project ("Basement Stairs" twins,
        // two seconds apart). The second request must FIND the first's
        // project instead. Only a same-name project created moments ago
        // counts — a deliberate same-name project down the road stays
        // possible.
        $twin = Project::query()
            ->where('client_id', $clientId)
            ->whereRaw('LOWER(TRIM(project_name)) = ?', [mb_strtolower(trim($projectName))])
            ->where('created_at', '>=', now()->subMinutes(2))
            ->first();

        if ($twin) {
            return $twin;
        }

        return Project::create([
            'project_name' => $projectName,
            'client_id' => $clientId,
            'address' => $resolved['address'],
            'address_2' => $resolved['address_2'],
            'city' => $resolved['city'],
            // projects.state is NOT NULL — a client saved stateless (old
            // lead imports) must not crash project creation. IL is the
            // service area default.
            'state' => trim((string) $resolved['state']) ?: 'IL',
            'zip_code' => $resolved['zip_code'] ?? '',
        ]);
        // ProjectStatus with Estimate (code 2) is created automatically via ProjectObserver.
    }

    /**
     * Resolves the final address the same three ways ProjectForm::store()
     * does: a brand new address given on the request ('NEW'), the client's
     * own stored address ('CLIENT_PROJECT' — offered when the client has no
     * past projects), or a copy of one of the client's past project
     * addresses (a numeric existing project id).
     *
     * @param  array{project_existing_address: string, address?: ?string, address_2?: ?string, city?: ?string, state?: ?string, zip_code?: ?string}  $address
     * @return array{address: ?string, address_2: ?string, city: ?string, state: ?string, zip_code: ?string}
     */
    private function resolveAddress(int $clientId, array $address): array
    {
        $selector = $address['project_existing_address'] ?? 'NEW';

        if ($selector === 'CLIENT_PROJECT') {
            $client = Client::findOrFail($clientId);

            return [
                'address' => $client->address,
                'address_2' => $client->address_2,
                'city' => $client->city,
                'state' => $client->state,
                'zip_code' => $client->zip_code,
            ];
        }

        if (is_numeric($selector)) {
            $existingProject = Project::findOrFail($selector);

            return [
                'address' => $existingProject->address,
                'address_2' => $existingProject->address_2,
                'city' => $existingProject->city,
                'state' => $existingProject->state,
                'zip_code' => $existingProject->zip_code,
            ];
        }

        return [
            'address' => $address['address'] ?? null,
            'address_2' => $address['address_2'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? null,
            'zip_code' => $address['zip_code'] ?? null,
        ];
    }
}
