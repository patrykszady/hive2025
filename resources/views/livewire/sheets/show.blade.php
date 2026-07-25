<div class="max-w-xl space-y-2 sm:px-6">
    {{-- HEADER --}}
    <x-details.card title="Profit and Loss" :subheading="date('F j, Y', strtotime($start_date)) . ' – ' . date('F j, Y', strtotime($end_date))">
        <x-slot:header_buttons>
            <flux:dropdown>
                <flux:button size="sm" icon:trailing="chevron-down">Export</flux:button>
                <flux:menu>
                    <flux:menu.item wire:click="export_csv" icon="table-cells">Excel</flux:menu.item>
                    <flux:menu.item wire:click="export_pdf" icon="document-text">PDF</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </x-slot:header_buttons>
    </x-details.card>

    {{-- UNCATEGORIZED EXPENSES --}}
    @php $uncatExpenses = $this->uncategorizedExpenses(); @endphp
    @if($uncatExpenses->isNotEmpty())
    <x-details.card title="Uncategorized Expenses" :expanded="true" :details_text="false" :separator="false">
        <x-slot:header_buttons>
            <flux:badge color="amber" size="sm">{{ $uncatExpenses->count() }} expenses</flux:badge>
        </x-slot:header_buttons>
        <x-slot name="subheading">
            <span class="text-sm italic text-zinc-500 dark:text-zinc-400">
                Expenses missing a category. Select a category to assign it.
            </span>
        </x-slot>
        <x-slot:details>
            @foreach($uncatExpenses as $expense)
                <div wire:key="uncat-{{ $expense->id }}" class="flex items-center justify-between gap-3 px-3 py-2 border-b border-zinc-100 dark:border-zinc-700 last:border-b-0">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ money($expense->amount) }}</span>
                            <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $expense->date->format('m/d/Y') }}</span>
                        </div>
                        <div class="text-sm text-zinc-600 dark:text-zinc-300 truncate">
                            @if($expense->vendor)
                                <a wire:navigate.hover href="{{ route('vendors.show', $expense->vendor_id) }}" class="hover:underline">{{ $expense->vendor->business_name }}</a>
                            @else
                                <span class="text-zinc-400">No Vendor</span>
                            @endif
                        </div>
                    </div>
                    <div class="shrink-0 w-56" x-data="{ categoryId: null }" x-effect="if (categoryId) { $wire.categorizeExpense({{ $expense->id }}, categoryId) }">
                        <flux:select size="sm" variant="listbox" searchable placeholder="Select category..." x-model="categoryId">
                            @foreach($this->availableCategories() as $category)
                                <flux:select.option value="{{ $category->id }}">{{ $category->friendly_primary }} — {{ $category->friendly_detailed }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                </div>
            @endforeach
        </x-slot:details>
        <x-slot:footer>
            <flux:badge size="sm" color="amber">{{ money($uncatExpenses->sum('amount')) }}</flux:badge>
        </x-slot:footer>
    </x-details.card>
    @endif

    {{-- INCOME --}}
    <x-details.card title="Income">
        <x-slot:header_buttons>
            <flux:badge color="blue" size="sm">{{ money($this->revenue()) }}</flux:badge>
        </x-slot:header_buttons>
    </x-details.card>

    {{-- COST OF GOODS SOLD --}}
    <x-details.card title="Cost of Goods Sold" :expanded="true" :details_text="false" :separator="false">
        <x-slot:header_buttons>
            <flux:badge color="red" size="sm">{{ money($this->costOfLaborSum() + $this->costOfMaterialsSum()) }}</flux:badge>
        </x-slot:header_buttons>

        <x-slot:details>
            <div class="space-y-2">
                {{-- COST OF LABOR --}}
                <x-details.card title="Cost of Labor" :expanded="false" :details_text="false" :separator="false">
                    <x-slot:header_buttons>
                        <flux:badge size="sm">{{ money($this->costOfLaborSum()) }}</flux:badge>
                    </x-slot:header_buttons>
                    <x-slot:details>
                        <div class="-mx-2">
                            <flux:table>
                                <flux:table.rows>
                                    @foreach($this->costOfLaborVendors as $vendor_name => $cost_of_labor_vendor)
                                        @php $laborSum = round((float) $cost_of_labor_vendor->sum('amount'), 2); @endphp
                                        @if($laborSum == 0.0) @continue @endif
                                        <flux:table.row :key="'labor-' . $loop->index">
                                            <flux:table.cell>
                                                <a wire:navigate.hover href="{{ route('vendors.show', $cost_of_labor_vendor->first()->vendor_id) }}" target="_blank" class="hover:text-indigo-600 dark:hover:text-indigo-400">
                                                    {!! $vendor_name !!}
                                                </a>
                                            </flux:table.cell>
                                            <flux:table.cell variant="strong" class="text-right">{{ money($laborSum) }}</flux:table.cell>
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        </div>
                    </x-slot:details>
                </x-details.card>

                {{-- COST OF MATERIALS --}}
                <x-details.card title="Cost of Materials" :expanded="false" :details_text="false" :separator="false">
                    <x-slot:header_buttons>
                        <flux:badge size="sm">{{ money($this->costOfMaterialsSum()) }}</flux:badge>
                    </x-slot:header_buttons>
                    <x-slot:details>
                        <div class="-mx-2">
                            <flux:table>
                                <flux:table.rows>
                                    @foreach($this->costOfMaterialsVendors() as $vendor_name => $cost_of_materials_vendors)
                                        @php $materialsSum = round((float) $cost_of_materials_vendors->sum('amount'), 2); @endphp
                                        @if($materialsSum == 0.0) @continue @endif
                                        <flux:table.row :key="'materials-' . $loop->index">
                                            <flux:table.cell>
                                                <a wire:navigate.hover href="{{ route('vendors.show', $cost_of_materials_vendors->first()->vendor_id) }}" target="_blank" class="hover:text-indigo-600 dark:hover:text-indigo-400">
                                                    {!! $vendor_name !!}
                                                </a>
                                            </flux:table.cell>
                                            <flux:table.cell variant="strong" class="text-right">{{ money($materialsSum) }}</flux:table.cell>
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        </div>
                    </x-slot:details>
                </x-details.card>
            </div>
        </x-slot:details>
    </x-details.card>

    {{-- GROSS PROFIT --}}
    @php $grossProfit = $this->revenue() - $this->costOfLaborSum() - $this->costOfMaterialsSum(); @endphp
    <x-details.card title="Gross Profit" :expanded="true" :details_text="false" :separator="false">
        <x-slot:header_buttons>
            <flux:badge color="blue" size="sm">{{ money($grossProfit) }}</flux:badge>
        </x-slot:header_buttons>
        <x-slot:details>
            <div class="-mx-2">
                <flux:table>
                    <flux:table.rows>
                        <flux:table.row>
                            <flux:table.cell>Total Income</flux:table.cell>
                            <flux:table.cell variant="strong" class="text-right">{{ money($this->revenue()) }}</flux:table.cell>
                        </flux:table.row>
                        <flux:table.row>
                            <flux:table.cell>Total Cost of Goods Sold</flux:table.cell>
                            <flux:table.cell variant="strong" class="text-right">-{{ money($this->costOfLaborSum() + $this->costOfMaterialsSum()) }}</flux:table.cell>
                        </flux:table.row>
                    </flux:table.rows>
                </flux:table>
            </div>
        </x-slot:details>
    </x-details.card>

    {{-- EXPENSES --}}
    @php $totalExpenses = $this->generalExpenses() + $this->uncategorizedTransactionsSum(); @endphp
    <x-details.card title="Expenses" :expanded="true" :details_text="false" :separator="false">
        <x-slot:header_buttons>
            <flux:badge color="red" size="sm">{{ money($totalExpenses) }}</flux:badge>
        </x-slot:header_buttons>

        <x-slot:details>
            <div class="space-y-2">
                @foreach($this->sortedExpenseCategories() as $category_primary_name => $category_data)
                    @php $categorySum = round((float) $category_data['sum'], 2); @endphp
                    @if($categorySum == 0.0) @continue @endif

                    <x-details.card title="{{ $category_primary_name }}" :expanded="false" :details_text="false" :separator="false">
                        <x-slot:header_buttons>
                            <flux:badge size="sm">{{ money($categorySum) }}</flux:badge>
                        </x-slot:header_buttons>
                        <x-slot:details>
                            <div class="-mx-2">
                                <flux:table>
                                    <flux:table.rows>
                                        @foreach($category_data['subcategories'] as $subcategory)
                                            @php $subSum = round((float) $subcategory['sum'], 2); @endphp
                                            @if($subSum == 0.0) @continue @endif

                                            {{-- Subcategory row --}}
                                            <flux:table.row :key="'sub-' . $loop->index">
                                                <flux:table.cell variant="strong">{!! $subcategory['name'] !!}</flux:table.cell>
                                                <flux:table.cell variant="strong" class="text-right">{{ money($subSum) }}</flux:table.cell>
                                            </flux:table.row>

                                            {{-- Vendor rows --}}
                                            @foreach($subcategory['vendors'] as $vendor_data)
                                                @php $vendSum = round((float) $vendor_data['sum'], 2); @endphp
                                                @if($vendSum == 0.0) @continue @endif
                                                <flux:table.row :key="'vend-' . $loop->parent->index . '-' . $loop->index">
                                                    <flux:table.cell class="!pl-10">
                                                        @if(isset($vendor_data['vendor_id']))
                                                            <a href="{{ route('vendors.show', $vendor_data['vendor_id']) }}" target="_blank" class="hover:text-indigo-600 dark:hover:text-indigo-400">
                                                                {!! $vendor_data['name'] !!}
                                                            </a>
                                                        @else
                                                            {!! $vendor_data['name'] !!}
                                                        @endif
                                                    </flux:table.cell>
                                                    <flux:table.cell class="text-right">{{ money($vendSum) }}</flux:table.cell>
                                                </flux:table.row>
                                            @endforeach
                                        @endforeach
                                    </flux:table.rows>
                                </flux:table>
                            </div>
                        </x-slot:details>
                    </x-details.card>
                @endforeach

                {{-- UNCATEGORIZED TRANSACTIONS --}}
                @php $uncatTransSum = $this->uncategorizedTransactionsSum(); @endphp
                @if($uncatTransSum != 0)
                    <x-details.card title="Uncategorized" :expanded="false" :details_text="false" :separator="false">
                        <x-slot:header_buttons>
                            <flux:badge size="sm" color="amber">{{ money($uncatTransSum) }}</flux:badge>
                        </x-slot:header_buttons>
                        <x-slot:details>
                            <div class="-mx-2">
                                <flux:table>
                                    <flux:table.rows>
                                        @foreach($this->transactions_no_associations as $txn)
                                            <flux:table.row :key="'uncat-txn-' . $txn->id">
                                                <flux:table.cell>
                                                    <div>
                                                        <span class="text-sm">{{ $txn->plaid_merchant_name ?: (is_array($txn->details) ? ($txn->details['name'] ?? 'Unknown') : ($txn->details ?: 'Unknown')) }}</span>
                                                        <span class="text-xs text-zinc-500 dark:text-zinc-400 ml-2">{{ date('m/d/Y', strtotime($txn->transaction_date)) }}</span>
                                                    </div>
                                                </flux:table.cell>
                                                <flux:table.cell variant="strong" class="text-right">{{ money($txn->amount) }}</flux:table.cell>
                                            </flux:table.row>
                                        @endforeach
                                    </flux:table.rows>
                                </flux:table>
                            </div>
                        </x-slot:details>
                    </x-details.card>
                @endif
            </div>
        </x-slot:details>
    </x-details.card>

    {{-- NET INCOME --}}
    @php $netIncome = $this->revenue() - $this->costOfLaborSum() - $this->costOfMaterialsSum() - $totalExpenses; @endphp
    <x-details.card title="Net Income">
        <x-slot:header_buttons>
            <flux:badge color="{{ $netIncome >= 0 ? 'green' : 'red' }}">
                {{ money($netIncome) }}
            </flux:badge>
        </x-slot:header_buttons>
    </x-details.card>
</div>