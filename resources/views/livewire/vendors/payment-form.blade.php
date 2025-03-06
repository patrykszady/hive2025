<div class="max-w-4xl">
    <form wire:submit="{{$view_text['form_submit']}}">
        <div class="grid max-w-xl grid-cols-4 gap-4 xl:relative lg:max-w-5xl sm:px-6">
            <div class="col-span-4 space-y-4 lg:col-span-2 lg:h-32 lg:sticky lg:top-5">
                <flux:card>
                    <flux:heading size="lg">{{$vendor->name}} Payment</flux:heading>
                    <flux:subheading><i>Choose Projects to add for {{$vendor->name}} in this Payment</i></flux:subheading>
                    <flux:separator variant="subtle" />
                    <x-cards.body :class="'space-y-2 my-2'">
                        {{-- FORM --}}
                        @include('livewire.checks._payment_form')
                    </x-cards.body>

                    <flux:separator variant="subtle" />

                    <div class="space-y-2 mt-2">
                        <flux:button class="w-full">Check Total | <b>{{money($this->vendor_check_sum)}}</b></flux:button>
                        <flux:button type="submit" variant="primary" class="w-full">{{$view_text['button_text']}}</flux:button>
                    </div>

                    <flux:error name="check_total_min" />
                </flux:card>

                {{-- INSURANCE --}}
                <livewire:vendor-docs.vendor-docs-card :vendor="$vendor" :view="true" lazy />

                {{-- SELECT PROJECT --}}
                <flux:card>
                    <flux:heading size="lg">Choose Payment Projects</flux:heading>

                    <flux:input.group>
                        <flux:select wire:model.live="project_id" variant="listbox" searchable placeholder="Choose project...">
                            <x-slot name="search">
                                <flux:select.search placeholder="Search..." />
                            </x-slot>

                            @foreach($projects->where('disabled', false) as $project)
                                <flux:select.option value="{{$project->id}}"><div>{{$project->address}} <br> <i class="font-normal">{{$project->project_name}}</i></div></flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:button variant="primary" wire:click="addProject" icon="plus-circle">Add</flux:button>
                    </flux:input.group>

                    <flux:error name="project_id" />
                </flux:card>
            </div>
            <div class="col-span-4 space-y-2 lg:col-span-2">
                {{-- PAYMENT PROJECTS --}}
                @foreach($projects->where('show', true)->sortBy('order') as $project_id => $project)
                    <flux:card class="space-y-2" wire:key="{{$project_id}}">
                        <div class="flex justify-between">
                            <div>
                                <flux:heading size="lg"><a href="{{route('projects.show', $project->id)}}" target="_blank">{{ $project->address }}</a></flux:heading>
                                <flux:subheading>{{ $project->project_name}}</flux:subheading>
                            </div>
                            <flux:button.group>
                                <flux:button size="sm" wire:click="$dispatchTo('bids.bid-create', 'addBids', { vendor: {{$vendor->id}}, project: {{$project->id}} })">Edit Bids</flux:button>
                                <flux:button size="sm" wire:click="removeProject({{$project_id}})">Remove</flux:button>
                            </flux:button.group>
                        </div>


                        <flux:separator variant="subtle" />

                        {{-- VENDOR BIDS --}}
                        <x-forms.one_line label="Total Bids">
                            <flux:input.group>
                                <flux:input.group.prefix>$</flux:input.group.prefix>
                                <flux:input wire:model="projects.{{$project_id}}.vendor_bids_sum" type="number" disabled />
                                <flux:error name="projects.{{$project_id}}.vendor_bids_sum" />
                            </flux:input.group>
                        </x-forms.one_line>

                        {{-- VENDOR PROJECT SUM --}}
                        <x-forms.one_line label="Total Paid">
                            <flux:input.group>
                                <flux:input.group.prefix>$</flux:input.group.prefix>
                                <flux:input wire:model="projects.{{$project_id}}.vendor_expenses_sum" type="number" disabled />
                                <flux:error name="projects.{{$project_id}}.vendor_expenses_sum" />
                            </flux:input.group>
                        </x-forms.one_line>

                        {{-- AMOUNT --}}
                        <x-forms.one_line label="Amount">
                            <flux:input.group>
                                <flux:input.group.prefix>$</flux:input.group.prefix>
                                <flux:input
                                    wire:model.live.debounce.500ms="projects.{{$project_id}}.amount"
                                    type="number"
                                    inputmode="decimal"
                                    step="0.01"
                                    min="0.00"
                                    pattern="[0-9]*"
                                    autofocus
                                />
                            </flux:input.group>
                            <flux:error name="projects.{{$project_id}}.amount" />
                        </x-forms.one_line>

                        <x-forms.one_line label="Balance">
                            <flux:input.group>
                                <flux:input.group.prefix>$</flux:input.group.prefix>
                                <flux:input wire:model="projects.{{$project_id}}.balance" type="number" disabled />
                                <flux:error name="projects.{{$project_id}}.balance" />
                            </flux:input.group>
                        </x-forms.one_line>
                    </flux:card>
                @endforeach

                <livewire:vendor-docs.vendor-doc-create />
                <livewire:bids.bid-create />
            </div>
        </div>
    </form>
</div>

