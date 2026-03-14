<div class="flex items-end gap-3">
    {{-- SHEETS TYPE --}}
    <div class="w-1/3">
        <flux:select
            size="sm"
            label="Sheets Type"
            x-on:change="$wire.updateSheetsType($event.target.value)"
        >
            <flux:select.option value="" :selected="! $vendor->sheets_type">General Expenses</flux:select.option>
            <flux:select.option value="Materials" :selected="$vendor->sheets_type === 'Materials'">Materials</flux:select.option>
        </flux:select>
    </div>

    {{-- DEFAULT CATEGORY --}}
    <div class="flex-1 flex items-end gap-2" x-data="{ categoryId: null }">
        <div class="flex-1">
            <flux:select
                size="sm"
                variant="listbox"
                searchable
                clearable
                label="Default Category"
                placeholder="{{ $vendor->category ? $vendor->category->friendly_primary . ' — ' . $vendor->category->friendly_detailed : 'Select category...' }}"
                x-model="categoryId"
            >
                @foreach($this->availableCategories as $category)
                    <flux:select.option value="{{ $category->id }}">
                        {{ $category->friendly_primary }} — {{ $category->friendly_detailed }}
                    </flux:select.option>
                @endforeach
            </flux:select>
        </div>
        <flux:button
            size="sm"
            variant="primary"
            icon="check"
            square
            x-show="categoryId"
            x-cloak
            x-on:click="$wire.updateVendorCategory(categoryId); categoryId = null"
        />
    </div>
</div>

<flux:separator variant="subtle" />

{{-- EXPENSES BY CATEGORY --}}
<flux:heading size="sm">Expenses by Category</flux:heading>

<div>
    <flux:accordion transition>
    @forelse($this->vendorExpenses as $categoryPrimary => $expenses)
        @php
            $categorySum = $expenses->sum('amount');
            $firstExpense = $expenses->first();
            $currentCategoryId = $firstExpense->category_id;
            $uniqueDetailed = $expenses->pluck('category.friendly_detailed')->unique()->filter();
            $singleDetailed = $uniqueDetailed->count() === 1;
            $groupTitle = $singleDetailed
                ? $categoryPrimary . ' — ' . $uniqueDetailed->first()
                : $categoryPrimary;
        @endphp

        <flux:accordion.item>
            <flux:accordion.heading>
                <div class="flex items-center justify-between w-full pr-2">
                    <span>{{ $groupTitle }}</span>
                    <div class="flex items-center gap-2">
                        <flux:badge color="gray" size="sm">{{ $expenses->count() }}</flux:badge>
                        <flux:badge size="sm">{{ money($categorySum) }}</flux:badge>
                    </div>
                </div>
            </flux:accordion.heading>

            <flux:accordion.content>
                <div class="space-y-1">
                    {{-- Reassign this group --}}
                    @if($currentCategoryId)
                        <div x-data="{ newCatId: null }" x-effect="if (newCatId) { $wire.reassignExpenseCategory({{ $currentCategoryId }}, newCatId); newCatId = null }">
                            <flux:select
                                size="sm"
                                variant="listbox"
                                searchable
                                placeholder="Reassign all {{ $expenses->count() }} expenses..."
                                x-model="newCatId"
                            >
                                @foreach($this->availableCategories as $cat)
                                    <flux:select.option value="{{ $cat->id }}">
                                        {{ $cat->friendly_primary }} — {{ $cat->friendly_detailed }}
                                    </flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                    @endif

                    {{-- Individual expenses --}}
                    @foreach($expenses->groupBy(fn($e) => $e->category?->friendly_detailed ?? 'Uncategorized') as $detailed => $detailedExpenses)
                        @if(!$singleDetailed)
                            <div class="py-1">
                                <flux:text class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                                    {{ $detailed }} ({{ $detailedExpenses->count() }}) — {{ money($detailedExpenses->sum('amount')) }}
                                </flux:text>
                            </div>
                        @endif
                        <div x-data="{ showAll: false }">
                            @foreach($detailedExpenses as $index => $expense)
                                <div
                                    class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors"
                                    x-on:click.stop="$wire.dispatchTo('expenses.expense-create', 'editExpense', { expense: {{ $expense->id }} })"
                                    @if($index >= 10) x-show="showAll" x-cloak x-collapse @endif
                                >
                                    <x-details.row
                                        title="{{ $expense->date->format('M j, Y') }}"
                                        :content="money($expense->amount)"
                                        :right-align="true"
                                    />
                                </div>
                            @endforeach
                            @if($detailedExpenses->count() > 10)
                                <div class="py-1" x-show="!showAll" x-cloak>
                                    <button type="button" x-on:click.stop="showAll = true" class="text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300 italic cursor-pointer transition-colors">
                                        + {{ $detailedExpenses->count() - 10 }} more expenses
                                    </button>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </flux:accordion.content>
        </flux:accordion.item>
    @empty
        <div class="px-3 py-2">
            <flux:text class="text-sm text-zinc-400 italic">No expenses found for this vendor.</flux:text>
        </div>
    @endforelse
    </flux:accordion>
</div>
