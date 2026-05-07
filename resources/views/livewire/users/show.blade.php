<div>
    <div class="grid max-w-xl grid-cols-4 gap-4 xl:relative lg:max-w-5xl sm:px-6">
        <div class="col-span-4 space-y-4 lg:col-span-2">
            {{-- USER DETAILS --}}
            <livewire:users.user-details :user="$user" wire:key="user-details-{{ $user->id }}" />

            {{-- PASSKEYS --}}
            <livewire:users.passkeys :user="$user" wire:key="user-passkeys-{{ $user->id }}" />

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