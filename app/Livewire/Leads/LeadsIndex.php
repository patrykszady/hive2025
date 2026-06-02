<?php

namespace App\Livewire\Leads;

use App\Models\Client;
use App\Models\Lead;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class LeadsIndex extends Component
{
    use AuthorizesRequests, WithPagination;

    #[Url(except: '')]
    public $origin = '';

    public $view = null;

    public $sortBy = 'date';

    public $sortDirection = 'desc';

    protected $listeners = ['refreshComponent' => '$refresh'];

    #[Computed]
    public function leads()
    {
        $leads =
            Lead::with(['user.clients', 'last_status'])->when($this->origin, function ($query) {
                return $query->where('origin', $this->origin);
            })
                ->orderBy($this->sortBy, $this->sortDirection)
                ->paginate(15);

        return $leads;
    }

    public function sort($column)
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
    }

    public function clientForLead(Lead $lead): ?Client
    {
        $client = $lead->user?->clients->first();
        if ($client) {
            return $client;
        }

        $address = $lead->lead_data['address'] ?? null;
        $vendorId = $lead->belongs_to_vendor_id;

        if (! $address || ! $vendorId) {
            return null;
        }

        $street = trim(explode(',', $address)[0] ?? '');
        if ($street === '') {
            return null;
        }

        return Client::query()
            ->whereHas('vendors', fn ($q) => $q->where('vendors.id', $vendorId))
            ->where('address', 'like', $street.'%')
            ->first();
    }

    #[Title('Leads')]
    public function render()
    {
        $this->authorize('viewAny', Lead::class);

        return view('livewire.leads.index');
    }
}
