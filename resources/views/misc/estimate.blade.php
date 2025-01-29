<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    {{-- HEAD --}}
    @include('components.layouts.head')

    {{-- BODY --}}
    {{--  class="min-h-screen" --}}
    <body>
        <flux:main>
            {{-- <div style="page-break-before: always;"></div> --}}
            <div class="break-after-page space-y-4">
                <div class="grid grid-cols-4 gap-4">
                    {{-- VENDOR DETAILS --}}
                    <div class="col-span-2 space-y-4">
                        <flux:card>
                            <div class="flex justify-between">
                                <flux:heading size="lg">Contractor Details</flux:heading>
                            </div>
                            <x-lists.ul>
                                <x-lists.search_li
                                    :basic=true
                                    :line_title="'Project Contractor'"
                                    href="{{route('vendors.show', $estimate->vendor)}}"
                                    :line_data="$estimate->vendor->business_name"
                                    >
                                </x-lists.search_li>

                                <x-lists.search_li
                                    :basic=true
                                    :line_title="'Address'"
                                    :href_target="'blank'"
                                    :line_data="$estimate->vendor->full_address"
                                    >
                                </x-lists.search_li>
                            </x-lists.ul>
                        </flux:card>
                        <flux:card>
                            <div class="flex justify-between">
                                <flux:heading size="lg">Homeowner Details</flux:heading>
                            </div>
                        </flux:card>
                    </div>

                    {{-- DOCUMENT DETAILS --}}
                    <div class="col-span-2 space-y-2">
                        <flux:card>
                            <div class="flex justify-between">
                                <flux:heading size="lg">{{$type}} Details</flux:heading>
                            </div>
                            <x-lists.ul>
                                <x-lists.search_li
                                    :basic=true
                                    :line_title="'Project Homeowner'"
                                    href="{{route('clients.show', $estimate->client)}}"
                                    :line_data="$estimate->client->name"
                                    >
                                </x-lists.search_li>

                                <x-lists.search_li
                                    :basic=true
                                    :line_title="'Project Name'"
                                    href="{{route('projects.show', $estimate->project->id)}}"
                                    :line_data="$estimate->project->project_name"
                                    >
                                </x-lists.search_li>

                                <x-lists.search_li
                                    :basic=true
                                    :line_title="'Jobsite Address'"
                                    href="{{$estimate->project->getAddressMapURI()}}"
                                    :href_target="'blank'"
                                    :line_data="$estimate->project->full_address"
                                    >
                                </x-lists.search_li>

                                <x-lists.search_li
                                    :basic=true
                                    :line_title="'Billing Address'"
                                    :line_data="$estimate->client->full_address"
                                    >
                                </x-lists.search_li>

                                <x-lists.search_li
                                    :basic=true
                                    :line_title="$type"
                                    :line_data="$estimate->number"
                                    >
                                </x-lists.search_li>
                            </x-lists.ul>
                        </flux:card>
                    </div>
                </div>

                {{-- SECTIONS --}}
                <div class="col-span-4 space-y-4">
                    @foreach($sections as $index => $section)
                        <flux:card>
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
                                                    <span class="text-black">{{$estimate_line_item->desc}}</span>
                                                    @if($estimate_line_item->notes)
                                                        <hr>
                                                        <span class="text-gray-500"><i>{{$estimate_line_item->notes}}</i></span>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
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
                    <div style="page-break-before: always;"></div>
                    <div class="grid grid-cols-4 gap-4">

                        <div class="col-span-2 space-y-4">
                            {{-- PROJECT PAYMENTS --}}
                            <livewire:payments.payments-index :project="$estimate->project" :view="'projects.show'" />
                        </div>
                        <div class="col-span-2">
                            <flux:card>
                                <div class="flex justify-between">
                                    <flux:heading size="lg">{{$type}} Finances</flux:heading>
                                </div>
                                {{-- wire:loading should just target the Reimbursment search_li not the entire Proejct Finances wrapper--}}
                                <x-lists.ul
                                    {{-- wire:target="print"
                                    wire:loading.attr="disabled"
                                    wire:loading.class="opacity-50 text-opacity-40" --}}
                                    >
                                    <x-lists.search_li
                                        :basic=true
                                        :line_title="'Estimate'"
                                        :line_data="money($estimate->project->finances['estimate'])"
                                        >
                                    </x-lists.search_li>

                                    <x-lists.search_li
                                        :basic=true
                                        :line_title="'Change Order'"
                                        :line_data="money($estimate->project->finances['change_orders'])"
                                        >
                                    </x-lists.search_li>

                                    @if($estimate->reimbursments)
                                        <x-lists.search_li
                                            :basic=true
                                            :line_title="'Reimbursements'"
                                            :line_data="money($estimate->reimbursments)"
                                            >
                                        </x-lists.search_li>
                                    @endif

                                    <x-lists.search_li
                                        :basic=true
                                        :bold="TRUE"
                                        {{-- make gray --}}
                                        :line_title="'TOTAL ESTIMATE'"
                                        :line_data="money($estimate_total + $estimate->reimbursments)"
                                        >
                                    </x-lists.search_li>

                                    <x-lists.search_li
                                        :basic=true
                                        :bold="TRUE"
                                        {{-- make gray --}}
                                        :line_title="'TOTAL PAYMENTS'"
                                        :line_data="'-' . money($payments->sum('amount'))"
                                        >
                                    </x-lists.search_li>

                                    <x-lists.search_li
                                        :basic=true
                                        :bold="TRUE"
                                        {{-- make gray --}}
                                        :line_title="'BALANCE'"
                                        :line_data="money(($estimate_total + $estimate->reimbursments) - $payments->sum('amount'))"
                                        >
                                    </x-lists.search_li>
                                </x-lists.ul>
                            </flux:card>
                        </div>
                    </div>
                @endif

                @if($type == 'Estimate')
                    <div style="page-break-before: always;"> </div>
                    <div>
                        @if(empty($estimate->payments))
                            <p><b><i>*The below Contract is a sample. It is not meant to be signed until a finalized Estimate is avaliable.</i></b></p>
                            <br>
                        @endif
                        <h1 class="text-xl font-bold">CONTRACTOR AGREEMENT</h1>
                        <p>THIS AGREEMENT made on {{today()->format('m/d/Y')}}, by and between {{$estimate->vendor->business_name}}, hereinafter called the Contractor, and {{$estimate->client->name}}, hereinafter called the Owner. WITNESSETH, that the Contractor and the Owner for the consideration named herein agree as follows:</p>
                        <br>
                        <h2 class="text-lg font-semibold">ARTICLE 1. SCOPE OF THE WORK</h2>
                        <p>The Contractor shall furnish all the construction materials and perform all of the work shown on the drawings and/or described in the specifications entitled Estimate {{$estimate->number}}, as annexed hereto as it pertains to work to be performed on property located at: </p>
                        <br>
                        <p>{!!$estimate->project->full_address!!}</p>
                        <br>
                        <p>The Owner is responsible for all finish materials unless otherwire noted in the Estimate.</p>
                        <br>
                        <h2 class="text-lg font-semibold">ARTICLE 2. TIME OF COMPLETION</h2>
                        <p>The work to be performed under this Contract shall be commenced on or before {{$estimate->start_date ? $estimate->start_date->format('m/d/Y') : 'START_DATE_HERE'}}, provided all permits are approved in a timely manner prior to the start date and all finish material is available. The work shall be substantially completed {{$estimate->end_date ? $estimate->end_date->format('m/d/Y') : 'END_DATE_HERE'}}, provided no Change Orders are added to this estimate, inspections are readily available, and all finish material is available. Such changes will alter the completion date.</p>
                        <br>
                        <h2 class="text-lg font-semibold">ARTICLE 3. THE CONTRACT PRICE</h2>
                        <p>The owner shall pay the Contractor for the material and labor to be performed under the Contract the sum of ---{{money($estimate_total)}}--- Dollars ($), {{$estimate_total_words}}, subject to additions and deductions pursuant to authorized change orders. </p>
                        <br>
                        <h2 class="text-lg font-semibold">ARTICLE 4. PROGRESS PAYMENTS</h2>
                        <p>Payments of the Contract price shall be paid in the manner following and shall not be unreasonably withheld.</p>
                        <p>Construction Payments:</p>

                        @if(!empty($estimate->payments))
                            <x-cards.body>
                                <div class="grid grid-cols-4 gap-4">
                                    <div class="col-span-3">
                                        <table class="min-w-full divide-y divide-gray-300">
                                            {{--  class="text-gray-900 border-b border-gray-400" --}}
                                            <thead>
                                                <tr>
                                                    <th
                                                        scope="col"
                                                        class="px-3 py-2 text-sm font-semibold text-left text-gray-900"
                                                        >
                                                        Payment
                                                    </th>
                                                    <th
                                                        scope="col"
                                                        class="px-3 py-2 text-sm font-semibold text-left text-gray-900"
                                                        >
                                                        Description
                                                    </th>
                                                    <th
                                                        scope="col"
                                                        class="px-3 py-2 text-sm font-semibold text-left text-gray-900"
                                                        >
                                                        Amount
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-200">
                                                @if($estimate->payments)
                                                    @foreach($estimate->payments as $key => $payment)
                                                        <tr>
                                                            <td class="px-3 py-2 text-sm text-gray-900 whitespace-nowrap">Payment {{$key + 1}}</td>
                                                            <td class="px-3 py-2 text-sm text-gray-900 whitespace-nowrap">{{$payment['description']}}</td>
                                                            <td class="px-3 py-2 text-sm text-gray-900 whitespace-nowrap">@if($loop->last && $payment['amount'] == '') Balance @else {{money($payment['amount'])}} @endif</td>
                                                        </tr>
                                                    @endforeach
                                                @endif
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </x-cards.body>
                        @else
                            <br>
                            <p><b><i>PAYMENT SCHEDULE HERE. Avaliable when this Contract is ready to sign.</i></b></p>
                            <br>
                        @endif

                        <hr>
                        <p>Any Change Order payments due after that scope is completed. Payment for any stone, glass fabrications, and finish material reimbursments due upon purchase. Balance payment may be split if needed.</p>
                        <br>
                        <p>(continued on next page.) </p>
                    </div>
                    <div style="page-break-before: always;"> </div>
                    <div>
                        <h2 class="text-lg font-semibold">ARTICLE 5. GENERAL PROVISIONS</h2>
                        <ol class="list-disc">
                            <li>All work shall be completed in a workmanship-like manner and in compliance with all building codes and other applicable laws.</li>
                            <li>To the extent required by law all work shall be performed by individuals duly licensed and authorized by law to perform said work.</li>
                            <li>Contractor may at its discretion engage subcontractors to perform work hereunder, provided Contractor shall fully pay said subcontractor and in all instances remain responsible for the proper completion of this Contract.</li>
                            <li>All change orders will be issued electronically on separate drawings and/or described in a separate specification entitled Estimate {{$estimate->number}}, section "Change Order".</li>
                            <li>Contractor warrants it is adequately insured for injury to its employees and others incurring loss or injury as a result of the acts of Contractor or its employees and subcontractors.</li>
                            <li>Contractor agrees to remove all debris and leave the premises in broom clean condition.</li>
                            <li>In the event Owner shall fail to pay any periodic or installment payment due hereunder, Contractor may cease work without breach pending payment or resolution of any dispute.</li>
                            <li>All disputes hereunder shall be resolved by binding arbitration in accordance with the rules of the American Arbitration Association.</li>
                            <li>Contractor shall not be liable for any delay due to circumstances beyond its control including strikes, casualty or general unavailability of materials, change orders, permit and inspection delays, and scope of work adjustments.</li>
                            <li>Contractor warrants all work for a period of 12 months following completion. Owner to notify contractor of a defect in a timely matter. Contractor to evaluate within 10 business days and provide a solution both parties agree upon. Contractor is not responsible for finish material defects.</li>
                            {{-- <li>Contractor to provide 2 mechanical lien waivers. 1st partial lien waiver after 2nd payment clears and final lien waiver after last payment clears.</li> --}}
                            {{-- <li>Contractor shall provide Owner a certificate of insurance for both, Workers Compensation Insurance, and General Liability Insurance. Contractor agrees to have Owner named as additional insureds under its General Liability policy. Owner to verify policy limits prior to Contract execution.</li> --}}
                        </ol>
                        <br>
                        <br>
                        <br>

                        @if($estimate->payments)
                            <p>Signed
                            <br><br><br></p>
                            <p>Owner or Authorized Representative _________ / ______/{{today()->format('Y')}}
                            <br><br><br><br></p>
                            <p>Contractor _________ / ______/{{today()->format('Y')}}</p>
                        @endif
                    </div>
                @endif
            </div>
        </flux:main>
    </body>
</html>
