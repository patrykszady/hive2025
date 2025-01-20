<div>
    <x-cards class="{{$view == NULL ? 'w-full px-4 sm:px-6 lg:max-w-2xl lg:px-8 pb-5 mb-1' : ''}}">
        {{-- HEADING --}}
        <x-cards.heading>
            <x-slot name="left">
                <h1>Audit</h1>
            </x-slot>

            <x-slot name="right">
                <div class="space-x-2">
                    {{-- disabled when clicked --}}
                    <x-cards.button
                        {{-- {{$vendor->id}} --}}
                        {{-- wire:click="$dispatchTo('vendor-docs.vendor-doc-create', 'downloadDocuments', { doc_filenames: [{{$vendor_docs}}] })" --}}
                        wire:click="download_documents"
                        :button_color="'indigo'"
                        >
                        Download Certificates
                    </x-cards.button>
                    {{-- @if(isset($vendor->expired_docs))
                        <x-cards.button
                            wire:click="$emitTo('vendor-docs.vendor-docs-form', 'requestDocument', {{$vendor->id}})"
                            button_color=red
                            >
                            Request
                        </x-cards.button>
                    @endif --}}
                </div>
            </x-slot>
        </x-cards.heading>
    </x-cards>
    {{-- <x-cards class="{{$view == NULL ? 'w-full px-4 sm:px-6 lg:max-w-2xl lg:px-8 pb-5 mb-1' : ''}}">
    @livewire('vendor-docs.audit-index')
    </x-cards> --}}

    {{-- TRANSACTIONS NO CHECKS --}}
    <x-cards class="{{$view == NULL ? 'w-full px-4 sm:px-6 lg:max-w-2xl lg:px-8 mb-2' : ''}}">
        <x-cards.heading>
            <x-slot name="left">
                <h1>Transactions </h1>
                <span class="text-sm italic">
                    These Check Transactions have not beed added to Vendors or Projects. Please add checks before comleting this Audit.
                </span>
            </x-slot>
            <x-slot name="right">
                <div class="space-x-2">
                    {{-- <x-cards.button
                        wire:click="$emitTo('vendor-docs.vendor-docs-form', 'addDocument', {{$vendor->id}})"
                        button_color=white
                        >
                        Add
                    </x-cards.button>
                    @if(isset($vendor->expired_docs))
                        <x-cards.button
                            wire:click="$emitTo('vendor-docs.vendor-docs-form', 'requestDocument', {{$vendor->id}})"
                            button_color=red
                            >
                            Request
                        </x-cards.button>
                    @endif --}}
                </div>
            </x-slot>
        </x-cards.heading>

        <x-cards.body>
            <table class="min-w-full divide-y divide-gray-300 break-after-all">
                <thead class="text-gray-900 border-b border-gray-400">
                    <tr>
                        {{-- first th --}}
                        {{-- <th
                            scope="col"
                            class="hidden px-3 py-3.5 text-right text-sm font-semibold text-gray-900 sm:table-cell"
                            >
                        </th> --}}
                        <th
                            scope="col"
                            class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 sm:pl-6 sm:w-1/2"
                            >
                            Withdrawal Date
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 sm:table-cell"
                            >
                            Check #
                        </th>
                        <th
                            scope="col"
                            class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 sm:table-cell"
                            >
                            Amount
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($transactions_no_check as $transaction)
                    {{-- hover:bg-red-100  --}}
                        <tr class="border-t border-gray-600 bg-gray-50">
                            <td class="px-3 py-1 text-left text-gray-700 align-text-top text-md sm:table-cell">{{$transaction->transaction_date->format('m/d/Y')}}</td>
                            <td class="px-3 py-1 text-right text-gray-700 align-text-top text-md sm:table-cell">{{$transaction->check_number == '1010101' ? 'Transfer' : ($transaction->check_number == '2020202' ? 'Cash' : $transaction->check_number)}}</td>
                            <td class="px-3 py-1 text-right text-gray-700 align-text-top text-md sm:table-cell">{{money($transaction->amount)}}</td>
                        </tr>
                        <tr>
                            <td class="px-3 py-1 italic text-right text-gray-500 align-text-top text-md sm:table-cell" colspan="3">{{$transaction->plaid_merchant_description}}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-cards.body>
    </x-cards>

    {{-- VENDOR CHECKS --}}
    {{-- @if(!is_null($vendors_grouped_checks)) --}}
        {{-- @dd($vendors_grouped_checks) --}}
        @foreach($vendors_grouped_checks as $vendor_id => $vendor_checks)
            <x-cards class="{{$view == NULL ? 'w-full px-4 sm:px-6 lg:max-w-2xl lg:px-8 mb-2' : ''}}">
                <x-cards.heading>
                    <x-slot name="left">
                        <h1>{{$vendor_checks->first()->vendor->business_name}}</h1>
                        @if($vendor_checks->first()->vendor->business_type == 'Retail')
                            <span class="text-sm italic">
                                Vendor is Retail and doesn't require coverage.
                            </span>
                        @endif
                    </x-slot>
                    <x-slot name="right">
                        <div class="space-x-2">
                            {{-- <x-cards.button
                                wire:click="$emitTo('vendor-docs.vendor-docs-form', 'addDocument', {{$vendor->id}})"
                                button_color=white
                                >
                                Add
                            </x-cards.button>
                            @if(isset($vendor->expired_docs))
                                <x-cards.button
                                    wire:click="$emitTo('vendor-docs.vendor-docs-form', 'requestDocument', {{$vendor->id}})"
                                    button_color=red
                                    >
                                    Request
                                </x-cards.button>
                            @endif --}}
                        </div>
                    </x-slot>
                </x-cards.heading>

                <x-cards.body>
                    <table class="min-w-full divide-y divide-gray-300 break-after-all">
                        <thead class="text-gray-900 border-b border-gray-400">
                            <tr>
                                {{-- first th --}}
                                {{-- <th
                                    scope="col"
                                    class="hidden px-3 py-3.5 text-right text-sm font-semibold text-gray-900 sm:table-cell"
                                    >
                                </th> --}}
                                <th
                                    scope="col"
                                    class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 sm:pl-6 sm:w-1/3"
                                    >
                                    Withdrawal Date
                                </th>
                                <th
                                    scope="col"
                                    class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 sm:table-cell"
                                    >
                                    Check #
                                </th>
                                <th
                                    scope="col"
                                    class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 sm:table-cell"
                                    >
                                    Amount
                                </th>
                                <th
                                    scope="col"
                                    class="hidden px-3 py-3.5 text-right text-sm font-semibold text-gray-900 sm:table-cell"
                                    >
                                    Coverage
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($vendor_checks as $check)
                            {{-- hover:bg-red-100  --}}
                                <tr class="sm:border-b sm:border-gray-400 {{$check->covered == true ? 'bg-green-50' : ($vendor_checks->first()->vendor->business_type == 'Retail' ? 'bg-yellow-50' : 'bg-red-50')}}">
                                    <td class="px-3 py-1 text-left text-gray-500 align-text-top text-md sm:table-cell">{{$check->date->format('m/d/Y')}}</td>
                                    <td class="px-3 py-1 text-right text-gray-500 align-text-top text-md sm:table-cell">{{isset($check->check_number) ? $check->check_number : $check->check_type}}</td>
                                    <td class="px-3 py-1 text-right text-gray-500 align-text-top text-md sm:table-cell">{{money($check->amount)}}</td>
                                    <td class="hidden px-3 py-1 text-right align-text-top text-md sm:table-cell {{$check->covered == true ? 'text-green-600' : ($vendor_checks->first()->vendor->business_type == 'Retail' ? 'text-yellow-600' : 'text-red-600')}} ">{{$check->covered == true ? 'Covered' : 'Not Covered'}}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-cards.body>
            </x-cards>
        @endforeach
    {{-- @endif --}}
</div>
