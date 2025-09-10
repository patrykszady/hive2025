<div class="max-w-xl space-y-2 sm:px-6">
    {{-- HEADER SECTION --}}
    <flux:card>
        <div class="flex justify-between items-center">
            <div>
                <flux:heading size="lg" class="mb-0">Sheets</flux:heading>
                <flux:subheading class="text-zinc-500">{{ date('m/d/Y', strtotime($start_date)) }} to {{ date('m/d/Y', strtotime($end_date)) }}</flux:subheading>
            </div>
            <flux:button wire:click="export_csv">
                Export CSV
            </flux:button>
        </div>
    </flux:card>
    
    {{-- REVENUE SUMMARY --}}
    <x-details.card title="Revenue" :canEdit="true">
        <x-slot:header_buttons>
            <flux:button
                size="sm"
                variant="primary" 
                color="blue"
                disabled
                >
                {{ money($this->revenue()) }}
            </flux:button>
        </x-slot:header_buttons>
    </x-details.card>

    {{-- COST OF REVENUE --}}
    <x-details.card title="Cost of Revenue" :expanded="false" :details_text="false">
        <x-slot:details>
            {{-- Add padding container for nested cards --}}
            <div class="space-y-2">
                {{-- COST OF LABOR SECTION AS CARD --}}
                <div>
                    <x-details.card title="Cost of Labor" :expanded="false" :details_text="false">
                        <x-slot:details>
                            @foreach($this->costOfLaborVendors as $vendor_name => $cost_of_labor_vendor)
                                @php $laborSum = round((float) $cost_of_labor_vendor->sum('amount'), 2); @endphp
                                @if($laborSum == 0.0)
                                    @continue
                                @endif
                                <x-details.row 
                                    title="{!! $vendor_name !!}" 
                                    :content="money($laborSum)"
                                    :right-align="true"
                                    :href="route('vendors.show', $cost_of_labor_vendor->first()->vendor_id)"
                                />
                            @endforeach
                        </x-slot:details>
                        <x-slot:footer>
                            <flux:button
                                size="sm"
                                disabled
                            >
                                {{ money($this->costOfLaborSum()) }}
                            </flux:button>
                        </x-slot:footer>
                    </x-details.card>
                </div>
                
                {{-- COST OF MATERIALS SECTION AS CARD --}}
                <div>
                    <x-details.card title="Cost of Materials" :expanded="false" :details_text="false">
                        <x-slot:details>
                            @foreach($this->costOfMaterialsVendors() as $vendor_name => $cost_of_materials_vendors)
                                @php $materialsSum = round((float) $cost_of_materials_vendors->sum('amount'), 2); @endphp
                                @if($materialsSum == 0.0)
                                    @continue
                                @endif
                                <x-details.row 
                                    title="{!! $vendor_name !!}" 
                                    :content="money($materialsSum)" 
                                    :right-align="true"
                                    :href="route('vendors.show', $cost_of_materials_vendors->first()->vendor_id)"
                                />
                            @endforeach
                        </x-slot:details>
                        <x-slot:footer>
                            <flux:button
                                size="sm"
                                disabled
                            >
                                {{ money($this->costOfMaterialsSum()) }}
                            </flux:button>
                        </x-slot:footer>
                    </x-details.card>
                </div>
            </div>
        </x-slot:details>
        
        <x-slot:footer>
            <flux:button
                size="sm"
                variant="primary" 
                color="red"
                disabled
            >
                {{ money($this->costOfLaborSum() + $this->costOfMaterialsSum()) }}
            </flux:button>
        </x-slot:footer>
    </x-details.card>

    {{-- GROSS PROFIT --}}
    <x-details.card title="Gross Profit" :expanded="false" :details_text="false">
        <x-slot:details>
            <x-details.row title="Revenue" :content="money($this->revenue())" :right-align="true" />
            <x-details.row title="Cost of Revenue" :content="'-' . money($this->costOfLaborSum() + $this->costOfMaterialsSum())" :right-align="true" />
        </x-slot:details>
        <x-slot:footer>
            <flux:button
                size="sm"
                variant="primary" 
                color="blue"
                disabled
            >
                {{ money($this->revenue() - $this->costOfLaborSum() - $this->costOfMaterialsSum()) }}
            </flux:button>
        </x-slot:footer>
    </x-details.card>

    {{-- GENERAL & ADMINISTRATIVE EXPENSES --}}
    <x-details.card title="General & Administrative Expenses" :expanded="false" :details_text="false">
        <x-slot:details>
            <div class="space-y-2"> {{-- Space between primary categories --}}
                @foreach($this->sortedExpenseCategories() as $category_primary_name => $category_data)
                    @php $categorySum = round((float) $category_data['sum'], 2); @endphp
                    @if($categorySum == 0.0)
                        @continue
                    @endif
                    {{-- PRIMARY CATEGORY AS CARD --}}
                    <div>
                        <x-details.card title="{{ $category_primary_name }}" :expanded="false" :details_text="false" class="!shadow-none !border-none !bg-transparent">
                            <x-slot:details>
                                {{-- Add padding container for nested cards --}}
                                <div class="space-y-2"> {{-- Added space-y-6 for subcategories --}}
                                    @foreach($category_data['subcategories'] as $subcategory)
                                        @php $subSum = round((float) $subcategory['sum'], 2); @endphp
                                        @if($subSum == 0.0)
                                            @continue
                                        @endif
                                        {{-- Make each detailed category its own card with margin --}}
                                        <div>
                                            <x-details.card title="{!! $subcategory['name'] !!}" :expanded="true" :details_text="false" class="!shadow-none !border-none !bg-transparent">
                                                <x-slot:details>
                                                    @foreach($subcategory['vendors'] as $vendor_data)
                                                        @php $vendSum = round((float) $vendor_data['sum'], 2); @endphp
                                                        @if($vendSum == 0.0)
                                                            @continue
                                                        @endif
                                                        <x-details.row 
                                                            title="{!! $vendor_data['name'] !!}" 
                                                            :content="money($vendSum)" 
                                                            :right-align="true"
                                                            :href="isset($vendor_data['vendor_id']) ? route('vendors.show', $vendor_data['vendor_id']) : null"
                                                        />
                                                    @endforeach
                                                </x-slot:details>
                                                
                                                <x-slot:footer>
                                                    <flux:button
                                                        size="sm"
                                                        disabled
                                                    >
                                                        {{ money($subSum) }}
                                                    </flux:button>
                                                </x-slot:footer>
                                            </x-details.card>
                                        </div>
                                    @endforeach
                                </div>
                            </x-slot:details>
                            
                            <x-slot:footer>
                                <flux:button
                                    size="sm"
                                    disabled
                                >
                                    {{ money($categorySum) }}
                                </flux:button>
                            </x-slot:footer>
                        </x-details.card>
                    </div>
                @endforeach
            </div>
        </x-slot:details>
        
        <x-slot:footer>
            <flux:button
                size="sm"
                variant="primary" 
                color="red"
                disabled
            >
                {{ money($this->generalExpenses()) }}
            </flux:button>
        </x-slot:footer>
    </x-details.card>

    {{-- NET INCOME --}}
    <x-details.card title="Net Income" :canEdit="true">
        <x-slot:header_buttons>
            <flux:button
                variant="primary" 
                color="green"
                disabled
            >
                {{ money($this->revenue() - $this->costOfLaborSum() - $this->costOfMaterialsSum() - $this->generalExpenses()) }}
            </flux:button>
        </x-slot:header_buttons>
    </x-details.card>

    {{-- UNASSIGNED TRANSACTIONS --}}
    {{-- <x-details.card title="Unassigned Transactions" :expanded="false" details_text="Create Expense">
        <x-slot name="subheading">
            <span class="text-sm italic">
                Transactions without a Check or Expense. Assign or create expenses to keep reports accurate.
            </span>
        </x-slot>

        <x-slot name="details">
            <flux:table class="w-full">
                <flux:table.columns>
                    <flux:table.column sortable :sorted="true">Date</flux:table.column>
                    <flux:table.column>Description</flux:table.column>
                    <flux:table.column>Amount</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach($this->transactions_no_associations as $transaction)
                        <flux:table.row :key="$transaction->id" class="border-b-0">
                            <flux:table.cell class="pb-0">
                                {{ $transaction->transaction_date->format('m/d/Y') }}
                            </flux:table.cell>
                            <flux:table.cell class="pb-0">
                                <div class="italic whitespace-normal break-words leading-tight" title="{{ $transaction->plaid_merchant_description }}">
                                    {{ $transaction->plaid_merchant_description ?? '—' }}
                                </div>
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
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </x-slot>
    </x-details.card> --}}
</div>