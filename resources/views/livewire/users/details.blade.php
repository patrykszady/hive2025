<div>
    <flux:card>
        <div class="flex justify-between">
            <flux:heading size="lg" class="mb-0">User Details</flux:heading>

            @can('update', $user)
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
            @endcan
        </div>
        <flux:subheading>User and related details.</flux:subheading>

        <flux:separator variant="subtle" />

            {{-- DETAILS --}}
        <x-lists.details_list>
            <x-lists.details_item title="Name" detail="{{$user->full_name}}" />
            <x-lists.details_item title="Email" detail="{{$user->email}}" />
            <x-lists.details_item title="Cell Phone" detail="{{$user->cell_phone}}" />
            @if($user->this_vendor)
                @can('update', $user)
                    <x-lists.details_item title="Start Date" detail="{{$user->this_vendor->pivot->start_date->format('m/d/Y')}}" />
                    <x-lists.details_item title="Hourly Rate" detail="{{money($user->this_vendor->pivot->hourly_rate)}}" />
                @endcan

                <x-lists.details_item title="Vendor Role" detail="{{$user->getVendorRole($user->this_vendor->id)}}" />
            @endif
        </x-lists.details_list>

        {{-- FOOTER --}}
            {{-- <div
                x-data="{ vendor_info: @entangle('registration') }"
                x-show="vendor_info"
                x-transition
                >
                <flux:button type="submit" variant="primary" wire:click="$dispatchTo('entry.vendor-registration', 'confirmProcessStep', { process_step: 'vendor_info' })">
                    Confirm Details
                </flux:button>
            </div> --}}
    </flux:card>
</div>
