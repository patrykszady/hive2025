<!DOCTYPE html>
<html lang="en">
    <head>
        <title>{{$title}}</title>
        <meta charset="utf-8">
        {{-- @vite('resources/js/app.js') --}}
        {{-- <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&display=swap"> --}}

        <!-- Styles -->
        <link rel="stylesheet" href="{{ asset('css/app.css') }}">
        {{-- <style type="text/css">
            table { page-break-inside:auto }
            tr    { page-break-inside:avoid; page-break-after:auto }
         </style> --}}

        <!-- Scripts -->
        <script src="{{ asset('js/app.js') }}" defer></script>
    </head>

    <body>
        <main class="py-10">
            <div class="px-4 sm:px-6 md:px-8 break-after-page">
                <div class="col-span-4">
                    <x-cards.body>
                        {{--  divide-y divide-gray-300 --}}
                        <table class="min-w-full">
                            <thead class="text-gray-900 border-b border-gray-400">
                                <tr>
                                    {{-- first th --}}
                                    <th
                                        scope="col"
                                        class="hidden px-3 py-3.5 text-right text-sm font-semibold text-gray-900 sm:table-cell"
                                        >
                                        Date
                                    </th>
                                    <th
                                        scope="col"
                                        class="hidden px-3 py-3.5 text-right text-sm font-semibold text-gray-900 sm:table-cell"
                                        >
                                        Vendor
                                    </th>
                                    {{-- last th --}}
                                    <th
                                        scope="col"
                                        class="py-3.5 pl-3 pr-4 text-right text-sm font-semibold text-gray-900 sm:pr-6"
                                        >
                                        Amount
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($expenses as $key => $expense)

                                <tr class="border-b border-gray-400">
                                    <td class="hidden px-3 py-5 text-right text-gray-500 align-text-top text-md sm:table-cell bg-gray-50">{{$expense->date->format('m/d/Y')}}</td>
                                    <td class="hidden px-3 py-5 text-right text-gray-500 align-text-top text-md sm:table-cell bg-gray-50">{{$expense->business_name}}</td>
                                    <td class="hidden px-3 py-5 text-right text-gray-500 align-text-top text-md sm:table-cell bg-gray-50">{{money($expense->amount)}}</td>
                                    {{-- last td --}}
                                    {{-- <td class="py-5 pl-3 pr-4 text-right text-gray-800 align-text-top text-md sm:pr-6 bg-gray-50">{{money($expense->total)}}</td> --}}
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </x-cards.body>
                </div>

                @foreach($expenses as $expense)
                    @if(isset($expense->receipt_html))
                        <div style="page-break-before: always;"></div>
                        <h1>{{ money($expense->amount) . ' for ' . $expense->business_name }}</h1>

                        @if(!isset($expense->receipt->receipt_items))
                            <pre style="bg-transparent">
                                {!! $expense->receipt_html !!}
                            </pre>
                        @else
                            {{-- <x-cards.wrapper> --}}
                            <div class="mx-auto">
                                <div class="overflow-hidden bg-white sm:rounded-lg">
                                    <x-cards.heading>
                                        <x-slot name="left">
                                            <h1>Receipt</h1>
                                        </x-slot>
                                        <x-slot name="right">
                                            <x-cards.button
                                                href="{{ route('expenses.original_receipt', $expense->receipt->receipt_filename) }}"
                                                target="_blank"
                                                :class="'rounded-md ring-2 ring-indigo-600 px-2.5 py-1.5 text-sm font-semibold text-gray-900 shadow-sm'"
                                                >
                                                Original Receipt
                                            </x-cards.button>
                                        </x-slot>
                                    </x-cards.heading>
                                    <x-cards.heading>
                                        @php
                                            $line_details = [
                                                1 => [
                                                    'text' => $expense->business_name,
                                                    'icon' => 'M6 6V5a3 3 0 013-3h2a3 3 0 013 3v1h2a2 2 0 012 2v3.57A22.952 22.952 0 0110 13a22.95 22.95 0 01-8-1.43V8a2 2 0 012-2h2zm2-1a1 1 0 011-1h2a1 1 0 011 1v1H8V5zm1 5a1 1 0 011-1h.01a1 1 0 110 2H10a1 1 0 01-1-1z M2 13.692V16a2 2 0 002 2h12a2 2 0 002-2v-2.308A24.974 24.974 0 0110 15c-2.796 0-5.487-.46-8-1.308z'
                                                    ],
                                                2 => [
                                                    'text' => $expense->date->format('m/d/Y'),
                                                    'icon' => 'M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z'
                                                    ],
                                                ];

                                            if(isset($expense->receipt_items)){
                                                $total = '<s>' . money($expense->receipt->total) . '</s> ' . ($expense->amount);
                                            }else{
                                                $total = money($expense->receipt->total);
                                            }
                                        @endphp

                                        <x-lists.ul>
                                            <x-lists.search_li
                                                :line_details="$line_details"
                                                >
                                            </x-lists.search_li>
                                        </x-lists.ul>
                                    </x-cards.heading>
                                    <x-cards.body>
                                        @if(!isset($expense->receipt->receipt_items->items))
                                            <pre style="background-color:transparent">
                                                {!! $expense->receipt_html !!}
                                            </pre>
                                        @else
                                            <x-lists.ul>
                                                <hr>
                                                    <x-lists.search_li
                                                        :basic=true
                                                        :line_title="'Items:'"
                                                        >
                                                    </x-lists.search_li>
                                                <hr>

                                                {{-- FOREACH --}}
                                                @foreach($expense->receipt->receipt_items->items as $index => $item)
                                                    <div class="border-t-4">
                                                        @include('livewire.receipts.receipt_view',
                                                            ['split_true' => $expense->receipt_items && $expense->receipt_items[$index]['checkbox'] == true ? true : false])
                                                    </div>
                                                @endforeach

                                                <hr>
                                            </x-lists.ul>
                                        @endif
                                    </x-cards.body>
                                    <x-cards.footer>
                                        <x-lists.ul>
                                            <x-lists.search_li
                                            :basic=true
                                            :line_title="'Subtotal'"
                                            :line_data="money($expense->receipt->subtotal)"
                                            >
                                        </x-lists.search_li>

                                        <x-lists.search_li
                                            :basic=true
                                            :line_title="'Tax'"
                                            :line_data="money($expense->receipt->tax)"
                                            >
                                        </x-lists.search_li>

                                        <x-lists.search_li
                                            :basic=true
                                            :line_title="'Total'"
                                            :line_data="$total"
                                            >
                                        </x-lists.search_li>
                                        </x-lists.ul>
                                    </x-cards.footer>
                                </div>
                            </div>
                        @endif
                    @endif
                @endforeach
            </div>
        </main>
    </body>
</html>

