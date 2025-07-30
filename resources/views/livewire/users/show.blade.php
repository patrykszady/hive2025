<div>
    <div class="grid max-w-xl grid-cols-4 gap-4 xl:relative lg:max-w-5xl sm:px-6">
        <div class="col-span-4 space-y-4 lg:col-span-2">
            {{-- USER DETAILS --}}
            <livewire:users.user-details :user="$user">

            {{-- VENDOR DETAILS --}}
            {{-- @if($user->this_vendor)
                 <livewire:vendors.vendor-details :vendor="$user->vendor">
            @endif --}}
        </div>

        {{-- USER / VENDOR FINANCES --}}
        @if($user->this_vendor)
            @can('update', $user)
                <div class="space-y-2 col-span-4 lg:col-span-2">
                    <livewire:users.user-finances :user="$user">
                </div>
            @endcan
        @endif
	</div>
    <livewire:users.user-create />
</div>