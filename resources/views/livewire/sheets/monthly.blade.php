<flux:card>
    <div class="flex justify-between">
        <flux:heading size="lg">Payments & Expenses</flux:heading>
        {{-- <flux:button
            wire:navigate.hover
            href="{{route('timesheets.index')}}"
            size="sm"
            >
            Confirm Timesheets
        </flux:button> --}}
    </div>
    {{-- <flux:subheading><i>Pick Date to add or edit Daily Hours for {{auth()->user()->first_name}}</i></flux:subheading> --}}

    <flux:separator variant="subtle" />

    <flux:chart wire:model="months" class="aspect-[3/1]">
        <flux:chart.viewport class="min-h-[20rem]" >
            <flux:chart.svg>
                <flux:chart.line field="this_year_payments" class="text-green-500 dark:text-green-400" />
                <flux:chart.line field="last_year_payments" class="text-gray-300 dark:text-white/40" stroke-dasharray="4 4" />
                <flux:chart.line field="monthly_total_expenses" class="text-orange-500 dark:text-orange-400" />

                <flux:chart.axis axis="x" field="month_year">
                    <flux:chart.axis.line />
                    <flux:chart.axis.tick />
                </flux:chart.axis>

                <flux:chart.axis axis="y" :format="['style' => 'currency', 'currency' => 'USD']">
                    <flux:chart.axis.grid />
                    <flux:chart.axis.tick />
                </flux:chart.axis>

                <flux:chart.cursor />
            </flux:chart.svg>

            <flux:chart.tooltip>
                <flux:chart.tooltip.heading field="month_year" :format="['month' => 'short', 'day' => 'numeric']" />
                <flux:chart.tooltip.value field="this_year_payments" label="Payments" :format="['style' => 'currency', 'currency' => 'USD']" />
                <flux:chart.tooltip.value field="last_year_payments" label="Last Year Payments" :format="['style' => 'currency', 'currency' => 'USD']" />
                <flux:chart.tooltip.value field="monthly_total_expenses" label="Expenses" :format="['style' => 'currency', 'currency' => 'USD']" />
            </flux:chart.tooltip>
        </flux:chart.viewport>

        <div class="flex justify-center gap-4 pt-4">
            <flux:chart.legend label="Payments">
                <flux:chart.legend.indicator class="bg-green-400" />
            </flux:chart.legend>

            <flux:chart.legend label="Expenses">
                <flux:chart.legend.indicator class="bg-orange-400" />
            </flux:chart.legend>

            <flux:chart.legend label="Last Year Payments">
                <flux:chart.legend.indicator class="bg-gray-400" />
            </flux:chart.legend>
        </div>
    </flux:chart>
</flux:card>
