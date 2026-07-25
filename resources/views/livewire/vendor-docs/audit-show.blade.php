<div class="max-w-lg space-y-4">
    {{-- class="!print:hidden" --}}
    <x-details.card title="Audit" :accordion="false">
        <x-slot:header_buttons>
            <flux:button.group>
                <flux:button
                    wire:click="download_documents"
                    size="sm"
                    {{-- variant="primary" --}}
                >
                    Download Certificates
                </flux:button>
                <flux:dropdown position="bottom" align="end">
                    <flux:button icon-trailing="chevron-down" size="sm"></flux:button>

                    <flux:menu>
                        <flux:menu.item
                            wire:click="export_xlsx"
                            size="sm"
                        >
                            Export Excel
                        </flux:menu.item>
                        <flux:menu.item
                            wire:click="download_bank_statements"
                            size="sm"
                        >
                            Download Bank Statements
                        </flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            </flux:button.group>
        </x-slot:header_buttons>
    </x-details.card>

    {{-- TRANSACTIONS NO CHECKS --}}
    <x-details.card title="Transactions" :expanded="false" details_text="Missing Expenses">
        <x-slot name="subheading">
            <span class="text-sm italic">
                These Check Transactions have not beed added to Vendors or Projects. Please add checks before comleting this Audit.
            </span>
        </x-slot>

        <x-slot name="details">
            <flux:table class="w-full">
                <flux:table.columns>
                    <flux:table.column 
                        sortable 
                        :sorted="true" 
                        {{-- :direction="'asc'" --}}
                        class="w-1/4"
                    >
                        Date
                    </flux:table.column>
                    <flux:table.column>Payment</flux:table.column>
                    <flux:table.column>Amount</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach($this->transactions_no_check as $transaction)
                        <flux:table.row :key="$transaction->id" class="border-b-0">
                            <flux:table.cell class="pb-0">
                                {{ $transaction->transaction_date->format('m/d/Y') }}
                            </flux:table.cell>
                            <flux:table.cell class="pb-0">
                                {{ $transaction->check_number == '1010101' ? 'Transfer' : ($transaction->check_number == '2020202' ? 'Cash' : $transaction->check_number) }}
                            </flux:table.cell>
                            <flux:table.cell class="pb-0">
                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    wire:click="$dispatchTo('expenses.expense-create', 'createExpenseFromTransaction', { transaction: {{ $transaction->id }} })"
                                >
                                    {{ money($transaction->amount) }}
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                        @if(!empty($transaction->plaid_merchant_description))
                            <flux:table.row :key="'desc-' . $transaction->id" class="border-t-0">
                                <flux:table.cell class="pt-0"></flux:table.cell>
                                <flux:table.cell class="pt-0 border-t border-gray-200" colspan="2">
                                    <div class="italic whitespace-normal break-words leading-tight">
                                        {{ $transaction->plaid_merchant_description }}
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endif
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </x-slot>
    </x-details.card>

    {{-- VENDOR CHECKS --}}
    @foreach($this->vendors_grouped_checks as $group)
        <x-details.card 
            :title="$group['vendor']->business_name"
            :title_href="($group['is_user'] ?? false) ? route('users.show', $group['user']->id) : route('vendors.show', $group['vendor']->id)"
            :accordion="false"
        >
            <x-slot:title_extras>
                @if(!empty($group['vendor']->business_type))
                    <flux:badge size="sm" color="zinc" inset="top bottom">{{ $group['vendor']->business_type }}</flux:badge>
                @endif
            </x-slot:title_extras>

            <x-slot name="subheading">
                <span class="text-sm italic">
                    @if(($group['is_user'] ?? false) === true)
                        @php($role = $group['user']?->getRoleForVendor(auth()->user()->vendor->id) ?? 'No Role')
                        @if($role === 'Admin')
                            Stakeholder in the business and is excluded.
                        @else
                            Employee payment subject to audit review.
                        @endif
                    @elseif(!empty($group['vendor']->category_id))
                        Vendor is {{ $group['vendor']->category?->friendly_detailed }} and doesn't require coverage.
                    @elseif($group['vendor']->business_type == 'Retail')
                        Retail and doesn't require coverage.
                    @elseif(isset($group['professional_doc']))
                        Professional policy active ({{ $group['professional_doc']->effective_date->format('m/d/Y') }}–{{ $group['professional_doc']->expiration_date->format('m/d/Y') }})
                    @endif
                </span>

                @if(isset($group['applicable_docs']) && ($group['applicable_docs']?->count() ?? 0) > 0)
                    <div class="space-y-2">
                        <flux:heading size="sm">Policies</flux:heading>
                        <div class="rounded-lg border border-zinc-200 dark:border-white/20 divide-y divide-zinc-200 dark:divide-white/10 overflow-hidden">
                            @foreach($group['applicable_docs'] as $doc)
                                <div class="p-3 flex items-center justify-between">
                                    <div class="space-y-0.5">
                                        <div class="text-sm font-medium">{{ ucfirst($doc->type) }} #{{ $doc->number }}</div>
                                        <div class="text-xs text-zinc-600 dark:text-zinc-300">
                                            {{ $doc->effective_date->format('m/d/Y') }} – {{ $doc->expiration_date->format('m/d/Y') }}
                                            {{-- @if($doc->agent)
                                                • Agent: {{ $doc->agent->name }}
                                            @endif --}}
                                        </div>
                                    </div>
                                    @if(!empty($doc->doc_filename))
                                        <flux:link href="{{ route('vendor_docs.show', $doc->doc_filename) }}" external variant="subtle"><flux:icon.eye variant="mini" /> </flux:link>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-slot>

            <x-slot name="details">
                <flux:table class="w-full">
                    <flux:table.columns>
                        <flux:table.column 
                            sortable 
                            :sorted="true" 
                            {{-- :direction="!($group['is_user'] ?? false) ? ($this->vendorSortDir[$group['vendor']->id] ?? 'asc') : 'asc'" --}}
                        >
                            Date
                        </flux:table.column>
                        <flux:table.column>Payment</flux:table.column>
                        <flux:table.column>Amount</flux:table.column>
                        <flux:table.column>Coverage</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach($group['checks'] as $check)
                            <flux:table.row :key="$check->id">
                                <flux:table.cell>{{ $check->date->format('m/d/Y') }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:link href="{{ route('checks.show', $check->id) }}" external variant="ghost" :accent="false" class="!text-gray-500 !font-normal">
                                        {{ $check->payment_label . ' ' . $check->check_number }}
                                    </flux:link>
                                </flux:table.cell>
                                <flux:table.cell>{{ money($check->amount) }}</flux:table.cell>
                                <flux:table.cell>
                                    @if(($group['is_user'] ?? false) === true)
                                        @php($role = $group['user']?->getRoleForVendor(auth()->user()->vendor->id) ?? 'No Role')
                                        @if($role === 'Admin')
                                            <flux:badge size="sm" color="yellow" inset="top bottom">Not Applicable</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="red" inset="top bottom">Not Covered</flux:badge>
                                        @endif
                                    @else
                                        <flux:badge size="sm" :color="$check->covered ? 'green' : ((isset($group['professional_doc']) || $group['vendor']->business_type == 'Retail' || !empty($group['vendor']->category_id)) ? 'yellow' : 'red')" inset="top bottom">
                                            {{ $check->covered ? 'Covered' : ((isset($group['professional_doc']) || $group['vendor']->business_type == 'Retail' || !empty($group['vendor']->category_id)) ? 'Not Applicable' : 'Not Covered') }}
                                        </flux:badge>
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </x-slot>
        </x-details.card>
    @endforeach
    <livewire:expenses.expense-create />
</div>


