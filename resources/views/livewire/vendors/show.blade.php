<x-page.shell
    :cols="4"
    :breadcrumbs="[
        ['label' => 'Vendors', 'href' => route('vendors.index')],
        ['label' => html_entity_decode((string) $vendor->name, ENT_QUOTES, 'UTF-8')],
    ]"
>
    <x-page.column :span="2">
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
    </x-page.column>

        @if($vendor->business_type != 'Retail')
    <x-page.column :span="2">
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
    </x-page.column>
        @endif
    <x-slot:offstage>
        <livewire:vendor-docs.vendor-doc-create />
    </x-slot:offstage>
</x-page.shell>
