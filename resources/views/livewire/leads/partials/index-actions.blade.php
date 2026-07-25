{{-- Card actions — included by BOTH the real card and its loading
     skeleton so the buttons are present from the first paint. --}}
@can('create', App\Models\Project::class)
    <flux:button size="sm" wire:click="$dispatchTo('leads.lead-create', 'addLead')">Add Lead</flux:button>
@endcan
