<flux:card class="w-full px-4 sm:px-6 lg:max-w-xl lg:px-8 pb-5 mb-1}}">
    <div class="flex justify-between">
        <flux:heading size="lg">Vendor Recurring Transactions</flux:heading>
        <flux:button wire:click="$dispatchTo('bulk-match.bulk-match-create', 'newMatch')" size="sm" icon="plus">New Bulk Match</flux:button>
    </div>
    <flux:subheading class>Bulk Match Transactions for Retail Vendors. Manual Match Below.</flux:subheading>

    <flux:separator variant="subtle" class="my-2" />

    <flux:table>
        <flux:columns>
            <flux:column>Vendor</flux:column>
            <flux:column>Distribution</flux:column>
            <flux:column>Amount</flux:column>
        </flux:columns>

        <flux:rows>
            @foreach($bulk_matches as $match)
                <flux:row :key="$match->vendor->id">
                    <flux:cell
                        wire:click="$dispatchTo('bulk-match.bulk-match-create', 'updateMatch', { match: {{$match->id}} })"
                        variant="strong"
                        class="cursor-pointer"
                        >
                        {{ $match->vendor->name }}
                    </flux:cell>
                    <flux:cell>{{ $match->distribution ? $match->distribution->name : 'SPLIT' }}</flux:cell>
                    <flux:cell>{{ $match->amount != NULL ? $match->options['amount_type'] . $match->amount : 'Any Amount' }}</flux:cell>
                </flux:row>
            @endforeach
        </flux:rows>
    </flux:table>

    {{--  :distributions="$distributions" :vendors="$bulk_matches->unique('vendor.id')->pluck('vendor.id')" --}}
    <livewire:bulk-match.bulk-match-create />
</flux:card>
