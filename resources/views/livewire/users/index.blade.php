{{-- 10-05-2024 should be same as VENDOR USERS --}}
<flux:card class="space-y-2 mb-4">
    <div class="flex justify-between">
        <flux:heading size="lg">{{$view_text['card_title']}}</flux:heading>
        @if($view === 'vendors.show')
            @can('create_team_member', [App\Models\User::class, $vendor->id])
                <flux:button wire:navigate.hover wire:click="add_user" icon="plus" size="sm">{{$view_text['card_title']}}</flux:button>
            @endcan
        @else
            @can('create_client_member', [App\Models\User::class, $client])
                <flux:button wire:navigate.hover wire:click="add_user" icon="plus" size="sm">{{$view_text['card_title']}}</flux:button>
            @endcan
        @endif
    </div>

    <flux:separator variant="subtle" />

    <flux:table>
        <flux:columns>
            <flux:column>Name</flux:column>
            <flux:column>Phone</flux:column>
            <flux:column>Email</flux:column>
            @if($view === 'vendors.show')
                <flux:column>Role</flux:column>
            @endif
        </flux:columns>

        <flux:rows>
            @foreach($users as $user)
                <flux:row :key="$user->id">
                    <flux:cell
                        wire:navigate.hover
                        href="{{route('users.show', $user->id)}}"
                        variant="strong"
                        class="cursor-pointer"
                        >
                        {{ $user->full_name }}
                    </flux:cell>
                    <flux:cell>{{ $user->cell_phone }}</flux:cell>
                    {{-- Str::limit($user->email, 8) --}}
                    <flux:cell>{{ $user->email }}</flux:cell>
                    @if($view === 'vendors.show')
                        <flux:cell>
                            {{ $user->getVendorRole($vendor->id) }}
                            {{-- <flux:badge inset="top bottom" color="{{$user->getVendorRole($vendor->id) === 'Admin' ? 'cyan' : 'purple'}}">
                                {{ $user->getVendorRole($vendor->id) }}
                            </flux:badge> --}}
                        </flux:cell>
                    @endif
                </flux:row>
            @endforeach
        </flux:rows>
    </flux:table>
    <div class="flex space-x-2">
        <flux:spacer />

        <div
            x-data="{ vendor_info: @entangle('registration') }"
            x-show="vendor_info"
            x-transition
            >
            <flux:button type="submit" variant="primary" wire:click="$dispatchTo('entry.vendor-registration', 'confirmProcessStep', { process_step: 'team_members' })">
                No More Employees
            </flux:button>
        </div>
    </div>
</flux:card>
