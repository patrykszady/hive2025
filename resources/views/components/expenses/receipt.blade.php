@props(['receipt', 'selectedSplit' => null, 'expenseMismatch' => false, 'expenseAmount' => null, 'compactNotes' => true, 'showNotes' => true])

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
                        class="transition-colors duration-150 {{ $loop->first ? '!border-none' : 'border-t border-zinc-800/15 dark:border-white/20 !border-b-0' }} {{ ($selectedSplit && isset($selectedSplit->receipt_items[$index]) && (($selectedSplit->receipt_items[$index]['checkbox'] ?? false) === true)) ? 'bg-indigo-50 dark:bg-indigo-900/10 print:!bg-transparent' : '' }}"
                    >
                        <flux:table.cell colspan="4" class="!pl-5 !pr-5 !py-1" title="{{$line_item['Description'] ?? ''}}">
                            <div class="flex items-center gap-2 transition-opacity transition-colors duration-150 {{ ($selectedSplit && isset($selectedSplit->receipt_items[$index]) && (($selectedSplit->receipt_items[$index]['checkbox'] ?? false) !== true)) ? 'text-gray-300 line-through opacity-50' : '' }}">
                                @if(!empty($line_item['image_url']))
                                    <button
                                        type="button"
                                        class="shrink-0 cursor-pointer"
                                        wire:click="selectReceiptItem({{ $receipt->id }}, {{ $index }})"
                                    >
                                        <img src="{{ $line_item['image_url'] }}" alt="{{ $line_item['Description'] ?? '' }}" class="size-10 rounded object-contain bg-white dark:bg-white" loading="lazy" referrerpolicy="no-referrer" />
                                    </button>
                                @endif
                                <button
                                    type="button"
                                    class="min-w-0 truncate text-left"
                                    wire:click="selectReceiptItem({{ $receipt->id }}, {{ $index }})"
                                >{{$line_item['Description'] ?? ''}}</button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                    
                    <flux:table.row 
                        wire:key="receipt-data-{{ $index }}"
                        class="receipt-row-data transition-colors duration-150 !py-0 !border-none {{ ($selectedSplit && isset($selectedSplit->receipt_items[$index]) && (($selectedSplit->receipt_items[$index]['checkbox'] ?? false) === true)) ? 'bg-indigo-50 dark:bg-indigo-900/10 print:!bg-transparent' : '' }}">
                        <flux:table.cell class="!pl-5">
                            <div class="flex items-center gap-2">
                                <div class="shrink-0 w-10"></div>
                                <span class="transition-opacity transition-colors duration-150 {{ ($selectedSplit && isset($selectedSplit->receipt_items[$index]) && (($selectedSplit->receipt_items[$index]['checkbox'] ?? false) !== true)) ? 'text-gray-300 line-through opacity-50' : '' }}">
                                @if(isset($receipt->expense->vendor) && $receipt->expense->vendor->sku_search_url)
                                    <flux:link 
                                        href="{{ $receipt->expense->vendor->sku_search_url . ($line_item['VendorCode'] ?? $line_item['ProductCode'] ?? '') }}" 
                                        external
                                        class="italic {{ ($selectedSplit && isset($selectedSplit->receipt_items[$index]) && (($selectedSplit->receipt_items[$index]['checkbox'] ?? false) !== true)) ? '!text-gray-300' : '' }}"
                                        variant="subtle"
                                    >
                                        {{$line_item['ManufacturerPartNumber'] ?? $line_item['VendorCode'] ?? $line_item['ProductCode'] ?? ''}}
                                    </flux:link>
                                @elseif(!empty($line_item['product_url']))
                                    <flux:link 
                                        href="{{ $line_item['product_url'] }}" 
                                        external
                                        class="italic {{ ($selectedSplit && isset($selectedSplit->receipt_items[$index]) && (($selectedSplit->receipt_items[$index]['checkbox'] ?? false) !== true)) ? '!text-gray-300' : '' }}"
                                        variant="subtle"
                                    >
                                        {{$line_item['ManufacturerPartNumber'] ?? $line_item['VendorCode'] ?? $line_item['ProductCode'] ?? ''}}
                                    </flux:link>
                                @else
                                    <i>{{$line_item['ManufacturerPartNumber'] ?? $line_item['VendorCode'] ?? $line_item['ProductCode'] ?? ''}}</i>
                                @endif
                                </span>
                            </div>
                        </flux:table.cell>
                        
                        <flux:table.cell align="end">
                            <span class="transition-opacity transition-colors duration-150 {{ ($selectedSplit && isset($selectedSplit->receipt_items[$index]) && (($selectedSplit->receipt_items[$index]['checkbox'] ?? false) !== true)) ? 'text-gray-300 line-through opacity-50' : '' }}">
                                {{money($line_item['Price'] ?? 0)}}
                            </span>
                        </flux:table.cell>
                        
                        <flux:table.cell align="end">
                            <span class="transition-opacity transition-colors duration-150 {{ ($selectedSplit && isset($selectedSplit->receipt_items[$index]) && (($selectedSplit->receipt_items[$index]['checkbox'] ?? false) !== true)) ? 'text-gray-300 line-through opacity-50' : '' }}">
                                {{$line_item['Quantity'] ?? 0}}{{ !empty($line_item['Unit']) ? ' ' . $line_item['Unit'] : '' }}
                            </span>
                        </flux:table.cell>
                        
                        <flux:table.cell variant="strong" class="!pr-5" align="end">
                            <span class="transition-opacity transition-colors duration-150 {{ ($selectedSplit && isset($selectedSplit->receipt_items[$index]) && (($selectedSplit->receipt_items[$index]['checkbox'] ?? false) !== true)) ? 'text-gray-300 line-through opacity-50' : '' }}">
                                {{money($line_item['TotalPrice'] ?? 0)}}
                            </span>
                        </flux:table.cell>
                    </flux:table.row>

                    @php($itemDate = $receipt->is_material_order ? ($line_item['ETA'] ?? null) : null)
                    @php($itemStatus = $receipt->is_material_order ? ($line_item['Status'] ?? null) : null)
                    @php($itemNotes = $receipt->is_material_order ? ($line_item['Notes'] ?? $line_item['notes'] ?? null) : null)
                    @if($itemStatus)
                        @php(
                            $normalizedStatus = match (true) {
                                in_array(strtolower(trim($itemStatus)), ['back ord', 'back order', 'bo', 'backorder', 'b/o'], true) => 'back order',
                                str_starts_with(strtolower(trim($itemStatus)), 'received (was') => 'available',
                                str_starts_with(strtolower(trim($itemStatus)), 'availabl') || strtolower(trim($itemStatus)) === 'available' => 'available',
                                in_array(strtolower(trim($itemStatus)), ['open', 'open item', 'open line'], true) => 'open',
                                str_starts_with(strtolower(trim($itemStatus)), 'received') || in_array(strtolower(trim($itemStatus)), ['recv', 'rec', 'delivered'], true) => 'received',
                                str_starts_with(strtolower(trim($itemStatus)), 'transfer arrived') || str_starts_with(strtolower(trim($itemStatus)), 'transfer') => 'received',
                                in_array(strtolower(trim($itemStatus)), ['shipped', 'ship'], true) => 'shipped',
                                in_array(strtolower(trim($itemStatus)), ['partial', 'partially shipped'], true) => 'partial',
                                in_array(strtolower(trim($itemStatus)), ['cancelled', 'cancel', 'canceled'], true) => 'cancelled',
                                default => strtolower(trim($itemStatus)),
                            }
                        )
                        @if($normalizedStatus === 'back order' && !empty($itemDate) && !\Carbon\Carbon::parse($itemDate)->startOfDay()->gt(now(auth()->user()?->vendor?->timezone ?? config('app.timezone'))->startOfDay()))
                            @php($normalizedStatus = 'available')
                        @endif
                    @endif
                    @if(!empty($line_item['Area']) || !empty($itemDate) || !empty($itemStatus) || !empty($itemNotes))
                        <flux:table.row
                            wire:key="receipt-meta-{{ $index }}"
                            class="receipt-row-meta transition-colors duration-150 !border-none {{ ($selectedSplit && isset($selectedSplit->receipt_items[$index]) && (($selectedSplit->receipt_items[$index]['checkbox'] ?? false) === true)) ? 'bg-indigo-50 dark:bg-indigo-900/10 print:!bg-transparent' : '' }}"
                        >
                            <flux:table.cell colspan="4" class="!pl-5 !pr-5 !border-t-0">
                                <div class="flex items-center gap-2 text-xs">
                                    <div class="shrink-0 w-10"></div>
                                    <div class="flex-1 min-w-0 flex items-center gap-1.5">
                                    @if(!empty($line_item['Area']))
                                        <span class="min-w-0 truncate text-zinc-500 dark:text-zinc-400">{{ is_array($line_item['Area']) ? implode(' / ', $line_item['Area']) : $line_item['Area'] }}</span>
                                    @endif
                                    @if($showNotes && !empty($itemNotes))
                                        @if($compactNotes)
                                            <span class="min-w-0 truncate text-zinc-400 dark:text-zinc-500" title="{{ $itemNotes }}">{{ \Illuminate\Support\Str::limit(trim((string) $itemNotes), 140) }}</span>
                                        @else
                                            <span class="min-w-0 whitespace-pre-line text-zinc-400 dark:text-zinc-500">{{ trim((string) $itemNotes) }}</span>
                                        @endif
                                    @endif
                                    <span class="shrink-0 ml-auto flex items-center gap-1.5">
                                        @if(!empty($itemDate))
                                            @php($txDate = \Carbon\Carbon::parse($itemDate)->startOfDay())
                                            @php($todayDate = now(auth()->user()?->vendor?->timezone ?? config('app.timezone'))->startOfDay())
                                            <span class="{{ $txDate->gt($todayDate) ? 'text-red-500 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">{{ $txDate->format('M j') }}</span>
                                        @endif
                                        @if(!empty($itemStatus))
                                            @php($statusColor = match($normalizedStatus) {
                                                'back order' => 'red',
                                                'available', 'received', 'shipped' => 'green',
                                                'open', 'partial' => 'amber',
                                                'cancelled' => 'zinc',
                                                default => 'zinc',
                                            })
                                            <flux:badge size="sm" :color="$statusColor">{{ ucfirst($normalizedStatus) }}</flux:badge>
                                        @endif
                                    </span>
                                    </div>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endif
                @endforeach

                <flux:table.row>
                    <flux:table.cell colspan="3" align="end" class="font-medium">Subtotal</flux:table.cell>
                    <flux:table.cell class="!pr-5" align="end">{{money($receipt->receipt_items['subtotal'] ?? 0)}}</flux:table.cell>
                </flux:table.row>

                @if(!empty($receipt->receipt_items['taxes']) && is_array($receipt->receipt_items['taxes']))
                    @foreach($receipt->receipt_items['taxes'] as $tax)
                    <flux:table.row>
                        <flux:table.cell colspan="3" align="end" class="font-medium">{{ $tax['type'] ?? 'Tax' }}</flux:table.cell>
                        <flux:table.cell class="!pr-5" align="end">{{money($tax['amount'] ?? 0)}}</flux:table.cell>
                    </flux:table.row>
                    @endforeach
                @else
                <flux:table.row>
                    <flux:table.cell colspan="3" align="end" class="font-medium">Tax</flux:table.cell>
                    <flux:table.cell class="!pr-5" align="end">{{money($receipt->receipt_items['total_tax'] ?? 0)}}</flux:table.cell>
                </flux:table.row>
                @endif

                @if(!empty($receipt->receipt_items['tip']))
                <flux:table.row>
                    <flux:table.cell colspan="3" align="end" class="font-medium">Tip</flux:table.cell>
                    <flux:table.cell class="!pr-5" align="end">{{money($receipt->receipt_items['tip'])}}</flux:table.cell>
                </flux:table.row>
                @endif

                @if(!empty($receipt->receipt_items['misc_fees']))
                <flux:table.row>
                    <flux:table.cell colspan="3" align="end" class="font-medium">Fees</flux:table.cell>
                    <flux:table.cell class="!pr-5" align="end">{{money($receipt->receipt_items['misc_fees'])}}</flux:table.cell>
                </flux:table.row>
                @endif

                @if(!empty($receipt->receipt_items['shipping']))
                <flux:table.row>
                    <flux:table.cell colspan="3" align="end" class="font-medium">Shipping</flux:table.cell>
                    <flux:table.cell class="!pr-5" align="end">{{money($receipt->receipt_items['shipping'])}}</flux:table.cell>
                </flux:table.row>
                @endif



                <flux:table.row>
                    <flux:table.cell colspan="3" align="end" class="font-medium {{ (($selectedSplit && isset($selectedSplit->amount)) || $expenseMismatch) ? '!text-gray-300 line-through' : '' }}">Total</flux:table.cell>
                    <flux:table.cell variant="strong" class="!pr-5 {{ (($selectedSplit && isset($selectedSplit->amount)) || $expenseMismatch) ? '!text-gray-300 line-through' : '' }}" align="end">{{money($receipt->receipt_items['total'] ?? 0)}}</flux:table.cell>
                </flux:table.row>

                @if(!empty($receipt->receipt_items['deposit']))
                <flux:table.row>
                    <flux:table.cell colspan="3" align="end" class="font-medium">Deposit</flux:table.cell>
                    <flux:table.cell class="!pr-5" align="end">{{money($receipt->receipt_items['deposit'])}}</flux:table.cell>
                </flux:table.row>
                @endif

                @if(!empty($receipt->receipt_items['balance_due']))
                <flux:table.row>
                    <flux:table.cell colspan="3" align="end" class="font-medium">Balance Due</flux:table.cell>
                    <flux:table.cell variant="strong" class="!pr-5" align="end">{{money($receipt->receipt_items['balance_due'])}}</flux:table.cell>
                </flux:table.row>
                @endif

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