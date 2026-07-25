{{-- lazy island: shared skeleton first, then the real table — same loading
     treatment as every other index card. --}}
@island(name: 'distributions-table', lazy: island_lazy(), always: true)
    @placeholder
        <x-index-table.placeholder
            heading="Distributions"
            subheading="Assign receipt emails to team members or office accounts."
            :columns="\App\Livewire\Distributions\DistributionsList::columnDefs()"
            :compact="false"
        >
            <x-slot:actions>
                @include('livewire.distributions.partials.list-actions')
            </x-slot:actions>
        </x-index-table.placeholder>
    @endplaceholder
<x-index-table heading="Distributions" subheading="Assign receipt emails to team members or office accounts.">
    <x-slot:actions>
        @include('livewire.distributions.partials.list-actions')
    </x-slot:actions>

    <x-slot:before>
        <livewire:distributions.distribution-create />
    </x-slot:before>

    <flux:table class="index-table [:where(&)]:p-0 [:where(&)]:space-y-0">
        <flux:table.columns>
            <flux:table.column class="w-[32%] min-w-0">Distribution</flux:table.column>
            <flux:table.column class="text-right w-[17%]">Balance</flux:table.column>
            <flux:table.column class="text-right w-[17%]">YTD Earned</flux:table.column>
            <flux:table.column class="text-right w-[17%]">YTD Paid</flux:table.column>
            <flux:table.column class="text-right w-[17%]">YTD Balance</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->distributions as $distribution)
                <flux:table.row :key="$distribution->id">
                    <flux:table.cell class="w-[32%] min-w-0">
                        <x-table-link :href="route('distributions.show', $distribution)" :label="$distribution->name" />
                    </flux:table.cell>
                    <flux:table.cell class="text-right {{ $distribution->balance < 0 ? 'text-red-600' : '' }}">
                        {{ money($distribution->balance ?? 0) }}
                    </flux:table.cell>
                    <flux:table.cell class="text-right">{{ money($distribution->ytd_earned ?? 0) }}</flux:table.cell>
                    <flux:table.cell class="text-right">{{ money($distribution->ytd_paid ?? 0) }}</flux:table.cell>
                    <flux:table.cell class="text-right {{ $distribution->ytd_balance < 0 ? 'text-red-600' : '' }}">
                        {{ money($distribution->ytd_balance ?? 0) }}
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</x-index-table>
@endisland
