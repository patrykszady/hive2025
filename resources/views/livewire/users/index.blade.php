<x-island-card :heading="$view_text['card_title']" :separator="true">
    <x-slot:actions>
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
    </x-slot:actions>

        @if($accordion ?? false)
            <div x-data="{ open: @js($accordionExpanded ?? true) }">
                <button type="button" @click="open = !open" class="flex w-full items-center justify-between py-2">
                    <div class="font-medium text-gray-700 dark:text-gray-300">Details</div>
                    <flux:icon.chevron-down variant="mini" class="text-gray-400 transition-transform duration-200" ::class="open && 'rotate-180'" />
                </button>
                <div x-show="open" x-collapse x-cloak>
        @endif

        <flux:table class="{{ ($nonLivewire ?? false) ? 'whitespace-normal' : '' }}">
        <flux:table.columns>
            <flux:table.column>Name</flux:table.column>
            <flux:table.column>Phone</flux:table.column>
            <flux:table.column>Email</flux:table.column>
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
                            class="cursor-pointer hover:text-indigo-600 dark:hover:text-indigo-400"
                            >
                            {{ $user->full_name }}
                        </flux:table.cell>
                    @elseif($view === 'clients.show' && auth()->user()->can('update_client_member', $user))
                        <flux:table.cell
                            wire:navigate.hover
                            href="{{route('users.show', $user->id)}}"
                            variant="strong"
                            class="cursor-pointer hover:text-indigo-600 dark:hover:text-indigo-400"
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
                            class="cursor-pointer hover:text-indigo-600 dark:hover:text-indigo-400"
                            >
                            {{ $user->full_name }}
                        </flux:table.cell>
                    @endif
                    <flux:table.cell>
                        @if($user->cell_phone)
                            @php $formattedPhone = preg_replace('/^(\d{3})(\d{3})(\d{4})$/', '($1) $2-$3', $user->cell_phone); @endphp
                            <div class="flex items-center gap-1 min-w-0" x-data="{
                                copied: false,
                                copyText() {
                                    navigator.clipboard.writeText('{{ $user->cell_phone }}')
                                        .then(() => { this.copied = true; setTimeout(() => { this.copied = false }, 1500); })
                                        .catch(() => { $flux.toast({ text: 'Failed to copy phone to clipboard.', variant: 'danger', timeout: 2000, position: 'top right' }); });
                                }
                            }">
                                <span class="truncate text-sm" title="{{ $formattedPhone }}">{{ $formattedPhone }}</span>
                                <div x-show="!copied" class="shrink-0">
                                    <flux:button size="xs" icon="clipboard-document" icon:variant="outline" tooltip="Copy Phone" x-on:click.stop="copyText()" />
                                </div>
                                <div x-show="copied" x-cloak class="shrink-0">
                                    <flux:button size="xs" icon="check" variant="primary" color="green" disabled />
                                </div>
                            </div>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        @if($user->email)
                            <div class="flex items-center gap-1 min-w-0" x-data="{
                                copied: false,
                                copyText() {
                                    navigator.clipboard.writeText('{{ addslashes($user->email) }}')
                                        .then(() => { this.copied = true; setTimeout(() => { this.copied = false }, 1500); })
                                        .catch(() => { $flux.toast({ text: 'Failed to copy email to clipboard.', variant: 'danger', timeout: 2000, position: 'top right' }); });
                                }
                            }">
                                <span class="truncate text-sm" title="{{ $user->email }}">{{ $user->email }}</span>
                                <div x-show="!copied" class="shrink-0">
                                    <flux:button size="xs" icon="clipboard-document" icon:variant="outline" tooltip="Copy Email" x-on:click.stop="copyText()" />
                                </div>
                                <div x-show="copied" x-cloak class="shrink-0">
                                    <flux:button size="xs" icon="check" variant="primary" color="green" disabled />
                                </div>
                            </div>
                        @endif
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
                        wire:click="$dispatchTo('entry.vendor-registration', 'confirmProcess', { process_step: 'team_members' })"
                        x-on:click="$nextTick(() => { isConfirmed = true })"
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

    @if($accordion ?? false)
                </div>
            </div>
    @endif
</x-island-card>
