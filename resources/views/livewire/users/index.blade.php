<flux:card class="space-y-2 !py-4">
    <div class="flex justify-between">
        <flux:heading size="lg">{{$view_text['card_title']}}</flux:heading>
        @unless($nonLivewire ?? false)
            @if($view === 'vendors.show' || $view === 'vendor_registration')
                @can('create_team_member', [App\Models\User::class, $vendor->id])
                    <flux:button wire:navigate.hover wire:click="add_user" icon="plus" size="sm">Add Member</flux:button>
                @endcan
            @elseif(isset($client))
                @can('create_client_member', [App\Models\User::class, $client])
                    <flux:button wire:navigate.hover wire:click="add_user" icon="plus" size="sm">Add Member</flux:button>
                @endcan
            @endif
        @endunless
    </div>

    <flux:separator variant="subtle" />

    <flux:table class="{{ ($nonLivewire ?? false) ? 'whitespace-normal' : '' }}">
        <flux:table.columns>
            <flux:table.column>Name</flux:table.column>
            <flux:table.column>Phone</flux:table.column>
            <flux:table.column class="w-1/2">Email</flux:table.column>
            @if($view === 'vendors.show' || $view === 'vendor_registration')
                <flux:table.column>Role</flux:table.column>
            @endif
        </flux:table.columns>

        <flux:table.rows>
            @foreach($users as $user)
                <flux:table.row :key="$user->id">
                    @if($nonLivewire ?? false)
                        <flux:table.cell variant="strong">
                            {{ $user->full_name }}
                        </flux:table.cell>
                    @elseif($view === 'clients.show' && auth()->user()->can('update', $client))
                        <flux:table.cell
                            wire:navigate.hover
                            href="{{route('users.show', $user->id)}}"
                            variant="strong"
                            class="cursor-pointer"
                            >
                            {{ $user->full_name }}
                        </flux:table.cell>
                    @elseif($view === 'clients.show')
                        <flux:table.cell variant="strong">
                            {{ $user->full_name }}
                        </flux:table.cell>
                    @else
                        <flux:table.cell
                            wire:navigate.hover
                            href="{{route('users.show', $user->id)}}"
                            variant="strong"
                            class="cursor-pointer"
                            >
                            {{ $user->full_name }}
                        </flux:table.cell>
                    @endif
                    <flux:table.cell>{{ $user->cell_phone }}</flux:table.cell>
                    <flux:table.cell class="whitespace-normal break-words">
                        <span class="whitespace-normal break-words">{{ $user->email }}</span>
                    </flux:table.cell>
                    @if($view === 'vendors.show' || $view === 'vendor_registration')
                        {{-- Show role only for vendor users --}}
                        <flux:table.cell>                            
                            <flux:badge inset="top bottom" color="blue" size="sm">
                                {{ $user->getRoleForVendor($vendor->id) }}
                            </flux:badge>
                        </flux:table.cell>
                    @endif
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
    
    <div class="flex justify-end">
        @unless($nonLivewire ?? false)
            <div x-data="{ isConfirmed: false }">
                @if($view === 'vendor_registration' && ! data_get($vendor->registration, 'team_members', false))
                    <flux:button
                        variant="primary"
                        x-show="!isConfirmed"
                        wire:click="$dispatchTo('entry.vendor-registration', 'confirmProcess', { process_step: 'team_members' }); $nextTick(() => { isConfirmed = true })"
                        >
                        Skip Team Members
                    </flux:button>
                @endif

                <livewire:users.user-create />
                {{-- @can('update', $vendor)
                    <livewire:users.user-create />
                @endcan --}}
            </div>
        @endunless
    </div>
</flux:card>
