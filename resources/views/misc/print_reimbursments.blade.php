<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    {{-- HEAD --}}
    @include('components.layouts.head')

    {{-- BODY --}}
    <body class="min-h-screen">
        <flux:main>
            <flux:card>
                <flux:table>
                    <flux:columns>
                        <flux:column>Date</flux:column>
                        <flux:column>Vendor</flux:column>
                        <flux:column>Amount</flux:column>
                    </flux:columns>

                    <flux:rows>
                        @foreach($expenses as $key => $expense)
                            <flux:row>
                                <flux:cell>{{$expense->date->format('m/d/Y')}}</flux:cell>
                                <flux:cell>{{$expense->business_name}}</flux:cell>
                                <flux:cell variant="strong">{{money($expense->amount)}}</flux:cell>
                            </flux:row>
                        @endforeach
                    </flux:rows>

                    <flux:row>
                        <flux:cell></flux:cell>
                        <flux:cell variant="strong" class="text-right">TOTAL</flux:cell>
                        <flux:cell variant="strong">{{money($expenses->sum('amount'))}}</flux:cell>
                    </flux:row>
                </flux:table>
            </flux:card>

            @foreach($expenses as $expense)
                @if(isset($expense->receipt_html))
                    <div style="page-break-before: always;"></div>

                    <div class="grid grid-cols-5 gap-4">
                        <div class="col-span-2">
                            <flux:card>
                                <div class="flex justify-between">
                                    <flux:heading>Receipt Info</flux:heading>
                                    <flux:button
                                        href="{{ route('expenses.original_receipt', $expense->receipt->receipt_filename) }}"
                                        target="_blank"
                                        size="sm"
                                        >
                                        Original Receipt
                                    </flux:button>
                                </div>

                                <ul role="list" class="divide-y divide-gray-20">
                                    <li>
                                        <span class="text-gray-500 text-sm">
                                            Vendor
                                        </span>
                                        <br>
                                        <span class="text-gray-700 text-sm">
                                            {{$expense->vendor->busienss_name}}
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
                                        <flux:columns>
                                            <flux:column>Desc</flux:column>
                                            <flux:column>Price</flux:column>
                                            <flux:column>Qty</flux:column>
                                            <flux:column>Total</flux:column>
                                        </flux:columns>

                                        <flux:rows>
                                            @foreach($expense->receipt->receipt_items->items as $item_index => $line_item)
                                                @php
                                                    //// $split = $expense->receipt_items && $expense->receipt_items[$item_index]['checkbox'] == true ? false : true;
                                                    if($expense->receipt_items){
                                                        if($expense->receipt_items[$item_index]['checkbox'] == false){
                                                            $split = true;
                                                        }else{
                                                            $split = false;
                                                        }
                                                    }else{
                                                        $split = false;
                                                    }

                                                    //Home Depot Search
                                                    if($expense->vendor->id === 8){
                                                        $search_url = 'https://www.homedepot.com/s/';
                                                    }elseif($expense->vendor->id === 10){
                                                        //Menards Search
                                                        $search_url = 'https://www.menards.com/main/search.html?search=';
                                                    }else{
                                                        $search_url = false;
                                                    }

                                                @endphp
                                                <flux:row>
                                                    <flux:cell colspan="4" class="!pb-0">
                                                        <span
                                                            @class([
                                                                'text-gray-200 line-through' => $split
                                                            ])
                                                            >
                                                            {{$line_item->Description ?? ''}}
                                                            {{-- {{isset($line_item->Description) ? $line_item->Description : ''}} --}}
                                                        </span>
                                                    </flux:cell>
                                                </flux:row>
                                                <flux:row class="!border-none !py-0">
                                                    {{-- 09/28/24 URL TO ITEM --}}
                                                    <flux:cell class="text-right">
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
                                                    </flux:cell>
                                                    <flux:cell>
                                                        <span
                                                            @class([
                                                                'text-gray-200 line-through' => $split
                                                            ])
                                                            >
                                                            {{money($line_item->Price)}}
                                                        </span>
                                                    </flux:cell>
                                                    <flux:cell>
                                                        <span
                                                            @class([
                                                                'text-gray-200 line-through' => $split
                                                            ])
                                                            >
                                                            {{$line_item->Quantity}}
                                                        </span>
                                                    </flux:cell>
                                                    <flux:cell>
                                                        <span
                                                            @class([
                                                                'text-gray-200 line-through' => $split,
                                                                'font-semibold' => !$split
                                                            ])
                                                            >
                                                            {{money($line_item->TotalPrice)}}
                                                        </span>
                                                    </flux:cell>
                                                </flux:row>
                                            @endforeach

                                            <flux:row>
                                                <flux:cell colspan="3" class="text-right font-semibold">Subtotal</flux:cell>
                                                <flux:cell>{{money($expense->receipt->receipt_items->total)}}</flux:cell>
                                            </flux:row>

                                            <flux:row>
                                                <flux:cell colspan="3" class="text-right font-semibold">Tax</flux:cell>
                                                <flux:cell>{{money($expense->receipt->receipt_items->total_tax)}}</flux:cell>
                                            </flux:row>

                                            <flux:row>
                                                <flux:cell colspan="3" class="text-right font-semibold">Total</flux:cell>
                                                <flux:cell>
                                                    @if($expense->receipt_items)
                                                        <s>{{money($expense->receipt->receipt_items->total)}}</s>
                                                        <br>
                                                        <b>{{money($expense->amount)}}</b>
                                                    @else
                                                        <b>{{money($expense->receipt->receipt_items->total)}}</b>
                                                    @endif
                                                </flux:cell>
                                            </flux:row>
                                        </flux:rows>
                                    </flux:table>
                                </flux:card>
                            @endif
                        </div>
                    </div>
                @endif
            @endforeach
        </flux:main>
    </body>
</html>
