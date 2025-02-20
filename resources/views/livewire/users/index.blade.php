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
        <flux:table.columns>
            <flux:table.column>Name</flux:table.column>
            <flux:table.column>Phone</flux:table.column>
            <flux:table.column>Email</flux:table.column>
            @if($view === 'vendors.show')
                <flux:table.column>Role</flux:table.column>
            @endif
        </flux:table.columns>

        <flux:table.rows>
            @foreach($users as $user)
                <flux:table.row :key="$user->id">
                    <flux:table.cell
                        wire:navigate.hover
                        href="{{route('users.show', $user->id)}}"
                        variant="strong"
                        class="cursor-pointer"
                        >
                        {{ $user->full_name }}
                    </flux:table.cell>
                    <flux:table.cell>{{ $user->cell_phone }}</flux:table.cell>
                    {{-- Str::limit($user->email, 8) --}}
                    <flux:table.cell>{{ $user->email }}</flux:table.cell>
                    @if($view === 'vendors.show')
                        <flux:table.cell>
                            {{ $user->getVendorRole($vendor->id) }}
                            {{-- <flux:badge inset="top bottom" color="{{$user->getVendorRole($vendor->id) === 'Admin' ? 'cyan' : 'purple'}}">
                                {{ $user->getVendorRole($vendor->id) }}
                            </flux:badge> --}}
                        </flux:table.cell>
                    @endif
                </flux:table.row>
            @endforeach
        </flux:table.rows>
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
