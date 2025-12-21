{{--  x-on:close="console.log({close: $event})" x-on:cancel="console.log({cancel: $event})" --}}
<x-form-modal name="lead_form_modal" title="Lead" :dismissible="false">
    <flux:tab.group>
        <flux:tabs>
            <flux:tab name="details">Details</flux:tab>
            <flux:tab name="messages">Mesages</flux:tab>
        </flux:tabs>

        <flux:tab.panel name="details">
            <form id="lead_form_modal_form" wire:submit="{{$view_text['form_submit']}}" class="space-y-4">
                <flux:textarea
                    wire:model.live="lead.message"
                    disabled
                    label="Message"
                    rows="auto"
                    resize="none"
                />

                <flux:input
                    wire:model.live="date"
                    disabled
                    label="Date"
                    type="date"
                />

                <flux:input
                    wire:model.live="lead.origin"
                    disabled
                    label="Origin"
                    type="text"
                />

                <flux:input.group label="User">
                    <flux:input
                        wire:model.live="full_name"
                        x-bind:disabled="$wire.user"
                        type="text"
                        placeholder="Lead User"
                    />

                    <flux:button
                        {{-- wire:click="$dispatchTo('users.user-create', 'newMember', { model: 'client', model_id: 'NEW'})" --}}
                        icon="plus"
                        >
                        {{ is_null($user) ? 'Add User' : 'Add Client' }}
                    </flux:button>
                </flux:input.group>

                <flux:input
                    wire:model.live="lead.phone"
                    label="Phone"
                    x-bind:disabled="$wire.user"
                    type="number"
                    placeholder="Phone"
                />

                <flux:input
                    wire:model.live="lead.email"
                    label="Email"
                    x-bind:disabled="$wire.user"
                    type="text"
                    placeholder="Email"
                />

                <flux:input
                    wire:model.live="lead.address"
                    label="Address"
                    type="text"
                    placeholder="Address"
                />

                {{--  id="new_project_id"  --}}
                <flux:select wire:model.live="lead_status" label="Status" variant="listbox" placeholder="Choose Status...">
                    @include('livewire.leads._lead_status_options')
                </flux:select>

                <flux:textarea
                    wire:model.live="lead.notes"
                    label="Notes"
                    rows="auto"
                    resize="none"
                />
            </form>
        </flux:tab.panel>
        <flux:tab.panel name="messages">
            <form wire:submit="message_reply" class="space-y-4">
                <flux:textarea
                    wire:model.live="lead.message"
                    disabled
                    label="Message"
                    rows="auto"
                    resize="none"
                />

                <flux:input
                    wire:model.live="lead.reply_to_email"
                    disabled
                    label="To: {{$full_name}}"
                    type="text"
                />

                <flux:textarea
                    wire:model.live="reply"
                    label="Reply"
                    rows="8"
                    resize="none"
                />

                {{-- <flux:button type="submit" variant="primary">Message</flux:button> --}}
            </form>
        </flux:tab.panel>
    </flux:tab.group>

    <x-slot name="footer">
        <flux:spacer />
        <flux:button wire:click="remove" variant="danger">Remove</flux:button>
        <flux:button type="submit" form="lead_form_modal_form" variant="primary">{{$view_text['button_text']}}</flux:button>
    </x-slot>

    {{-- <livewire:users.user-create /> --}}
</x-form-modal>
