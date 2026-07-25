<div>
	<div class="grid max-w-xl grid-cols-4 gap-4 xl:relative lg:max-w-5xl sm:px-6">
        <div class="col-span-4 space-y-4 lg:col-span-2">
            {{-- VENDOR DETAILS --}}
            <div class="col-span-4 lg:col-span-2">
                <livewire:vendors.vendor-details :vendor="$vendor" :expanded="true" />
            </div>

            {{-- VENDOR TASKS --}}
            @if($vendor->business_type != 'Retail')
                <div class="col-span-4 lg:col-span-2">
                    <livewire:vendors.vendor-task-list :vendor="$vendor" :key="'vendor-tasks-'.$vendor->id" />
                </div>
            @endif

            {{-- EXPENSES --}}
            @if(in_array($vendor->business_type, ["Retail"]))
                <div class="col-span-4 lg:col-span-2 space-y-4">
                    <livewire:expenses.expense-index :expense_vendor="$vendor->id" :view="'vendors.show'" />
                </div>
            @endif
        </div>

        @if($vendor->business_type != 'Retail')
            <div class="col-span-4 lg:col-span-2 space-y-4">
                {{-- VENDOR TEAM MEMBERS --}}
                <livewire:users.users-index :vendor="$vendor" :view="'vendors.show'"/>

                {{-- VENDOR CHECKS --}}
                @if($vendor->checks()->count() > 0 )
                    <livewire:checks.checks-index :vendor="$vendor->id" :view="'vendors.show'" />
                @endif

                {{-- EMAILS SENT TO THIS VENDOR (lien waiver signing requests) --}}
                <livewire:vendors.vendor-payment-email-tracking-table
                    :vendor-id="$vendor->id"
                    :templates="['Lien Waiver Signing Request']"
                    :key="'vendor-emails-'.$vendor->id" />

                {{-- VENDOR FINANCES --}}
                {{-- INSURANCE --}}
                @can('update', $vendor)
                    @if(in_array($vendor->business_type, ["Sub", "DBA"]))
                        <livewire:vendor-docs.vendor-docs-card :vendor="$vendor" :view="true" lazy />
                    @endif 
                    {{-- <livewire:vendors.vendor-finances :vendor="$vendor" /> --}}
                @endcan
            </div>
        @endif
	</div>
    <livewire:vendor-docs.vendor-doc-create />
    {{-- <livewire:users.user-create />
    <livewire:clients.client-create />
     --}}
</div>
