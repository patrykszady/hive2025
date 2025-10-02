@props(['receipt', 'selectedSplit' => null, 'expenseMismatch' => false, 'expenseAmount' => null])

@if(!$receipt->receipt_items || empty($receipt->receipt_items['items'] ?? []))
    <div class="flow-root">
        <pre style="background-color:transparent; overflow: auto;">{!! $receipt->receipt_html !!}</pre>
    </div>
@else
    <div class="overflow-hidden -mx-5">
        <flux:table class="table-fixed w-full">
            <flux:table.columns>
                <flux:table.column class="w-[45%] !pl-5 pr-5">Desc</flux:table.column>
                <flux:table.column class="w-[20%]" align="end">Price</flux:table.column>
                <flux:table.column class="w-[10%]" align="end">Qty</flux:table.column>
                <flux:table.column class="w-[25%] !pr-5" align="end">Total</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach($receipt->receipt_items['items'] as $index => $line_item)
                    <flux:table.row 
                        wire:key="receipt-desc-{{ $index }}"
                        class="transition-colors duration-150 !border-none {{ ($selectedSplit && isset($selectedSplit->receipt_items[$index]) && (($selectedSplit->receipt_items[$index]['checkbox'] ?? false) === true)) ? 'bg-blue-50 dark:bg-blue-900/10 print:!bg-transparent' : '' }}"
                    >
                        <flux:table.cell colspan="4" class="!pl-5 !pr-5 !pb-0" title="{{$line_item['Description'] ?? ''}}">
                            <div class="truncate w-full transition-opacity transition-colors duration-150 {{ ($selectedSplit && isset($selectedSplit->receipt_items[$index]) && (($selectedSplit->receipt_items[$index]['checkbox'] ?? false) !== true)) ? 'text-gray-300 line-through opacity-50' : '' }}">
                                {{$line_item['Description'] ?? ''}}
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                    
                    <flux:table.row 
                        wire:key="receipt-data-{{ $index }}"
                        class="transition-colors duration-150 !py-0 {{ ($selectedSplit && isset($selectedSplit->receipt_items[$index]) && (($selectedSplit->receipt_items[$index]['checkbox'] ?? false) === true)) ? 'bg-blue-50 dark:bg-blue-900/10 print:!bg-transparent' : '' }} {{ $loop->last ? '' : 'border-b border-zinc-800/15 dark:border-white/20' }}">
                        <flux:table.cell class="!pl-5" align="end">
                            <span class="transition-opacity transition-colors duration-150 {{ ($selectedSplit && isset($selectedSplit->receipt_items[$index]) && (($selectedSplit->receipt_items[$index]['checkbox'] ?? false) !== true)) ? 'text-gray-300 line-through opacity-50' : '' }}">
                                @if(isset($receipt->expense->vendor) && $receipt->expense->vendor->sku_search_url)
                                    <flux:link 
                                        href="{{ $receipt->expense->vendor->sku_search_url . ($line_item['ProductCode'] ?? '') }}" 
                                        external
                                        class="italic {{ ($selectedSplit && isset($selectedSplit->receipt_items[$index]) && (($selectedSplit->receipt_items[$index]['checkbox'] ?? false) !== true)) ? '!text-gray-300' : '' }}"
                                        variant="subtle"
                                    >
                                        {{$line_item['ProductCode'] ?? ''}}
                                    </flux:link>
                                @else
                                    <i>{{$line_item['ProductCode'] ?? ''}}</i>
                                @endif
                            </span>
                        </flux:table.cell>
                        
                        <flux:table.cell align="end">
                            <span class="transition-opacity transition-colors duration-150 {{ ($selectedSplit && isset($selectedSplit->receipt_items[$index]) && (($selectedSplit->receipt_items[$index]['checkbox'] ?? false) !== true)) ? 'text-gray-300 line-through opacity-50' : '' }}">
                                {{money($line_item['Price'] ?? 0)}}
                            </span>
                        </flux:table.cell>
                        
                        <flux:table.cell align="end">
                            <span class="transition-opacity transition-colors duration-150 {{ ($selectedSplit && isset($selectedSplit->receipt_items[$index]) && (($selectedSplit->receipt_items[$index]['checkbox'] ?? false) !== true)) ? 'text-gray-300 line-through opacity-50' : '' }}">
                                {{$line_item['Quantity'] ?? 0}}
                            </span>
                        </flux:table.cell>
                        
                        <flux:table.cell variant="strong" class="!pr-5" align="end">
                            <span class="transition-opacity transition-colors duration-150 {{ ($selectedSplit && isset($selectedSplit->receipt_items[$index]) && (($selectedSplit->receipt_items[$index]['checkbox'] ?? false) !== true)) ? 'text-gray-300 line-through opacity-50' : '' }}">
                                {{money($line_item['TotalPrice'] ?? 0)}}
                            </span>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach

                <flux:table.row>
                    <flux:table.cell colspan="3" align="end" class="font-medium">Subtotal</flux:table.cell>
                    <flux:table.cell class="!pr-5" align="end">{{money($receipt->receipt_items['subtotal'] ?? 0)}}</flux:table.cell>
                </flux:table.row>

                <flux:table.row>
                    <flux:table.cell colspan="3" align="end" class="font-medium">Tax</flux:table.cell>
                    <flux:table.cell class="!pr-5" align="end">{{money($receipt->receipt_items['total_tax'] ?? 0)}}</flux:table.cell>
                </flux:table.row>



                <flux:table.row>
                    <flux:table.cell colspan="3" align="end" class="font-medium {{ (($selectedSplit && isset($selectedSplit->amount)) || $expenseMismatch) ? '!text-gray-300 line-through' : '' }}">Total</flux:table.cell>
                    <flux:table.cell variant="strong" class="!pr-5 {{ (($selectedSplit && isset($selectedSplit->amount)) || $expenseMismatch) ? '!text-gray-300 line-through' : '' }}" align="end">{{money($receipt->receipt_items['total'] ?? 0)}}</flux:table.cell>
                </flux:table.row>

                @if($selectedSplit && isset($selectedSplit->amount))
                    <flux:table.row>
                        <flux:table.cell colspan="3" align="end" class="font-medium">Split Total</flux:table.cell>
                        <flux:table.cell variant="strong" class="!pr-5" align="end">{{ money($selectedSplit->amount) }}</flux:table.cell>
                    </flux:table.row>
                @elseif($expenseMismatch)
                    <flux:table.row>
                        <flux:table.cell colspan="3" align="end" class="font-medium">Expense Total</flux:table.cell>
                        <flux:table.cell variant="strong" class="!pr-5" align="end">{{ money($expenseAmount) }}</flux:table.cell>
                    </flux:table.row>
                @endif
            </flux:table.rows>
        </flux:table>
    </div>
@endif