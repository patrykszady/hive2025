<div>
    <div class="grid max-w-xl grid-cols-4 gap-4 xl:relative lg:max-w-5xl sm:px-6">
        <div class="col-span-4 space-y-4 lg:col-span-2">
            {{-- USER DETAILS --}}
            <livewire:users.user-details :user="$user" wire:key="user-details-{{ $user->id }}" />

            {{-- PASSKEYS --}}
            <livewire:users.passkeys :user="$user" wire:key="user-passkeys-{{ $user->id }}" />

            {{-- CLIENTS this user is linked to — one person can sit on several
                 client records (their own household, a property they manage),
                 so name them all here. Internal chrome: hidden from clients
                 browsing their own profile. --}}
            @if (! auth()->user()->is_browsing_as_client && $user->clients->isNotEmpty())
                <x-island-card heading="Clients">
                    <div class="space-y-2">
                        @foreach ($user->clients as $client)
                            <a href="{{ route('clients.show', $client) }}" wire:navigate
                                class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 px-3 py-2 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800">
                                <flux:text class="truncate font-medium text-zinc-800 dark:text-zinc-200">{{ $client->name }}</flux:text>
                                <flux:text class="shrink-0 text-xs text-zinc-500">
                                    {{ $client->address }}{{ $client->city ? ' | ' . $client->city : '' }}
                                </flux:text>
                            </a>
                        @endforeach
                    </div>
                </x-island-card>
            @endif

            {{-- VENDOR DETAILS --}}
            {{-- @if($user->this_vendor)
                 <livewire:vendors.vendor-details :vendor="$user->vendor" :expanded="true">
            @endif --}}
        </div>

        {{-- NOTIFICATION SETTINGS --}}
        @if(auth()->id() === $user->id && auth()->user()->primary_vendor_id)
            <div class="col-span-4 space-y-4 lg:col-span-2">
                <livewire:users.user-notification-settings :user="$user" wire:key="user-notif-settings-{{ $user->id }}" />
            </div>
        @endif

        {{-- USER FINANCES --}}
        @can('update', $user)
            @if($user->isEmployed())
                <div class="space-y-2 col-span-4 lg:col-span-4">
                    <livewire:users.user-finances :user="$user" lazy />
                </div>
            @endif
        @endcan
	</div>
    <livewire:users.user-create />
</div>