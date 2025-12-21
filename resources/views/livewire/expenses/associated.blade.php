<x-form-modal name="associated_expenses_form_modal" title="Link Expenses">
    <form id="associated_expenses_form_modal_form" wire:submit="save" class="space-y-4">
        <flux:radio.group wire:model.live="associate_expense" label="Expenses" variant="cards" class="flex-col">
            @foreach($expenses as $expense)
                <flux:radio value="{{$expense->id}}" wire:key="{{$expense->id}}" label="{{money($expense->amount)}}" description="{{$expense->date->format('m/d/Y')}} | {!! $expense->vendor->name !!}" />
            @endforeach
        </flux:radio.group>
    </form>

    <x-slot name="footer">
        <flux:spacer />
        <flux:button type="submit" form="associated_expenses_form_modal_form" variant="primary">Link Expenses</flux:button>
    </x-slot>
</x-form-modal>
