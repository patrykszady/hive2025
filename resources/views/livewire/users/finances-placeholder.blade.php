<div class="space-y-6 select-none" aria-busy="true" aria-live="polite">
    <x-island-card :enter="true" :separator="true">
        <x-slot:actions>
            <div class="flex gap-2">
                <div class="h-6 w-6 rounded-full bg-zinc-200 dark:bg-zinc-700 animate-pulse"></div>
                <div class="h-6 w-6 rounded-full bg-zinc-200 dark:bg-zinc-700 animate-pulse"></div>
            </div>
        </x-slot:actions>

        @php($cols = 8) {{-- Placeholder assumes up to 8 year columns; real content will replace. --}}
        @php($rows = [
            'Checks Written',
            '  Timesheets Paid',
            '  Timesheets Paid Others',
            '  Timesheets Paid By',
            '  Expenses Paid',
            'TOTAL CHECKS FOR USER',
            'TOTAL FOR USER',
            '[Difference]'
        ])

        <div class="card-flush-bottom">
        <div class="overflow-x-auto">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column class="sticky left-0 z-20 bg-white dark:bg-zinc-800"></flux:table.column>
                    @for($c=0;$c<$cols;$c++)
                        <flux:table.column class="border-l border-zinc-200 dark:border-zinc-700">
                            <div class="mx-auto h-4 w-16 rounded bg-zinc-200 dark:bg-zinc-700 animate-pulse"></div>
                        </flux:table.column>
                    @endfor
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($rows as $index => $label)
                        <flux:table.row>
                            <flux:table.cell class="sticky left-0 z-10 bg-white dark:bg-zinc-800 border-r border-zinc-200 dark:border-zinc-700">
                                <div class="h-4 {{ $index < 5 ? 'w-48' : 'w-56' }} rounded bg-zinc-200 dark:bg-zinc-700 animate-pulse"></div>
                            </flux:table.cell>
                            @for($c=0;$c<$cols;$c++)
                                <flux:table.cell class="border-l border-zinc-200 dark:border-zinc-700">
                                    <div class="h-4 w-20 rounded bg-zinc-200 dark:bg-zinc-700 animate-pulse"></div>
                                </flux:table.cell>
                            @endfor
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
        </div>
    </x-island-card>
</div>
