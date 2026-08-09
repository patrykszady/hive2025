<?php

namespace App\Livewire\Clients;

use App\Models\Client;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

class ClientsShow extends Component
{
    use AuthorizesRequests;

    public Client $client;

    protected $listeners = ['refreshComponent' => '$refresh'];

    /**
     * Check if the current user is a client-only user.
     */
    #[Computed]
    public function isClientUser(): bool
    {
        return auth()->user()->is_browsing_as_client;
    }

    /**
     * The consultation times this client's homeowner picked on the public
     * scheduling page — read live off their lead so the client page answers
     * "when can they meet?" without hunting through the leads list.
     *
     * @return array{lead_id: int, times: array<int, array{date: string, time: string}>, updated: ?string, preference: ?string}|null
     */
    #[Computed]
    public function leadAvailability(): ?array
    {
        $lead = \App\Models\Lead::latestForClient($this->client);

        $times = collect((array) ($lead?->lead_data['availability'] ?? []))
            ->filter(fn ($slot) => is_array($slot) && \App\Models\Lead::slotIsBookable($slot))
            ->values()
            ->all();

        if ($times === []) {
            return null;
        }

        return [
            'lead_id' => $lead->id,
            'times' => $times,
            'updated' => $lead->lead_data['availability_updated_at'] ?? null,
            'preference' => $lead->lead_data['meeting_preference'] ?? null,
        ];
    }

    #[Title('Client')]
    public function render()
    {
        $this->authorize('view', $this->client);

        return view('livewire.clients.show');
    }
}
