{{-- Card actions — included by BOTH the real card and its loading
     skeleton so the buttons are present from the first paint. --}}
@can('create', App\Models\Client::class)
    <flux:button size="sm" wire:click="$dispatchTo('users.user-create', 'newMember', { model: 'client', model_id: 'NEW' })" icon="plus">New Client</flux:button>
@endcan
