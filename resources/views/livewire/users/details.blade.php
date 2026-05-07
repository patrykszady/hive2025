<x-details.card
    title="User Details"
    subheading="User and related details."
    :canEdit="auth()->user()->can('update', $user)"
    wire:init="$refresh"
>
    <x-slot:header_buttons>
        @can('update', $user->vendor)
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
        @endcan
    </x-slot:header_buttons>

    <x-slot:details>
    {{-- Lightweight skeleton guard to avoid flash before hydration --}}
    @php($hydrated = isset($user) && $user->id)
    <x-details.row title="Name" :content="$hydrated ? $user->full_name : 'Loading...'" />
    <x-details.row title="Email" :content="$hydrated ? $user->email : 'Loading...'" copyable />
    <x-details.row title="Cell Phone" :content="$hydrated && $user->cell_phone ? preg_replace('/^(\d{3})(\d{3})(\d{4})$/', '($1) $2-$3', $user->cell_phone) : 'Loading...'" copyable />

        @if($user->isEmployed())
            <x-details.row title="" :content="auth()->user()->vendor->name . ' Details:'" />
            @can('update', $user)
                <x-details.row 
                    title="Start Date" 
                    :content="$user->vendor_pivot?->start_date?->format('m/d/Y') ?? '—'" 
                />

                <x-details.row 
                    title="Hourly Rate" 
                    :content="money($user->vendor_pivot->hourly_rate)" 
                />
                {{-- @can('create_team_member', [App\Models\User::class, auth()->user()->vendor->id])
                    <x-details.row 
                        title="Hourly Rate" 
                        :content="money($user->vendor_pivot->hourly_rate)" 
                    />
                @endcan --}}
            @endcan

            <x-details.row title="Vendor Role" :content="$user->getRoleForVendor(auth()->user()->vendor->id)" />

            @if($user->via_vendor)
                <x-details.row title="Via Vendor" :content="$user->via_vendor->business_name . ' (' . $user->via_vendor->business_type . ')'" href="{{ route('vendors.show', $user->via_vendor->id) }}" />
            @endif
        @endif
    </x-slot:details>
</x-details.card>