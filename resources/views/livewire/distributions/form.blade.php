<x-form-modal name="distribution_form_modal" :title="$view_text['card_title']">
    <form id="distribution_form_modal_form" wire:submit="{{$view_text['form_submit']}}" class="space-y-4">
        {{-- TEAM MEMBER --}}
        <flux:select label="Team Member" wire:model.live="form.user_id" variant="listbox" placeholder="Select User...">
            @foreach ($form->users as $user)
                <flux:select.option value="{{$user->id}}">{{$user->full_name}}</flux:select.option>
            @endforeach
        </flux:select>

        {{-- DISTRIBUTION NAME --}}
        <flux:input
            wire:model.live="form.name"
            label="Distribution Name"
            type="text"
            placeholder="Office"
        />
    </form>

    <x-slot name="footer">
        <flux:spacer />
        <flux:button type="submit" form="distribution_form_modal_form" variant="primary">{{$view_text['button_text']}}</flux:button>
    </x-slot>
</x-form-modal>
