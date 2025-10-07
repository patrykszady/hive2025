<flux:modal name="payment_form_modal" class="space-y-2 w-full max-w-lg">
    <div class="flex justify-between">
        <flux:heading size="lg">{{$view_text['card_title']}}</flux:heading>
    </div>

    <flux:separator variant="subtle" />

    <form wire:submit="{{$view_text['form_submit']}}" class="grid gap-6">
        {{-- CLIENT --}}
        <flux:select
            label="Client"
            variant="listbox"
            searchable
            placeholder="Choose client..."
            wire:model.live="client_id"
            x-bind:disabled="$wire.from_project"
            >
            @foreach($this->clients as $client)
                <flux:select.option value="{{$client->id}}">{{$client->name}}</flux:select.option>
            @endforeach
        </flux:select>

        <div
            x-data="{ open: @entangle('client_id') }"
            x-show="open"
            x-transition
            class="space-y-2"
            >

            @if($this->hasValidProjects)
                {{-- DATE --}}
                <flux:input
                    wire:model.live="form.date"
                    label="Date"
                    type="date"
                />

                {{-- REF --}}
                <flux:input
                    wire:model.live.debounce.500ms="form.invoice"
                    label="Reference"
                    type="text"
                    placeholder="Check #"
                />

                {{-- NOTES --}}
                <flux:textarea
                    wire:model.live.debounce.500ms="form.note"
                    label="Notes"
                    rows="auto"
                    resize="none"
                    placeholder="Notes"
                />

                <flux:separator variant="subtle" />

                {{-- CLIENT PROJECTS --}}
                @foreach ($projects as $index => $project)
                    <flux:field wire:key="payment-project-{{$project['id']}}">
                        <div class="grid gap-2 grid-cols-2">
                            <div>
                                <flux:label>{{$project['address']}}</flux:label>
                                <flux:description><i>{{$project['project_name']}}</i></flux:description>
                            </div>
                            <div>
                                <flux:input.group>
                                    <flux:input.group.prefix>$</flux:input.group.prefix>
                                    <flux:input wire:model.live="projects.{{$index}}.amount" type="number" inputmode="decimal" step="0.01" placeholder="99.99" />
                                </flux:input.group>
                            </div>
                        </div>
                    </flux:field>

                    @if(!$loop->last)
                        <flux:separator variant="subtle" />
                    @endif
                @endforeach

                <flux:separator variant="subtle" />
                
                <div class="flex justify-between mt-4">
                    <flux:button disabled variant="primary" icon="currency-dollar">
                        {{money($this->client_payment_sum)}}
                    </flux:button>

                    <div class="space-x-2">
                        @if($view_text['form_submit'] === 'update')
                            <flux:button wire:click.prevent="remove" variant="danger" icon="trash">Delete</flux:button>
                        @endif
                        <flux:button type="submit" variant="primary">{{$view_text['button_text']}}</flux:button>
                    </div>
                </div>

                <flux:error name="payment_total_min" />
            @else
                {{-- Show callout when no valid projects --}}
                @if($client_id)
                    <flux:callout icon="information-circle" variant="info">
                       No projects with outstanding balances.
                    </flux:callout>
                @endif
            @endif
        </div>
    </form>
</flux:modal>
