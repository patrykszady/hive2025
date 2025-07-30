{{-- filepath: /home/patryk/web/hive/resources/views/livewire/users/details.blade.php --}}
<x-details.card
    title="User Details"
    subheading="User and related details."
    :canEdit="auth()->user()->can('update', $user)"
>
    <x-slot:header_buttons>
        @if($user->this_vendor)
            <flux:button.group>
                <flux:button
                    wire:click="$dispatchTo('users.user-create', 'editMember', { user: {{$user->id}} })"
                    size="sm"
                >
                    Edit User
                </flux:button>
                <flux:dropdown position="bottom" align="end">
                    <flux:button icon-trailing="chevron-down" size="sm"></flux:button>

                    <flux:menu>
                        <flux:menu.item
                            wire:click="$dispatchTo('users.user-create', 'removeMember', { user: {{$user->id}} })"
                            wire:confirm.prompt="Are you sure you want to remove this User from this Vendor?\n\nType REMOVE to confirm|REMOVE"
                            size="sm"
                            variant="danger"
                        >
                            Remove User from Vendor
                        </flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            </flux:button.group>
        @else
            <flux:button
                wire:click="$dispatchTo('users.user-create', 'editMember', { user: {{$user->id}} })"
                size="sm"
            >
                Edit User
            </flux:button>
        @endif
    </x-slot:header_buttons>

    <x-slot:details>
        <x-details.row title="Name" :content="$user->full_name" />
        <x-details.row title="Email" :content="$user->email" copyable />
        <x-details.row title="Cell Phone" :content="$user->cell_phone" copyable />
        
        @if($user->this_vendor)
            @can('update', $user)
                <x-details.row 
                    title="Start Date" 
                    :content="$user->this_vendor->pivot->start_date->format('m/d/Y')" 
                />
                <x-details.row 
                    title="Hourly Rate" 
                    :content="money($user->this_vendor->pivot->hourly_rate)" 
                />
            @endcan

            <x-details.row title="Vendor Role" :content="$user->vendor_role" />
        @endif
    </x-slot:details>
</x-details.card>