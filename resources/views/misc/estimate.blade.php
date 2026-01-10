<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    {{-- HEAD --}}
    @include('components.layouts.pdf-head')

    {{-- BODY --}}
    <body>
        <flux:main>
            <div class="break-after-page space-y-4">
                <div class="grid grid-cols-5 gap-4 items-start">
                    <div class="col-span-2 space-y-4">
                        {{-- VENDOR DETAILS --}}
                        @include('livewire.vendors.vendor-details', [
                            'vendor' => $vendor,
                            'vendorLogoDataUrl' => $vendorLogoDataUrl,
                            'nonLivewire' => true,
                            'titleOverride' => 'Contractor Details',
                            'hide' => ['type'],
                        ])
                    </div>

                    <div class="col-span-3 space-y-4">
                        {{-- DOCUMENT DETAILS --}}
                        @include('livewire.estimates.estimate-details', [
                            'estimate' => $estimate,
                            'client' => $client,
                            'project' => $project,
                            'nonLivewire' => true,
                            'titleOverride' => $type . ' Details',
                            'estimateTotal' => $estimate_total,
                            'hide' => ['total'],
                        ])

                        {{-- CONTACT DETAILS --}}
                        @include('livewire.users.index', [
                            'users' => $clientContacts,
                            'client' => $client,
                            'view' => 'clients.show',
                            'view_text' => [
                                'card_title' => 'Contact Details',
                                'button_text' => 'Add Contact',
                            ],
                            'nonLivewire' => true,
                        ])
                    </div>
                </div>

                {{-- SECTIONS --}}
                <div class="col-span-4 space-y-4">
                    @foreach($sections as $index => $section)
                        <flux:card style="{{ $index > 0 ? 'break-inside: avoid;' : '' }}">
                            <div class="flex justify-between">
                                <flux:heading size="lg" class="text-lg font-extrabold">{{$section['name']}}</flux:heading>
                            </div>

                            <table class="min-w-full">
                                <thead class="text-gray-900 border-b border-gray-400">
                                    <tr>
                                        {{-- first th --}}
                                        <th
                                            scope="col"
                                            class="hidden px-3 py-3.5 text-right text-sm font-semibold text-gray-900 sm:table-cell"
                                            >
                                        </th>
                                        <th
                                            scope="col"
                                            class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 sm:pl-6 sm:w-1/2"
                                            >
                                            Item
                                        </th>
                                        <th
                                            scope="col"
                                            class="hidden px-3 py-3.5 text-right text-sm font-semibold text-gray-900 sm:table-cell"
                                            >
                                            Quantity
                                        </th>
                                        <th
                                            scope="col"
                                            class="hidden px-3 py-3.5 text-right text-sm font-semibold text-gray-900 sm:table-cell"
                                            >
                                            Unit
                                        </th>
                                        @if($type != 'Work Order')
                                            <th scope="col"
                                                class="hidden px-3 py-3.5 text-right text-sm font-semibold text-gray-900 sm:table-cell"
                                                >
                                                Cost
                                            </th>
                                            {{-- last th --}}
                                            <th
                                                scope="col"
                                                class="py-3.5 pl-3 pr-4 text-right text-sm font-semibold text-gray-900 sm:pr-6"
                                                >
                                                Total
                                            </th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($estimate->estimate_line_items()->where('section_id', $section->id)->orderBy('order', 'ASC')->get() as $key => $estimate_line_item)
                                        <tbody style="break-inside: avoid;">
                                        <tr class="sm:border-b sm:border-gray-400">
                                            <td class="hidden px-3 py-5 text-right text-gray-500 align-text-top text-md sm:table-cell bg-gray-50">{{$index + 1}}.{{$estimate_line_item->order + 1}}</td>
                                            {{-- first td --}}

                                            <td class="pl-4 pr-3 text-md max-w-0 sm:pl-6 bg-gray-50">
                                                <a
                                                {{-- <x-cards.button type="button" wire:click="$dispatchTo('line-items.estimate-line-item-create', 'addToEstimate', { section_id: {{$section['section_id']}} })"> --}}
                                                    class="cursor-pointer"
                                                    {{-- {{$estimate_line_item->pivot->id}}, {{$section['section_id']}} --}}
                                                    {{--  section_id: {{$section['section_id']}},  --}}

                                                    {{-- href="{{route('estimates.show', $estimate->id)}}" --}}
                                                    >
                                                    <div class="text-lg font-medium text-gray-900">{{$estimate_line_item->name}}</div>
                                                    <div class="text-xs font-bold text-indigo-900">{{$estimate_line_item->category}}/{{$estimate_line_item->sub_category}}</div>
                                                </a>
                                                {{-- @if($estimate_line_item->pivot->notes)
                                                    <div class="hidden mt-1 italic text-gray-500 sm:table-cell">
                                                        {{$estimate_line_item->pivot->notes}}
                                                    </div>
                                                @endif --}}
                                            </td>

                                            <td class="hidden px-3 py-5 text-right text-gray-500 align-text-top text-md sm:table-cell bg-gray-50">{{$estimate_line_item->unit_type !== 'no_unit' ? $estimate_line_item->quantity : ''}}</td>
                                            <td class="hidden px-3 py-5 text-right text-gray-500 align-text-top text-md sm:table-cell bg-gray-50">{{$estimate_line_item->unit_type !== 'no_unit' ? $estimate_line_item->unit_type : ''}}</td>

                                            @if($type != 'Work Order')
                                                <td class="hidden px-3 py-5 text-right text-gray-500 align-text-top text-md sm:table-cell bg-gray-50">{{$estimate_line_item->unit_type !== 'no_unit' ? money($estimate_line_item->cost) : ''}}</td>
                                                {{-- last td --}}
                                                <td class="py-5 pl-3 pr-4 text-right text-gray-800 align-text-top text-md sm:pr-6 bg-gray-50">{{money($estimate_line_item->total)}}</td>
                                            @endif
                                        </tr>

                                        <tr class="border-b border-gray-400">
                                            {{-- first td --}}
                                            <td class="hidden sm:table-cell"></td>
                                            <td class="pb-5 pl-4 pr-3 text-md max-w-0 sm:pl-6" colspan="5">
                                                <div class="flex flex-col hidden mt-1 sm:block">
                                                    <span class="text-black" style="white-space: pre-line;">{{$estimate_line_item->desc}}</span>
                                                    @if($estimate_line_item->notes)
                                                        <hr>
                                                        <span class="text-gray-500" style="white-space: pre-line;"><i>{{$estimate_line_item->notes}}</i></span>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                        </tbody>
                                    @endforeach
                                </tbody>
                            </table>

                            @if($type != 'Work Order')
                                <x-cards.footer>
                                    <button></button>
                                    <h3>Section Total: {{money($section->total)}}</h3>
                                </x-cards.footer>
                            @endif
                        </flux:card>
                    @endforeach
                </div>

                {{-- ESTIMATE TOTAL --}}
                @if($type != 'Work Order')
                    @if($projectStatusTitle && !in_array($projectStatusTitle, ['Active', 'Complete', 'Service Call', 'Service Call Complete']))
                        <div class="flex justify-between">
                            <div></div>
                            <x-lists.ul
                                {{-- wire:target="print"
                                wire:loading.attr="disabled"
                                wire:loading.class="opacity-50 text-opacity-40" --}}
                                >
                                <x-lists.search_li
                                    :basic=true
                                    :bold="TRUE"
                                    {{-- make gray --}}
                                    :line_title="'TOTAL ESTIMATE'"
                                    :line_data="money($estimate_total + ($reimbursements ?? 0))"
                                    >
                                </x-lists.search_li>
                            </x-lists.ul>
                        </div>
                    @endif

                    <div style="page-break-before: always;"></div>
                    <div class="grid grid-cols-4 gap-4">
                        {{-- PROJECT PAYMENTS --}}
                        <div class="col-span-2 space-y-4">
                            @if($payments->isNotEmpty())
                                <flux:card class="space-y-2">
                                    <div class="flex justify-between">
                                        <flux:heading size="lg">Payments</flux:heading>
                                    </div>

                                    <flux:table>
                                        <flux:table.columns>
                                            <flux:table.column>Amount</flux:table.column>
                                            <flux:table.column>Date</flux:table.column>
                                            <flux:table.column>Reference</flux:table.column>
                                            <flux:table.column>Status</flux:table.column>
                                        </flux:table.columns>

                                        <flux:table.rows>
                                            @foreach ($payments as $payment)
                                                <flux:table.row>
                                                    <flux:table.cell variant="strong">{{ money($payment->amount) }}</flux:table.cell>
                                                    <flux:table.cell>{{ $payment->date->format('m/d/Y') }}</flux:table.cell>
                                                    <flux:table.cell>{{ $payment->reference }}</flux:table.cell>
                                                    <flux:table.cell>
                                                        <flux:badge size="sm" :color="$payment->transaction_id != NULL ? 'green' : 'red'" inset="top bottom">
                                                            {{ $payment->transaction_id != NULL ? 'Complete' : 'Missing Transaction' }}
                                                        </flux:badge>
                                                    </flux:table.cell>
                                                </flux:table.row>
                                            @endforeach
                                        </flux:table.rows>
                                    </flux:table>
                                </flux:card>
                            @endif
                        </div>

                        {{-- PROJECT FINANCES --}}
                        <div class="col-span-2">
                            @if($projectStatusTitle && in_array($projectStatusTitle, ['Active', 'Complete', 'Service Call', 'Service Call Complete']))
                                <flux:card>
                                    <div class="flex justify-between">
                                        <flux:heading size="lg">{{$type}} Finances</flux:heading>
                                    </div>
                                    <flux:separator variant="subtle" />
                                    <flux:table>
                                        <flux:table.rows>
                                            <flux:table.row>
                                                <flux:table.cell>Estimate</flux:table.cell>
                                                <flux:table.cell>{{ money(data_get($projectFinances, 'estimate', 0)) }}</flux:table.cell>
                                            </flux:table.row>
                                            <flux:table.row>
                                                <flux:table.cell>Change Order</flux:table.cell>
                                                <flux:table.cell>{{ money(data_get($projectFinances, 'change_orders', 0)) }}</flux:table.cell>
                                            </flux:table.row>
                                            @if($reimbursements)
                                                <flux:table.row>
                                                    <flux:table.cell>Reimbursements</flux:table.cell>
                                                    <flux:table.cell>{{money($reimbursements)}}</flux:table.cell>
                                                </flux:table.row>
                                            @endif
                                            <flux:table.row>
                                                <flux:table.cell variant="strong">TOTAL ESTIMATE</flux:table.cell>
                                                <flux:table.cell variant="strong">{{money($estimate_total + ($reimbursements ?? 0))}}</flux:table.cell>
                                            </flux:table.row>
                                            <flux:table.row>
                                                <flux:table.cell variant="strong">TOTAL PAYMENTS</flux:table.cell>
                                                <flux:table.cell variant="strong">-{{money($payments->sum('amount'))}}</flux:table.cell>
                                            </flux:table.row>
                                            <flux:table.row>
                                                <flux:table.cell variant="strong">BALANCE</flux:table.cell>
                                                <flux:table.cell variant="strong">{{money(($estimate_total + ($reimbursements ?? 0)) - $payments->sum('amount'))}}</flux:table.cell>
                                            </flux:table.row>
                                        </flux:table.rows>
                                    </flux:table>
                                </flux:card>
                            @endif
                        </div>
                    </div>
                @endif

                @if($type == 'Estimate' && !empty($contractBody))
                    <div style="page-break-before: always;"> </div>

                    {{-- Dynamic contract template --}}
                    <div class="contract-body">
                        @if(empty($estimate->payments))
                            <p><b><i>*The below Contract is a sample. It is not meant to be signed until a finalized Estimate is available.</i></b></p>
                            <br>
                        @endif
                        {!! $contractBody !!}
                    </div>
                @endif
            </div>
        </flux:main>
    </body>
</html>
