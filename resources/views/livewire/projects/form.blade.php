<x-form-modal name="project_form_modal" :title="$view_text['card_title']">
    <form id="project_form_modal_form" wire:submit="{{$view_text['form_submit']}}" class="space-y-4">
        {{-- CLIENT ID --}}
        <div>
            <flux:select label="Client" wire:model.live="form.client_id" variant="listbox" searchable placeholder="Choose client...">
                <x-slot name="search">
                    <flux:select.search placeholder="Search..." />
                </x-slot>

                @foreach($this->clients as $client)
                    <flux:select.option value="{{$client->id}}">{{$client->name}}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div
            x-data="{ client: @entangle('form.client_id') }"
            x-show="client"
            x-transition
            class="my-4 space-y-4"
            >

            {{-- ADDRESS --}}
            <flux:fieldset>
                <flux:legend>Address</flux:legend>

                <flux:radio.group wire:model.live="form.project_existing_address" variant="cards" class="flex-col" :indicator="false">
                    @foreach($client_addresses as $project_address)
                        @if(isset($project_address->id))
                            <flux:radio
                                value="{{$project_address->id}}"
                                label="{{$project_address->address}}"
                                description="{{$project_address->city . ', ' . $project_address->state . ' ' . $project_address->zip_code}}"
                                {{-- @if($loop->first)
                                    checked
                                @endif --}}
                            />
                        @else
                            <flux:radio
                                value="CLIENT_PROJECT"
                                label="{{$project_address['address']}}"
                                description="{{$project_address['city'] . ', ' . $project_address['state'] . ' ' . $project_address['zip_code']}}"
                                {{-- checked --}}
                            />
                        @endif
                    @endforeach

                    <flux:radio
                        value="NEW"
                        label="New Address"
                    />
                </flux:radio.group>
            </flux:fieldset>

            {{-- only show if new address --}}
            <div
                x-data="{ new_address: @entangle('form.project_existing_address') }"
                x-show="new_address == 'NEW'"
                x-transition
                class="my-4 space-y-4"
                >

                {{-- ADDRESS --}}
                @include('components.forms._address_form', ['address_suggestions' => $address_suggestions])
            </div>

            {{-- PROJECT NAME --}}
            <flux:input
                wire:model="form.project_name"
                label="Project Name"
                type="text"
            />
        </div>
    </form>

    <x-slot name="footer">
        @if($view_text['form_submit'] === 'edit')
            @can('forceDelete', $project)
                <flux:button
                    x-on:click="$flux.modal('project_delete_confirm').show()"
                    variant="danger"
                    type="button"
                >
                    Delete
                </flux:button>
            @endcan
        @endif

        <flux:spacer />

        <flux:button type="submit" form="project_form_modal_form" variant="primary">{{$view_text['button_text']}}</flux:button>
    </x-slot>
</x-form-modal>

@if($view_text['form_submit'] === 'edit')
    @can('forceDelete', $project)
        <flux:modal name="project_delete_confirm" class="min-w-[22rem]">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Delete Project?</flux:heading>
                    <flux:text class="mt-2">
                        <p>You're about to permanently delete this project.</p>
                        <p>This action cannot be reversed.</p>
                        <p class="mt-2">Projects can only be deleted if they have no payments, timesheets, hours, tasks, expenses, bids, or distributions.</p>
                    </flux:text>
                </div>
                
                <div class="flex gap-2">
                    <flux:spacer />
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button wire:click="delete" variant="danger">Delete Project</flux:button>
                </div>
            </div>
        </flux:modal>
    @endcan
@endif
