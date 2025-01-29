<div>
	<x-page.top
        class="max-w-xl lg:max-w-5xl"
        h1="{!! $user->full_name !!}"
        p="{{$user->this_vendor ? 'Team Member for ' . $user->this_vendor->name : ''}}"
        {{-- right_button_href="{{auth()->user()->can('update', $vendor) ? route('vendors.show', $vendor->id) : ''}}"
        right_button_text="Edit Vendor" --}}
        >
    </x-page.top>

	<div class="grid max-w-xl grid-cols-4 gap-4 mx-auto lg:max-w-5xl sm:px-6">
        <div class="col-span-4 space-y-4 lg:col-span-2 lg:top-5">
            {{-- USER DETAILS --}}
            <div class="col-span-4 lg:col-span-2">
                <x-cards>
                    <x-cards.heading>
                        <x-slot name="left">
                            <h1 class="text-lg">User Details</h1>
                            {{-- @if($registration)
                                <p class="max-w-2xl mt-1 text-sm text-gray-500">Confirm {{$vendor->business_name}} information.</p>
                            @endif --}}
                        </x-slot>

                        @if($user->this_vendor && auth()->user()->vendor_role == 'Admin')
                            @can('update', $user)
                                <x-slot name="right">
                                    <x-cards.button
                                        button_color='red'
                                        wire:click="$dispatchTo('users.user-create', 'removeMember', { user: {{$user->id}} })"
                                        wire:confirm.prompt="Are you sure you want to remove this User from this Vendor?\n\nType REMOVE to confirm|REMOVE"
                                        >
                                        Remove User From Vendor
                                    </x-cards.button>

                                    <livewire:users.user-create />
                                </x-slot>
                            @endcan
                        @endif
                    </x-cards.heading>

                    <x-cards.body>
                        <x-lists.ul>
                            <x-lists.search_li
                                :basic=true
                                :line_title="'Name'"
                                :line_data="$user->full_name"
                                {{-- :bubble_message="'Success'" --}}
                                >
                            </x-lists.search_li>

                            <x-lists.search_li
                                :basic=true
                                :line_title="'Email'"
                                :line_data="$user->email"
                                >
                            </x-lists.search_li>

                            {{-- Retail --}}
                            {{-- @if($vendor->business_type != 'Retail')
                                <x-lists.search_li
                                    :basic=true
                                    :line_title="'Vendor Address'"
                                    href="{{$vendor->getAddressMapURI()}}"
                                    :href_target="'blank'"
                                    :line_data="$vendor->full_address"
                                    >
                                </x-lists.search_li>
                            @endif
                            --}}
                            <x-lists.search_li
                                :basic=true
                                :line_title="'Cell Phone'"
                                :line_data="$user->cell_phone"
                                >
                            </x-lists.search_li>

                            @if($user->this_vendor)
                                @can('update', $user)
                                    <x-lists.search_li
                                        :basic=true
                                        :line_title="'Start Date'"
                                        :line_data="$user->this_vendor->pivot->start_date->format('m/d/Y')"
                                        >
                                    </x-lists.search_li>

                                    <x-lists.search_li
                                        :basic=true
                                        :line_title="'Hourly Rate'"
                                        :line_data="money($user->this_vendor->pivot->hourly_rate)"
                                        >
                                    </x-lists.search_li>
                                @endcan

                                <x-lists.search_li
                                    :basic=true
                                    :line_title="'Vendor Role'"
                                    :line_data="$user->getVendorRole($user->this_vendor->id)"
                                    >
                                </x-lists.search_li>
                            @endif
                        </x-lists.ul>
                    </x-cards.body>
                </x-cards>
            </div>
        </div>

        {{-- VENDOR DETAILS --}}
        @if($user->this_vendor)
            <div class="col-span-4 lg:col-span-2">
                <livewire:vendors.vendor-details :vendor="$user->vendor">
            </div>
        @endif

        {{-- USER / VENDOR FINANCES --}}
        @if(!is_null($user->this_vendor))
        @can('update', $user)
            <div class="col-span-4 lg:col-span-2">
                <div
                    class="w-full"
                    x-data="{
                        init() {
                            let chart = new Chart(this.$refs.canvas.getContext('2d'), {
                                type: 'doughnut',

                                data: {
                                    labels: ['Timesheets Paid', 'Timesheets Paid By', 'Distribution Checks', 'Distribution Expenses', ' Timesheets Paid Others', 'Expenses Paid', 'Total User', 'Paid For'],
                                    datasets: [{
                                        data: [{{$timesheets_paid}}, {{$timesheets_paid_by}}, {{$distribution_checks}}, {{$distribution_expenses}}, {{$timesheets_paid_others}}, {{$expenses_paid}}, 0, 0],
                                        label: '2023 ALL',
                                        backgroundColor: [
                                            '#194d19',
                                            '#008000',
                                            '#38B000',
                                            '#70E000',
                                            '#F2545B',
                                            '#D64045',
                                            '#B2BABB',
                                            '#CACFD2'
                                        ],
                                        {{-- hoverOffset: 8 --}}
                                    },
                                    {
                                        data: [0, 0, 0, 0, 0, 0, {{$timesheets_paid + $timesheets_paid_by + $distribution_checks + $distribution_expenses}}, {{$timesheets_paid_others + $expenses_paid}}],
                                        label: '2023 SPLIT',
                                        backgroundColor: [
                                            '#194d19',
                                            '#008000',
                                            '#38B000',
                                            '#70E000',
                                            '#F2545B',
                                            '#D64045',
                                            '#B2BABB',
                                            '#CACFD2'
                                        ],
                                        {{-- hoverOffset: 4 --}}
                                    }],
                                },
                                options: {
                                    interaction: { intersect: false },
                                    cutout: '50%',
                                    borderColor: 'transparent',
                                    borderWidth: '2',
                                    borderColor: 'white',
                                    {{-- scales: { y: { beginAtZero: true }}, --}}
                                    plugins: {
                                        legend: { position: 'bottom', display: true },
                                        tooltip: {
                                            displayColors: false
                                        }
                                    }
                                }
                            })

                            {{-- this.$watch('values', () => {
                                chart.data.labels = this.labels
                                chart.data.datasets[0].data = this.values
                                chart.update()
                            }) --}}
                        }
                    }"
                    >
                    <canvas x-ref="canvas" class="bg-transparent rounded-lg"></canvas>
                </div>
            </div>
            <div class="col-span-4 lg:col-span-2 lg:col-start-3">
                <div class="col-span-4">
                    <x-cards>
                        <x-cards.heading>
                            <x-slot name="left">
                                <h1 class="text-lg">User Finances</h1>
                            </x-slot>
                        </x-cards.heading>

                        <x-cards.body>
                            <div class="px-4 sm:px-6 lg:px-8">
                                <div class="flow-root mt-8">
                                    <div class="-mx-4 -my-2 overflow-x-auto sm:-mx-6 lg:-mx-8">
                                        <div class="inline-block min-w-full py-2 align-middle sm:px-6 lg:px-8">
                                            <table class="min-w-full divide-y divide-gray-300">
                                                <thead>
                                                    <tr class="divide-x divide-gray-200">
                                                        <th scope="col"
                                                            class="py-3.5 pl-4 pr-4 text-left text-sm font-semibold text-gray-900 sm:pl-0"></th>
                                                        <th scope="col" class="px-4 py-3.5 text-left text-sm font-semibold text-gray-900">{{$year}}</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="bg-white divide-y divide-gray-200">
                                                    <tr class="divide-x divide-gray-200">
                                                        <td class="py-4 pl-4 pr-4 text-sm font-medium text-gray-900 whitespace-nowrap sm:pl-0">
                                                            Checks Written</td>
                                                        <td class="p-4 text-sm text-gray-800 whitespace-nowrap">{{money($checks_written)}}</td>
                                                    </tr>
                                                    <tr class="divide-x divide-gray-200">
                                                        <td class="py-4 pl-4 pr-4 text-sm text-gray-900 whitespace-nowrap sm:pl-0">
                                                            &emsp; Timesheets Paid</td>
                                                        <td class="p-4 text-sm text-green-800 whitespace-nowrap">{{money($timesheets_paid)}}</td>
                                                    </tr>

                                                    @if($distribution_checks != 0)
                                                        <tr class="divide-x divide-gray-200">
                                                            <td class="py-4 pl-4 pr-4 text-sm text-gray-900 whitespace-nowrap sm:pl-0">
                                                                &emsp; Distribution Checks</td>
                                                            <td class="p-4 text-sm text-green-800 whitespace-nowrap">{{money($distribution_checks)}}</td>
                                                        </tr>
                                                    @endif

                                                    @if($user_checks != 0)
                                                        <tr class="divide-x divide-gray-200">
                                                            <td class="py-4 pl-4 pr-4 text-sm text-gray-900 whitespace-nowrap sm:pl-0">
                                                                &emsp; Member Extra Payments</td>
                                                            <td class="p-4 text-sm text-green-800 whitespace-nowrap">{{money($user_checks)}}</td>
                                                        </tr>
                                                    @endif

                                                    @if($timesheets_paid_others != 0)
                                                        <tr class="divide-x divide-gray-200">
                                                            <td class="py-4 pl-4 pr-4 text-sm text-gray-900 whitespace-nowrap sm:pl-0">
                                                                &emsp; Timesheets Paid Others</td>
                                                            <td class="p-4 text-sm text-red-800 whitespace-nowrap">{{money($timesheets_paid_others)}}</td>
                                                        </tr>
                                                    @endif

                                                    <tr class="divide-x divide-gray-200">
                                                        <td class="py-4 pl-4 pr-4 text-sm text-gray-900 whitespace-nowrap sm:pl-0">
                                                            &emsp; Expenses Paid</td>
                                                        <td class="p-4 text-sm text-red-800 whitespace-nowrap">{{money($expenses_paid)}}</td>
                                                    </tr>

                                                    <tr class="divide-x divide-gray-200">
                                                        <td class="py-4 pl-4 pr-4 text-sm font-medium text-gray-900 whitespace-nowrap sm:pl-0">
                                                            &emsp; TOTAL CHECKS FOR USER</td>
                                                        <td class="p-4 text-sm text-gray-800 whitespace-nowrap">{{money($timesheets_paid + $distribution_checks)}}</td>
                                                    </tr>

                                                    @if($timesheets_paid_by != 0)
                                                        <tr class="divide-x divide-gray-200">
                                                            <td class="py-4 pl-4 pr-4 text-sm text-gray-900 whitespace-nowrap sm:pl-0">
                                                                Timesheets Paid By</td>
                                                            <td class="p-4 text-sm text-green-800 whitespace-nowrap">{{money($timesheets_paid_by)}}</td>
                                                        </tr>
                                                    @endif

                                                    @if($distribution_expenses != 0)
                                                        <tr class="divide-x divide-gray-200">
                                                            <td class="py-4 pl-4 pr-4 text-sm text-gray-900 whitespace-nowrap sm:pl-0">
                                                                Distribution Expenses</td>
                                                            <td class="p-4 text-sm text-green-800 whitespace-nowrap">{{money($distribution_expenses)}}</td>
                                                        </tr>
                                                    @endif

                                                    <tr class="divide-x divide-gray-200">
                                                        <td class="py-4 pl-4 pr-4 text-sm font-medium text-gray-900 whitespace-nowrap sm:pl-0">
                                                            TOTAL FOR USER</td>
                                                        <td class="p-4 text-sm font-bold text-gray-800 whitespace-nowrap">{{money($timesheets_paid + $distribution_checks + $distribution_expenses + $timesheets_paid_by)}}</td>
                                                    </tr>

                                                    <tr class="divide-x divide-gray-200">
                                                        <td class="py-4 pl-4 pr-4 text-sm text-gray-900 whitespace-nowrap sm:pl-0">
                                                            &emsp; <i>difference</i></td>
                                                        <td class="p-4 text-sm text-gray-800 whitespace-nowrap"><i>{{money($difference)}}</i></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </x-cards.body>
                    </x-cards>
                </div>
            </div>
        @endcan
        @endif
	</div>
</div>
