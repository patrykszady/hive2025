<div>
	<div class="grid max-w-xl grid-cols-4 gap-4 xl:relative lg:max-w-5xl sm:px-6">
        <div class="col-span-4 space-y-4 lg:col-span-2 lg:h-32 lg:sticky lg:top-5">
            {{-- VENDOR DETAILS --}}
            <div class="col-span-4 lg:col-span-2">
                <livewire:vendors.vendor-details :vendor="$vendor">
            </div>

            {{-- INSURANCE --}}
            @if(in_array($vendor->business_type, ["Sub", "DBA"]))
                <livewire:vendor-docs.vendor-docs-card :vendor="$vendor" :view="true" lazy />
            @endif

            @if(in_array($vendor->business_type, ["Retail"]))
                <div class="col-span-4 lg:col-span-2 space-y-4">
                    <livewire:expenses.expense-index :expense_vendor="$vendor->id" :view="'vendors.show'" lazy />
                </div>
            @endif
        </div>

        {{-- VENDOR TEAM MEMBERS --}}
        @if($vendor->business_type != 'Retail')
            <div class="col-span-4 lg:col-span-2 space-y-4">
                <livewire:users.users-index :vendor="$vendor" :view="'vendors.show'"/>
                {{-- <livewire:users.team-members :vendor="$vendor"> --}}

                @if($vendor->business_type != 'Retail')
                    <livewire:checks.checks-index :vendor="$vendor->id" :view="'vendors.show'" lazy />
                @endif
            </div>
        @endif
	</div>
    <livewire:users.user-create />
    <livewire:clients.client-create />
    <livewire:vendor-docs.vendor-doc-create />
</div>
