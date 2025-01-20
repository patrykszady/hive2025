<flux:modal name="distribution_form_modal" class="space-y-2">
    <div class="flex justify-between">
        <flux:heading size="lg">{{$view_text['card_title']}}</flux:heading>
        @if(isset($expense->id))
            <flux:button wire:navigate.hover href="{{route('expenses.show', $expense->id)}}">Show Expense</flux:button>
        @endif
    </div>

    <flux:separator variant="subtle" />

    <form wire:submit="{{$view_text['form_submit']}}" class="grid gap-6">
        {{-- TEAM MEMBER --}}
        <flux:select label="Team Member" wire:model.live="form.user_id" variant="listbox" placeholder="Select User...">
            @foreach ($form->users as $user)
                <flux:option value="{{$user->id}}">{{$user->full_name}}</flux:option>
            @endforeach
        </flux:select>

        {{-- DISTRIBUTION NAME --}}
        <flux:input
            wire:model.live="form.name"
            label="Distribution Name"
            type="text"
            placeholder="Office"
        />

        {{-- FOOTER --}}
        <div class="flex space-x-2 sticky bottom-0">
            <flux:spacer />

            <flux:button type="submit" variant="primary">{{$view_text['button_text']}}</flux:button>
        </div>
    </form>
</flux:modal>
