<flux:modal name="associated_expenses_form_modal" class="space-y-2">
    <div class="flex justify-between">
        <flux:heading size="lg">Link Expenses</flux:heading>
    </div>

    <flux:separator variant="subtle" />
    <form wire:submit="save" class="grid gap-6">
        <flux:radio.group wire:model.live="associate_expense" label="Expenses" variant="cards" class="flex-col">
            @foreach($expenses as $expense)
                <flux:radio value="{{$expense->id}}" wire:key="{{$expense->id}}" label="{{money($expense->amount)}}" description="{{$expense->date->format('m/d/Y')}} | {!! $expense->vendor->name !!}" />
            @endforeach
        </flux:radio.group>
        {{-- FOOTER --}}
        <div class="flex space-x-2 sticky bottom-0">
            <flux:spacer />

            <flux:button type="submit" variant="primary">Link Expenses</flux:button>
        </div>
    </form>
</flux:modal>
