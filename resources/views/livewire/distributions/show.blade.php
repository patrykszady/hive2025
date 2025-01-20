@section('title','Hive Distribution | ' . $distribution->name)
<div>
	<x-page.top
        h1="<b>{{$distribution->name}}</b> Distribution"
        p="{!! auth()->user()->vendor->name !!} distribution."
        {{-- right_button_href="#" --}}
		{{-- right_button_href="{{auth()->user()->can('update', $project) ? route('projects.show', $project->id) : ''}}" --}}
        {{-- right_button_text="Add Distribution" --}}
        >
    </x-page.top>

    <div class="grid max-w-xl grid-cols-4 gap-4 mx-auto lg:max-w-5xl sm:px-6">
		{{--  lg:h-32 lg:sticky lg:top-5 --}}
		<div class="col-span-4 lg:col-span-2">
			{{-- PROJECT DETAILS --}}
			<x-cards.wrapper>
				<x-cards.heading>
					<x-slot name="left">
						<h1 class="text-lg">Distribution Details</b></h1>
					</x-slot>

                    {{-- <x-slot name="right">
                        <x-cards.button href="#">
                            Add New
                        </x-cards.button>
                    </x-slot> --}}
				</x-cards.heading>
				<x-cards.body>
					<x-lists.ul>
                        <x-lists.search_li
                            basic=true
                            line_title="Total Earned YTD"
                            line_data="{{money($distribution->earned)}}"
                            >
                        </x-lists.search_li>
                        <x-lists.search_li
                            basic=true
                            line_title="Paid YTD"
                            line_data="{{money($distribution->paid)}}"
                            >
                        </x-lists.search_li>
                        <x-lists.search_li
                            basic=true
                            line_title="Balance YTD"
                            line_data="{{money($distribution->earned - $distribution->paid)}}"
                            >
                        </x-lists.search_li>
					</x-lists.ul>
				</x-cards.body>
			</x-cards.wrapper>

            <br>
            {{-- DISTRIBUTION PROJECT DETAILS --}}
            <x-cards.wrapper>
                <x-cards.heading>
                    <x-slot name="left">
                        <h1 class="text-lg">Distribution Projects Details</h1>
                    </x-slot>
                </x-cards.heading>
                <x-cards.body>
                    <x-lists.ul>
                        @foreach($distribution_projects as $distribution_project)
                            @php
                                $line_details = [
                                    1 => [
                                        'text' => $distribution_project->pivot->percent . '<b>%</b>',
                                        'icon' => ''
                                        ],
                                    2 => [
                                        'text' => money($distribution_project->pivot->amount),
                                        'icon' => 'M10.75 10.818v2.614A3.13 3.13 0 0011.888 13c.482-.315.612-.648.612-.875 0-.227-.13-.56-.612-.875a3.13 3.13 0 00-1.138-.432zM8.33 8.62c.053.055.115.11.184.164.208.16.46.284.736.363V6.603a2.45 2.45 0 00-.35.13c-.14.065-.27.143-.386.233-.377.292-.514.627-.514.909 0 .184.058.39.202.592.037.051.08.102.128.152z M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-6a.75.75 0 01.75.75v.316a3.78 3.78 0 011.653.713c.426.33.744.74.925 1.2a.75.75 0 01-1.395.55 1.35 1.35 0 00-.447-.563 2.187 2.187 0 00-.736-.363V9.3c.698.093 1.383.32 1.959.696.787.514 1.29 1.27 1.29 2.13 0 .86-.504 1.616-1.29 2.13-.576.377-1.261.603-1.96.696v.299a.75.75 0 11-1.5 0v-.3c-.697-.092-1.382-.318-1.958-.695-.482-.315-.857-.717-1.078-1.188a.75.75 0 111.359-.636c.08.173.245.376.54.569.313.205.706.353 1.138.432v-2.748a3.782 3.782 0 01-1.653-.713C6.9 9.433 6.5 8.681 6.5 7.875c0-.805.4-1.558 1.097-2.096a3.78 3.78 0 011.653-.713V4.75A.75.75 0 0110 4z'

                                        ],
                                    // 3 => [
                                    //     //$transaction_found->bank_account->bank->name
                                    //     'text' => $transaction_found->bank_account->bank->name,
                                    //     'icon' => 'M4 4a2 2 0 00-2 2v1h16V6a2 2 0 00-2-2H4z M18 9H2v5a2 2 0 002 2h12a2 2 0 002-2V9zM4 13a1 1 0 011-1h1a1 1 0 110 2H5a1 1 0 01-1-1zm5-1a1 1 0 100 2h1a1 1 0 100-2H9z'
                                    //     ],
                                    ];
                            @endphp

                            <x-lists.search_li
                                line_title="{!!$distribution_project->name!!}"
                                href="{{route('projects.show', $distribution_project->id)}}"
                                hrefTarget="_blank"
                                :line_details="$line_details"
                                >
                            </x-lists.search_li>
                        @endforeach
					</x-lists.ul>
                </x-cards.body>
                <x-cards.footer>
                    {{ $distribution_projects->links() }}
                </x-cards.footer>
            </x-cards.wrapper>
		</div>

        <div class="col-span-4 lg:col-span-2">
			{{-- DISTRIBUTION VENDORS TOTAL --}}
            <x-cards.wrapper>
				<x-cards.heading>
					<x-slot name="left">
						<h1 class="text-lg">Distribution Vendor Totals</h1>
					</x-slot>

					{{-- <x-slot name="right">
						<x-cards.button
							wire:click="$emitTo('bids.bids-form', 'addBids', {{$project}}, {{auth()->user()->vendor}})"
							>
							Edit Bid
						</x-cards.button>
					</x-slot> --}}
				</x-cards.heading>
				<x-cards.body>
					<x-lists.ul>
                        @foreach ($distribution_vendors as $distribution_vendor)
                            {{-- @foreach($distribution_vendor as $distribution_vendor_expense)
                                @dd($distribution_vendor_expense)
                            @endforeach
                             --}}
                            <x-lists.search_li
                                :basic=true
                                line_title="{!! $distribution_vendor->vendor->business_name !!}"
                                {{-- emit ... expenses.show, where distribution = $distribution, where vendor = $vendor, where dates?  --}}
                                href="{{route('vendors.show', $distribution_vendor->vendor->id)}}"
                                :href_target="'blank'"
                                :line_data="money($distribution_vendor->sum)"
                                >
                            </x-lists.search_li>
                        @endforeach
					</x-lists.ul>
				</x-cards.body>
                <x-cards.footer>
                    {{-- {{ $projects_has_dis->links() }} --}}
                </x-cards.footer>
			</x-cards.wrapper>
			<br>
		</div>
	</div>
{{--
    @livewire('distributions.distribution-projects-form') --}}
</div>
