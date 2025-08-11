<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    {{-- HEAD --}}
    @include('components.layouts.head')

    {{-- BODY --}}
    <body class="min-h-screen">
        <flux:main>
            <flux:card>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Date</flux:table.column>
                        <flux:table.column>Vendor</flux:table.column>
                        <flux:table.column>Amount</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach($expenses as $key => $expense)
                            <flux:table.row>
                                <flux:table.cell>{{$expense->date->format('m/d/Y')}}</flux:table.cell>
                                <flux:table.cell>{{$expense->business_name}}</flux:table.cell>
                                <flux:table.cell variant="strong">{{money($expense->amount)}}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>

                    <flux:table.row>
                        <flux:table.cell></flux:table.cell>
                        <flux:table.cell variant="strong" class="text-right">TOTAL</flux:table.cell>
                        <flux:table.cell variant="strong">{{money($expenses->sum('amount'))}}</flux:table.cell>
                    </flux:table.row>
                </flux:table>
            </flux:card>

            @foreach($expenses as $expense)
                {{-- @if(isset($expense->receipt_html)) --}}
                    <div style="page-break-before: always;"></div>

                    <div class="grid grid-cols-5 gap-4">
                        <div class="col-span-2">
                            <flux:card>
                                <div class="flex justify-between">
                                    <flux:heading>Receipt Info</flux:heading>

                                    @if($expense->receipt->receipt_filename)
                                        <flux:button
                                            href="{{ route('expenses.original_receipt', ['receipts', $expense->receipt->receipt_filename]) }}"
                                            target="_blank"
                                            size="sm"
                                            >
                                            Original Receipt
                                        </flux:button>
                                    @endif
                                </div>

                                <ul role="list" class="divide-y divide-gray-200">
                                    <li>
                                        <span class="text-gray-500 text-sm">
                                            Vendor
                                        </span>
                                        <br>
                                        <span class="text-gray-700 text-sm">
                                            {{$expense->business_name}}
                                        </span>
                                    </li>

                                    <li>
                                        <span class="text-gray-500 text-sm">
                                            Amount
                                        </span>
                                        <br>
                                        <span class="text-gray-700 text-sm">
                                            {{money($expense->amount)}}
                                        </span>
                                    </li>

                                    <li>
                                        <span class="text-gray-500 text-sm">
                                            Date
                                        </span>
                                        <br>
                                        <span class="text-gray-700 text-sm">
                                            {{$expense->date->format('m/d/Y')}}
                                        </span>
                                    </li>

                                    @if($expense->receipt->receipt_items->invoice_number)
                                        <li>
                                            <span class="text-gray-500 text-sm">
                                                Invoice
                                            </span>
                                            <br>
                                            <span class="text-gray-700 text-sm">
                                                {{$expense->receipt->receipt_items->invoice_number}}
                                            </span>
                                        </li>
                                    @endif

                                    {{-- $expense->receipt->receipt_items->purchase_order || $expense->receipt->receipt_items->handwritten_notes --}}
                                    @if($expense->receipt->notes)
                                        <li>
                                            <span class="text-gray-500 text-sm">
                                                Purchase Order
                                            </span>
                                            <br>
                                            <span class="text-gray-700 text-sm">
                                                {{$expense->receipt->notes}}
                                            </span>
                                        </li>
                                    @endif
                                </ul>
                            </flux:card>
                        </div>
                        <div class="col-span-3">
                            @if(!isset($expense->receipt->receipt_items))
                                <pre style="bg-transparent">
                                    {!! $expense->receipt_html !!}
                                </pre>
                            @else
                                {{--  class="w-96" --}}
                                <flux:card>
                                    {{-- @include('livewire.expenses._receipt') --}}
                                    <flux:table>
                                        <flux:table.columns>
                                            <flux:table.column>Desc</flux:table.column>
                                            <flux:table.column>Price</flux:table.column>
                                            <flux:table.column>Qty</flux:table.column>
                                            <flux:table.column>Total</flux:table.column>
                                        </flux:table.columns>

                                        <flux:table.rows>
                                            @foreach($expense->receipt->receipt_items->items as $item_index => $line_item)
                                                @php
                                                    // $split = $expense->receipt_items && $expense->receipt_items[$item_index]['checkbox'] == true ? false : true;
                                                    if($expense->receipt_items){
                                                        if($expense->receipt_items[$item_index]['checkbox'] == false){
                                                            $split = true;
                                                        }else{
                                                            $split = false;
                                                        }
                                                    }else{
                                                        $split = false;
                                                    }

                                                    if($expense->vendor->id === 8){
                                                        //Home Depot Search
                                                        $search_url = 'https://www.homedepot.com/s/';
                                                    }elseif($expense->vendor->id === 10){
                                                        //Menards Search
                                                        $search_url = 'https://www.menards.com/main/search.html?search=';
                                                    }elseif($expense->vendor->id === 54){
                                                        //Amazon Search
                                                        $search_url = 'https://www.amazon.com/s?k=';
                                                    }else{
                                                        $search_url = false;
                                                    }
                                                @endphp

                                                <flux:table.row>
                                                    <flux:table.cell colspan="4" class="pb-0!">
                                                        <span
                                                            @class([
                                                                'text-gray-200 line-through' => $split
                                                            ])
                                                            >
                                                            {{-- {{$line_item->Description ?? Str::limit($line_item->Description, 45) : ''}} --}}
                                                            {{isset($line_item->Description) ? Str::limit($line_item->Description, 45) : ''}}
                                                        </span>
                                                    </flux:table.cell>
                                                </flux:table.row>

                                                <flux:table.row class="border-none! py-0!">
                                                    {{-- 09/28/24 URL TO ITEM --}}
                                                    <flux:table.cell class="text-right">
                                                        <i
                                                            @class([
                                                                'text-gray-200 line-through' => $split,
                                                                'underline' => $search_url && !$split
                                                            ])
                                                            >
                                                            @if($search_url && !$split)
                                                                <a href="{{$search_url}} {{$line_item->ProductCode}}">{{$line_item->ProductCode}}</a>
                                                            @else
                                                                {{$line_item->ProductCode}}
                                                            @endif
                                                        </i>
                                                    </flux:table.cell>
                                                    <flux:table.cell>
                                                        <span
                                                            @class([
                                                                'text-gray-200 line-through' => $split
                                                            ])
                                                            >
                                                            {{money($line_item->Price)}}
                                                        </span>
                                                    </flux:table.cell>
                                                    <flux:table.cell>
                                                        <span
                                                            @class([
                                                                'text-gray-200 line-through' => $split
                                                            ])
                                                            >
                                                            {{$line_item->Quantity}}
                                                        </span>
                                                    </flux:table.cell>
                                                    <flux:table.cell>
                                                        <span
                                                            @class([
                                                                'text-gray-200 line-through' => $split,
                                                                'font-semibold' => !$split
                                                            ])
                                                            >
                                                            {{money($line_item->TotalPrice)}}
                                                        </span>
                                                    </flux:table.cell>
                                                </flux:table.row>
                                            @endforeach

                                            <flux:table.row>
                                                <flux:table.cell colspan="3" class="text-right font-semibold">Subtotal</flux:table.cell>
                                                <flux:table.cell>{{money($expense->receipt->receipt_items->total)}}</flux:table.cell>
                                            </flux:table.row>

                                            <flux:table.row>
                                                <flux:table.cell colspan="3" class="text-right font-semibold">Tax</flux:table.cell>
                                                <flux:table.cell>{{money($expense->receipt->receipt_items->total_tax)}}</flux:table.cell>
                                            </flux:table.row>

                                            <flux:table.row>
                                                <flux:table.cell colspan="3" class="text-right font-semibold">Total</flux:table.cell>
                                                <flux:table.cell>
                                                    @if($expense->receipt_items)
                                                        <s>{{money($expense->receipt->receipt_items->total)}}</s>
                                                        <br>
                                                        <b>{{money($expense->amount)}}</b>
                                                    @else
                                                        @if($expense->amount != $expense->receipt->receipt_items->total)
                                                            <s>{{money($expense->receipt->receipt_items->total)}}</s>
                                                            <br>
                                                            <b>{{money($expense->amount)}}</b>
                                                        @else
                                                            <b>{{money($expense->amount)}}</b>
                                                            {{-- {{money($expense->receipt->receipt_items->total)}}   --}}
                                                        @endif
                                                    @endif
                                                </flux:table.cell>
                                            </flux:table.row>
                                        </flux:table.rows>
                                    </flux:table>
                                </flux:card>
                            @endif
                        </div>
                    </div>
                {{-- @endif --}}
            @endforeach
        </flux:main>

        {{-- @fluxScripts --}}
    </body>
</html>
