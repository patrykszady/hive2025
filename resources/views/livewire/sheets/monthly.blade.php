<div>
    <x-cards>
        <x-cards.heading>
            <x-slot name="left">
                <h1 class="text-lg">Payments & Expenses</h1>
            </x-slot>
        </x-cards.heading>

        <x-cards.body>
            <div class="px-4 sm:px-6 lg:px-8">
                <div class="flow-root -mt-8">
                    <div class="-mx-4 -my-2 overflow-x-auto sm:-mx-6 lg:-mx-8">
                        <div class="inline-block min-w-full py-2 align-middle sm:px-6 lg:px-8">
                            {{-- GRAPH --}}
                            <div
                                class="w-full pl-11 sm:pl-7 pr-14"
                                x-data="{
                                    init() {
                                        let chart = new Chart(this.$refs.canvas.getContext('2d'), {
                                            type: 'line',

                                            data: {
                                                labels: [
                                                    @foreach($months as $month_format => $month)
                                                        '{{$month_format}}',
                                                    @endforeach
                                                ],
                                                datasets: [
                                                    {
                                                        data: [
                                                            @foreach($months as $month_format => $month)
                                                                {{array_key_exists('monthly_payments', $month) ? $month['monthly_payments']->sum('amount') : '0.00'}},
                                                            @endforeach
                                                        ],
                                                        label: 'Payments',
                                                        borderColor: [
                                                            '#16A34A',
                                                        ],
                                                        tension: 0.3
                                                    },
                                                    {
                                                        data: [
                                                            @foreach($months as $month_format => $month)
                                                                {{array_key_exists('monthly_expenses', $month) ? $month['monthly_expenses']->sum('amount') : '0.00'}},
                                                            @endforeach
                                                        ],
                                                        label: 'Expenses',
                                                        borderColor: [
                                                            '#F2545B',
                                                        ],
                                                        tension: 0.3
                                                    },
                                                    {
                                                        data: [
                                                            @foreach($months as $month_format => $month)
                                                                {{array_key_exists('monthly_timesheets', $month) ? $month['monthly_timesheets']->sum('amount') : '0.00'}},
                                                            @endforeach
                                                        ],
                                                        label: 'Timesheets',
                                                        borderColor: [
                                                            '#FE9900',
                                                        ],
                                                        tension: 0.3
                                                    },
                                                    {
                                                        data: [
                                                            @foreach($months as $month_format => $month)
                                                                {{array_key_exists('monthly_total_expenses', $month) ? $month['monthly_total_expenses'] : '0.00'}},
                                                            @endforeach
                                                        ],
                                                        label: 'Total Spend',
                                                        borderColor: [
                                                            '#c14348',
                                                        ],
                                                        tension: 0.3,
                                                        borderDash: [15,5]
                                                    },
                                                    {
                                                        data: [
                                                            @foreach($months as $month_format => $month)
                                                                {{array_key_exists('last_year_monthly_payments', $month) ? $month['last_year_monthly_payments']->sum('amount') : '0.00'}},
                                                            @endforeach
                                                        ],
                                                        label: 'Payments Year Before',
                                                        borderColor: [
                                                            '#a1a5a8',
                                                        ],
                                                        tension: 0.3,
                                                        borderDash: [5,5]
                                                    }
                                                ],
                                            },
                                            options: {
                                                interaction: { intersect: true },
                                                borderWidth: '2',
                                                responsive: true,
                                                maintainAspectRatio: false,
                                                borderColor: 'white',
                                                {{-- scales: { y: { beginAtZero: true }}, --}}
                                                plugins: {
                                                    legend: {
                                                        position: 'bottom',
                                                        display: true
                                                    },
                                                    tooltip: {
                                                        displayColors: true,
                                                        callbacks: {
                                                            title: function(context) {
                                                                let title = '';

                                                                return title;
                                                            }
                                                        }
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
                                <div class="h-64 sm:h-96">
                                    <canvas x-ref="canvas" class="bg-transparent rounded-lg"></canvas>
                                </div>
                            </div>
                            {{-- TABLE --}}
                            <table class="min-w-full divide-y divide-gray-300">
                                <thead>
                                    <tr class="divide-x divide-gray-200">
                                        <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 sm:pl-0"></th>
                                        @foreach($months as $month_format => $month)
                                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 whitespace-nowrap">{{$month_format}}&emsp;&emsp;</th>
                                        @endforeach
                                        {{-- <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-0">
                                            <span class="sr-only">Edit</span>
                                        </th> --}}
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <tr class="divide-x divide-gray-200">
                                        <td class="py-4 pl-4 pr-3 text-sm font-medium text-gray-900 whitespace-nowrap sm:pl-0">Payments</td>
                                        @foreach($months as $month_format => $month)
                                            <td class="px-3 py-4 text-sm text-gray-500 whitespace-nowrap">{{money(array_key_exists('monthly_payments', $month) ? $month['monthly_payments']->sum('amount') : '0.00')}}</td>
                                        @endforeach


                                        {{-- <td class="px-3 py-4 text-sm text-gray-500 whitespace-nowrap">Front-end Developer</td>
                                        <td class="px-3 py-4 text-sm text-gray-500 whitespace-nowrap">lindsay.walton@example.com</td>
                                        <td class="px-3 py-4 text-sm text-gray-500 whitespace-nowrap">Member</td> --}}

                                        {{-- <td class="relative py-4 pl-3 pr-4 text-sm font-medium text-right whitespace-nowrap sm:pr-0">
                                            <a href="#" class="text-indigo-600 hover:text-indigo-900">Edit<span class="sr-only">, Lindsay Walton</span></a>
                                        </td> --}}
                                    </tr>
                                    <tr class="divide-x divide-gray-200">
                                        <td class="py-4 pl-4 pr-3 text-sm font-medium text-gray-900 whitespace-nowrap sm:pl-0">Expenses</td>
                                        @foreach($months as $month_format => $month)
                                            <td class="px-3 py-4 text-sm text-gray-500 whitespace-nowrap">{{money(array_key_exists('monthly_expenses', $month) ? $month['monthly_expenses']->sum('amount') : '0.00')}}</td>
                                        @endforeach
                                    </tr>
                                    <tr class="divide-x divide-gray-200">
                                        <td class="py-4 pl-4 pr-3 text-sm font-medium text-gray-900 whitespace-nowrap sm:pl-0">Timesheets</td>
                                        @foreach($months as $month_format => $month)
                                            <td class="px-3 py-4 text-sm text-gray-500 whitespace-nowrap">{{money(array_key_exists('monthly_timesheets', $month) ? $month['monthly_timesheets']->sum('amount') : '0.00')}}</td>
                                        @endforeach
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
