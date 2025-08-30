<div class="max-w-lg space-y-4">
    <div class="print:hidden">
    <x-details.card title="Audit" :accordion="false">
        <x-slot:header_buttons>
            <flux:button wire:click="download_documents" variant="primary">
                Download Certificates
            </flux:button>
            <flux:button wire:click="export_xlsx">
                Export XLSX
            </flux:button>
        </x-slot:header_buttons>
    </x-details.card>
    </div>

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
                        :direction="'asc'"
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
                            <flux:table.cell class="pb-0">{{ money($transaction->amount) }}</flux:table.cell>
                        </flux:table.row>
                        @if(!empty($transaction->plaid_merchant_description))
                            <flux:table.row :key="'desc-' . $transaction->id" class="border-t-0">
                                <flux:table.cell class="pt-0"></flux:table.cell>
                                <flux:table.cell class="pt-0 border-t border-gray-200" colspan="2">
                                    <div class="italic whitespace-normal break-words leading-tight" title="{{ $transaction->plaid_merchant_description }}">
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
        <x-details.card :title="$group['vendor']->business_name" :title_href="route('vendors.show', $group['vendor']->id)" :accordion="false">
            <x-slot name="subheading">
                <span class="text-sm italic">
                    @if($group['vendor']->business_type == 'Retail')
                        {{ $group['vendor']->business_name }} is Retail and doesn't require coverage.
                    @elseif(isset($group['professional_doc']))
                        Professional policy active ({{ $group['professional_doc']->effective_date->format('m/d/Y') }}–{{ $group['professional_doc']->expiration_date->format('m/d/Y') }})
                    @endif
                </span>
            </x-slot>

            <x-slot name="details">
                <flux:table class="w-full">
                    <flux:table.columns>
                        <flux:table.column 
                            sortable 
                            :sorted="true" 
                            :direction="$this->vendorSortDir[$group['vendor']->id] ?? 'asc'"
                        >
                            Date
                        </flux:table.column>
                        <flux:table.column>Payment</flux:table.column>
                        <flux:table.column>Amount</flux:table.column>
                        <flux:table.column><span class="block text-right">Coverage</span></flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach($group['checks'] as $check)
                            <flux:table.row :key="$check->id">
                                <flux:table.cell>{{ $check->date->format('m/d/Y') }}</flux:table.cell>
                                <flux:table.cell>
                                    {{ $check->payment_type }}
                                </flux:table.cell>
                                <flux:table.cell>{{ money($check->amount) }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" :color="$check->covered ? 'green' : ((isset($group['professional_doc']) || $group['vendor']->business_type == 'Retail') ? 'yellow' : 'red')" inset="top bottom">
                                        {{ $check->covered ? 'Covered' : ((isset($group['professional_doc']) || $group['vendor']->business_type == 'Retail') ? 'Not Applicable' : 'Not Covered') }}
                                    </flux:badge>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </x-slot>
        </x-details.card>
    @endforeach
</div>
