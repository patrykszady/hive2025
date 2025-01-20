<div class="max-w-xl mx-auto space-y-4 sm:px-6">
    <x-cards>
        <x-cards.heading>
            <x-slot name="left">
                <h1 class="text-lg"></h1>
            </x-slot>
            <x-slot name="right">
                <x-cards.button
                    wire:click="export_csv"
                    >
                    Export CSV
                </x-cards.button>

                {{-- NEW PROJECT MODAL --}}
                {{-- <livewire:projects.project-create :$clients /> --}}
            </x-slot>
        </x-cards.heading>
    </x-cards>
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
                    :bold="TRUE"
                    :line_title="strtoupper('Cost of Labor')"
                    :line_data="money($cost_of_labor_sum)"
                    >
                </x-lists.search_li>
                <x-lists.ul>
                    @foreach($cost_of_labor_vendors as $vendor_name => $cost_of_labor_vendor)
                        <x-lists.search_li
                            :basic=true
                            :line_title="$vendor_name"
                            :line_data="money($cost_of_labor_vendor->sum('amount'))"
                            >
                        </x-lists.search_li>
                    @endforeach
                </x-lists.ul>

                <x-lists.search_li
                    :basic=true
                    :bold="TRUE"
                    :line_title="strtoupper('Cost of Materials')"
                    :line_data="money($cost_of_materials_sum)"
                    >
                </x-lists.search_li>
                <x-lists.ul>
                    @foreach($cost_of_materials_vendors as $vendor_name => $cost_of_materials_vendors)
                        <x-lists.search_li
                            :basic=true
                            :line_title="$vendor_name"
                            :line_data="money($cost_of_materials_vendors->sum('amount'))"
                            >
                        </x-lists.search_li>
                    @endforeach
                </x-lists.ul>
            </x-lists.ul>
        </x-cards.body>

        <x-cards.footer has_ul="TRUE">
            <x-lists.ul>
                <x-lists.search_li
                    :basic=true
                    :bold="TRUE"
                    :line_title="'TOTAL'"
                    :line_data="money($cost_of_labor_sum + $cost_of_materials_sum)"
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
                    :line_data="money($cost_of_labor_sum + $cost_of_materials_sum)"
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
                    :line_data="money($revenue - $cost_of_labor_sum - $cost_of_materials_sum)"
                    >
                </x-lists.search_li>
            </x-lists.ul>
        </x-cards.footer>
    </x-cards>

    {{-- order each category by sum of vendor for each category. High first --}}
    <x-cards accordian="CLOSED">
        <x-cards.heading>
            <x-slot name="left">
                <h1 class="text-lg">General & Administrative Expenses</b></h1>
            </x-slot>
        </x-cards.heading>

        <x-cards.body>
            <x-lists.ul>
                @foreach($general_expense_categories as $category_primary_name => $general_expense_category)
                    <x-lists.search_li
                        :basic=true
                        :bold="TRUE"
                        :no_hover="TRUE"
                        :line_title="strtoupper($category_primary_name)"
                        :line_data="money($general_expense_category->sum('amount'))"
                        >
                    </x-lists.search_li>

                    @foreach($general_expense_category->groupBy('category.friendly_detailed') as $category_friendly_detailed => $category_friendly_expenses)
                        <x-lists.search_li
                            :basic=true
                            :no_hover="TRUE"
                            {{-- '&emsp;' .  --}}
                            {{-- :line_title="strtoupper($category_friendly_detailed) . '<br><i>' . $category_primary_name . '</i>'" --}}
                            :line_title="$category_friendly_detailed"
                            :line_data="money($category_friendly_expenses->sum('amount'))"
                            >
                        </x-lists.search_li>

                        @foreach($category_friendly_expenses->groupBy('vendor.busienss_name') as $vendor_name => $general_expense_vendor_expenses)
                            <x-lists.search_li
                                {{-- wire:click="$dispatchTo('categories.vendor-categories-create', 'addCategories', { vendor: {{$general_expense_vendor_expenses->first()->vendor->id}} })" --}}
                                :basic=true
                                :line_title="$vendor_name"
                                :line_data="money($general_expense_vendor_expenses->sum('amount'))"
                                >
                            </x-lists.search_li>
                        @endforeach
                    @endforeach

                @endforeach
            </x-lists.ul>
        </x-cards.body>

        {{-- <livewire:categories.vendor-categories-create /> --}}

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
                :line_data="money($revenue - $cost_of_labor_sum - $cost_of_materials_sum - $general_expenses)"
                >
            </x-lists.search_li>
        </x-lists.ul>
    </x-cards>
</div>
