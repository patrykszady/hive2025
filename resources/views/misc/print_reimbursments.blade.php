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
                <div style="page-break-before: always;"></div>

                <div class="grid grid-cols-5 gap-4">
                    <div class="col-span-2">
                        <flux:card>
                            <div class="flex justify-between">
                                <flux:heading>Receipt Info</flux:heading>
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
                        <x-details.card 
                            title="Receipt"
                            :accordion="false"
                        >
                            @if($expense->receipt && $expense->receipt->receipt_filename)
                                <x-slot:header_buttons>
                                    <flux:button
                                        href="{{ route('expenses.original_receipt', ['receipts', $expense->receipt->receipt_filename]) }}"
                                        target="_blank"
                                        size="sm"
                                    >
                                        Original Receipt
                                    </flux:button>
                                </x-slot:header_buttons>
                            @endif

                            <x-slot:details>
                                @if(!$expense->receipt || !$expense->receipt->receipt_items || empty($expense->receipt->receipt_items->items))
                                    <p class="text-sm text-zinc-600 dark:text-zinc-300">No Receipt line items. See <i>Original Receipt</i> for a receipt copy.</p>
                                @else
                                    <x-expenses.receipt 
                                        :receipt="$expense->receipt" 
                                        :selectedSplit="$expense->selectedSplit ?? null" 
                                    />
                                @endif
                            </x-slot:details>
                        </x-details.card>
                    </div>
                </div>
            @endforeach
        </flux:main>

        {{-- @fluxScripts --}}
    </body>
</html>
