<div class="grid max-w-2xl grid-cols-3 gap-6 sm:px-6 lg:max-w-5xl lg:grid-flow-col-dense lg:grid-cols-6">
    {{-- USER TASKS --}}
    <div class="space-y-6 col-span-3 lg:col-start-1 lg:col-span-2 h-180">
        <livewire:planner.cards-index>
    </div>
    
    {{-- VENDOR DETAILS --}}
    <div class="space-y-6 col-span-3 lg:col-start-1 lg:col-span-2">
        <livewire:vendors.vendor-details :vendor="$user->vendor">
    </div>

    {{-- VENDOR TEAM MEMBERS --}}
    <div class="space-y-6 col-span-3 lg:col-start-3 lg:col-span-4">
        <livewire:users.users-index :vendor="$user->vendor" :view="'vendors.show'"/>
    </div>

    {{-- GRAPH --}}
    @can('hasAdminRole', $user)
        <div class="space-y-6 col-span-3 lg:col-start-1 lg:col-span-6">
            <livewire:sheets.sheet-monthly />
        </div>
    @endcan
</div>
