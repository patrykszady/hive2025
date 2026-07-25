{{-- Shared collapsible-card pattern: chevron in the header, whole heading
     row toggles — same as every other accordion card on the site. --}}
<x-details.card
    :title="$view_text['card_title']"
    :accordion="($accordion ?? false) && ! ($nonLivewire ?? false)"
    :expanded="$accordionExpanded ?? true"
    :details_text="false"
    :separator="true"
    :nonLivewire="$nonLivewire ?? false">
    <x-slot:header_buttons>
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
    </x-slot:header_buttons>
    <x-slot:details>
        <flux:table class="{{ ($nonLivewire ?? false) ? 'whitespace-normal' : '' }}">
        <flux:table.columns>
            <flux:table.column>Name</flux:table.column>
            @if($nonLivewire ?? false)
                <flux:table.column>Phone</flux:table.column>
                <flux:table.column>Email</flux:table.column>
            @else
                <flux:table.column>Contact</flux:table.column>
            @endif
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
                    {{-- Contact: phone + email as icon buttons in one column so
                         narrow cards never scroll — value on hover, click copies. --}}
                    @php $formattedPhone = $user->cell_phone ? preg_replace('/^(\d{3})(\d{3})(\d{4})$/', '($1) $2-$3', $user->cell_phone) : null; @endphp
                    @if($nonLivewire ?? false)
                        <flux:table.cell>
                            @if($formattedPhone)<span class="text-sm">{{ $formattedPhone }}</span>@endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if($user->email)<span class="text-sm">{{ $user->email }}</span>@endif
                        </flux:table.cell>
                    @else
                        <flux:table.cell>
                            <div class="flex items-center gap-1">
                                @if($user->cell_phone)
                                    <div x-data="{
                                        copied: false,
                                        copyText() {
                                            navigator.clipboard.writeText('{{ $user->cell_phone }}')
                                                .then(() => { this.copied = true; setTimeout(() => { this.copied = false }, 1500); })
                                                .catch(() => { $flux.toast({ text: 'Failed to copy phone to clipboard.', variant: 'danger', timeout: 2000, position: 'top right' }); });
                                        }
                                    }">
                                        <div x-show="!copied">
                                            <flux:button size="xs" icon="phone" icon:variant="outline" tooltip="{{ $formattedPhone }} — click to copy" x-on:click.stop="copyText()" />
                                        </div>
                                        <div x-show="copied" x-cloak>
                                            <flux:button size="xs" icon="clipboard-document" variant="primary" color="green" disabled />
                                        </div>
                                    </div>
                                @endif
                                @if($user->email)
                                    <div x-data="{
                                        copied: false,
                                        copyText() {
                                            navigator.clipboard.writeText('{{ addslashes($user->email) }}')
                                                .then(() => { this.copied = true; setTimeout(() => { this.copied = false }, 1500); })
                                                .catch(() => { $flux.toast({ text: 'Failed to copy email to clipboard.', variant: 'danger', timeout: 2000, position: 'top right' }); });
                                        }
                                    }">
                                        <div x-show="!copied">
                                            <flux:button size="xs" icon="envelope" icon:variant="outline" tooltip="{{ $user->email }} — click to copy" x-on:click.stop="copyText()" />
                                        </div>
                                        <div x-show="copied" x-cloak>
                                            <flux:button size="xs" icon="clipboard-document" variant="primary" color="green" disabled />
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </flux:table.cell>
                    @endif
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
                <div>
                    <livewire:users.user-create />
                </div>
            @endunless
        </div>
    </x-slot:details>
</x-details.card>
