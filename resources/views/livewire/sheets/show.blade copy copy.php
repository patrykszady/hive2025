<div class="max-w-xl mx-auto space-y-4 sm:px-6">
    <x-cards>
        <x-lists.ul>
            <x-lists.search_li
                :basic=true
                :bold="TRUE"
                :line_title="'REVENUE'"
                :line_data="money($revenue)"
                >
            </x-lists.search_li>
        </x-lists.ul>
    </x-cards>

    <x-cards accordian="OPENED">
        <x-cards.heading>
            <x-slot name="left">
                <h1 class="text-lg">Cost of Revenue</b></h1>
            </x-slot>
        </x-cards.heading>

        <x-cards.body>
            <x-lists.ul>
                <x-lists.search_li
                    :basic=true
                    :line_title="'Cost of Labor'"
                    :line_data="money($cost_of_labor_sum)"
                    >
                </x-lists.search_li>
                <x-lists.search_li
                    :basic=true
                    :line_title="'Cost of Materials'"
                    :line_data="money($cost_of_materials)"
                    >
                </x-lists.search_li>
            </x-lists.ul>
        </x-cards.body>

        <x-cards.footer has_ul="TRUE">
            <x-lists.ul>
                <x-lists.search_li
                    :basic=true
                    :bold="TRUE"
                    :line_title="'TOTAL'"
                    :line_data="money($cost_of_labor_sum + $cost_of_materials)"
                    >
                </x-lists.search_li>
            </x-lists.ul>
        </x-cards.footer>
    </x-cards>

    <x-cards accordian="OPENED">
        <x-cards.heading>
            <x-slot name="left">
                <h1 class="text-lg">Gross Profit</b></h1>
            </x-slot>
        </x-cards.heading>

        <x-cards.body>
            <x-lists.ul>
                <x-lists.search_li
                    :basic=true
                    :line_title="'Revenue'"
                    :line_data="money($revenue)"
                    >
                </x-lists.search_li>
                <x-lists.search_li
                    :basic=true
                    :line_title="'- Cost of Revenue'"
                    :line_data="money($cost_of_labor_sum + $cost_of_materials)"
                    >
                </x-lists.search_li>
            </x-lists.ul>
        </x-cards.body>

        <x-cards.footer has_ul="TRUE">
            <x-lists.ul>
                <x-lists.search_li
                    :basic=true
                    :bold="TRUE"
                    :line_title="'TOTAL'"
                    :line_data="money($revenue - $cost_of_labor_sum - $cost_of_materials)"
                    >
                </x-lists.search_li>
            </x-lists.ul>
        </x-cards.footer>
    </x-cards>

    {{-- order each category by sum of vendor for each category. High first --}}
    <x-cards accordian="CLOSED">
        <x-cards.heading>
            <x-slot name="left">
                <h1 class="text-lg">General & Administrative Expenses</h1>
            </x-slot>
        </x-cards.heading>

        <x-cards.body>
            <x-lists.ul>
                @foreach($general_expense_categories as $vendor_category_name => $general_expense_category)
                    <x-lists.search_li
                        :basic=true
                        :bold="TRUE"
                        :line_title="strtoupper($vendor_category_name)"
                        {{-- :line_data="money($general_expense_category->sum('amount'))" --}}
                        :line_data="'TEST / $'"
                        >
                    </x-lists.search_li>

                    @foreach($general_expense_category->vendors as $vendor)
                        <x-lists.search_li
                            :basic=true
                            :line_title="$vendor->name"
                            :line_data="money($vendor->expenses->sum('amount'))"
                            >
                        </x-lists.search_li>
                    @endforeach
                @endforeach
            </x-lists.ul>
        </x-cards.body>

        <x-cards.footer has_ul="TRUE">
            <x-lists.ul>
                <x-lists.search_li
                    :basic=true
                    :bold="TRUE"
                    :line_title="'TOTAL'"
                    :line_data="money($general_expenses)"
                    >
                </x-lists.search_li>
            </x-lists.ul>
        </x-cards.footer>
    </x-cards>

    <x-cards>
        <x-lists.ul>
            <x-lists.search_li
                :basic=true
                :bold="TRUE"
                :line_title="'NET INCOME'"
                :line_data="money($revenue - $cost_of_labor_sum - $cost_of_materials - $general_expenses)"
                >
            </x-lists.search_li>
        </x-lists.ul>
    </x-cards>
</div>
