{{-- PROJECT DETAILS --}}
<x-lists.details_card>
    {{-- HEADING --}}
    <x-slot:heading>
        <div>
            <flux:heading size="lg" class="mb-0">Distributions</flux:heading>
            <flux:subheading class="mb-0">Split Project profits between shareholders.</flux:subheading>
        </div>

        <flux:button
            size="sm"
            wire:click="$dispatchTo('distributions.distribution-create', 'newDistribution')"
            >
            Add New
        </flux:button>
    </x-slot>

    {{-- DETAILS --}}
    <x-lists.details_list>
        @foreach($distributions as $distribution)
            {{--  . money($distribution->balances->balance) --}}
            <x-lists.details_item title="{{$distribution->name}}" detail="{{$registration == TRUE ? '' : 'Balance: '}}" href="{{$registration == TRUE ? '' : route('distributions.show', $distribution->id)}}" />
        @endforeach
    </x-lists.details_list>

    <livewire:distributions.distribution-create />
</x-lists.details_card>
