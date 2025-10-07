<div class="grid max-w-2xl grid-cols-3 gap-6 sm:px-6 lg:max-w-5xl lg:grid-flow-col-dense lg:grid-cols-6">
    {{-- USER TASKS --}}
    {{-- <div class="space-y-6 col-span-3 lg:col-start-1 lg:col-span-3 h-180">
        <livewire:planner.cards-index type="employee" :employee-id="$user->id" />
    </div> --}}

    <div class="space-y-6 col-span-3 lg:col-start-4 lg:col-span-3">
        {{-- VENDOR DETAILS --}}
        <livewire:vendors.vendor-details :vendor="$user->vendor" :expanded="false" />
        {{-- VENDOR TEAM MEMBERS --}}
        <livewire:users.users-index :vendor="$user->vendor" :view="'vendors.show'"/>
    </div>

    {{-- GRAPH --}}
    @can('hasAdminRole', $user)
        <div class="space-y-6 col-span-3 lg:col-start-1 lg:col-span-6">
            <livewire:sheets.sheet-monthly />
        </div>
    @endcan
</div>
