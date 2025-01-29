<div class="grid max-w-2xl grid-cols-3 gap-6 mt-8 sm:px-6 lg:max-w-5xl lg:grid-flow-col-dense lg:grid-cols-6">
    {{-- VENDOR DETAILS --}}
    <div class="space-y-6 col-span-3 lg:col-start-1 lg:col-span-2">
        <livewire:vendors.vendor-details :vendor="$user->vendor">
    </div>

    {{-- VENDOR TEAM MEMBERS --}}
    <div class="space-y-6 col-span-3 lg:col-start-3 lg:col-span-4">
        <livewire:users.users-index :vendor="$user->vendor" :view="'vendors.show'"/>
        {{-- <livewire:users.team-members :vendor="$user->vendor"> --}}
    </div>

    {{-- GRAPH --}}
    @if($user->primary_vendor->pivot->role_id == 1)
        <div class="space-y-6 col-span-3 lg:col-start-1 lg:col-span-6">
            <livewire:sheets.sheet-monthly />
        </div>
    @endif
    <livewire:users.user-create />
    <livewire:clients.client-create />
</div>
