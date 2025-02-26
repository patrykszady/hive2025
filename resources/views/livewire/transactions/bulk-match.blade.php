<flux:card class="w-full px-4 sm:px-6 lg:max-w-xl lg:px-8 pb-5 mb-1}}">
    <div class="flex justify-between">
        <flux:heading size="lg">Vendor Recurring Transactions</flux:heading>
        <flux:button wire:click="$dispatchTo('bulk-match.bulk-match-create', 'newMatch')" size="sm" icon="plus">New Bulk Match</flux:button>
    </div>
    <flux:subheading class>Bulk Match Transactions for Retail Vendors. Manual Match Below.</flux:subheading>

    <flux:separator variant="subtle" class="my-2" />

    <flux:table>
        <flux:table.columns>
            <flux:table.column>Vendor</flux:table.column>
            <flux:table.column>Distribution</flux:table.column>
            <flux:table.column>Amount</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach($bulk_matches as $match)
                <flux:table.row :key="$match->vendor->id">
                    <flux:table.cell
                        wire:click="$dispatchTo('bulk-match.bulk-match-create', 'updateMatch', { match: {{$match->id}} })"
                        variant="strong"
                        class="cursor-pointer"
                        >
                        {{ $match->vendor->name }}
                    </flux:table.cell>
                    <flux:table.cell>{{ $match->distribution ? $match->distribution->name : 'SPLIT' }}</flux:table.cell>
                    <flux:table.cell>{{ $match->amount != NULL ? $match->options['amount_type'] . $match->amount : 'Any Amount' }}</flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    {{--  :distributions="$distributions" :vendors="$bulk_matches->unique('vendor.id')->pluck('vendor.id')" --}}
    <livewire:bulk-match.bulk-match-create />
</flux:card>
