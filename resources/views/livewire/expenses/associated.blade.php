<x-form-modal name="associated_expenses_form_modal" title="Link Expenses">
    <form id="associated_expenses_form_modal_form" wire:submit="save" class="space-y-4">
        <flux:field>
            <flux:input
                wire:model.live.debounce.300ms="search"
                icon="magnifying-glass"
                clearable
                placeholder="Find any expense: id, amount, vendor or invoice"
            />
            <flux:description>Without a search, the list suggests this vendor's other expenses (newest first) and any expense of the same amount within three months.</flux:description>
        </flux:field>

        @if ($this->candidates->isEmpty())
            <flux:text>{{ trim($search) === '' ? 'No suggestions. Search for the expense above.' : 'No expense matches that search.' }}</flux:text>
        @else
            <flux:radio.group wire:model.live="associate_expense" label="Expenses" variant="cards" class="flex-col">
                @foreach($this->candidates as $expense)
                    <flux:radio value="{{$expense->id}}" wire:key="associate-{{$expense->id}}" label="{{money($expense->amount)}}" description="#{{ $expense->id }} | {{$expense->date->format('m/d/Y')}} | {!! $expense->vendor->name !!}" />
                @endforeach
            </flux:radio.group>
        @endif
    </form>
    <x-slot name="footer">
        <flux:spacer />
        <flux:button type="submit" form="associated_expenses_form_modal_form" variant="primary">Link Expenses</flux:button>
    </x-slot>
</x-form-modal>
