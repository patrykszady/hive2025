<flux:modal name="bulk_match_form_modal" class="space-y-2">
    <div class="flex justify-between">
        <flux:heading size="lg">{{$view_text['card_title']}}</flux:heading>
    </div>

    <flux:separator variant="subtle" />

    <form wire:submit="{{$view_text['form_submit']}}" class="grid gap-6">
        {{-- VENDOR --}}
        <flux:field>
            {{-- $view_text['form_submit'] === 'edit' ? $new_vendor->name : 'Choose vendor...' --}}
            <flux:select
                label="Vendor"
                wire:model.live="form.vendor_id"
                x-bind:disabled="{{isset($form->match)}}"
                variant="listbox"
                searchable
                placeholder="{{'Choose vendor...'}}"
                >
                <x-slot name="search">
                    <flux:select.search placeholder="Search..." />
                </x-slot>
                @if(isset($form->match))
                    @foreach($this->vendors as $vendor)
                        <flux:option value="{{$vendor->id}}">{{$vendor->name}}</flux:option>
                    @endforeach
                @else
                    {{-- existing_vendors --}}
                    @foreach($this->new_vendors as $vendor)
                        <flux:option value="{{$vendor->id}}">{{$vendor->name}}</flux:option>
                    @endforeach
                @endif
            </flux:select>
        </flux:field>

        <div
            x-data="{ open: @entangle('form.vendor_id') }"
            x-show="open"
            x-transition
            >
            <flux:card class="!p-2 max-w-2xl">
                <div class="flex justify-between">
                    {{-- <a href="{{route('checks.show', $check->id)}}"> --}}
                    <flux:heading>Vendor Transactions</flux:heading>
                    {{-- <flux:subheading>{{$check->check_type . ' ' . $check->check_number . ' ' . $check->date->format('m/d/Y')}}</flux:subheading> --}}

                    {{-- <a href="{{route('checks.show', $check->id)}}" class="text-red-800"><b>{{money($check->amount)}}</b></a> --}}
                </div>
            </flux:card>
        </div>

        {{-- AMOUNT --}}
        <flux:input.group label="Amount" >
            <flux:select
                wire:model.live="form.amount_type"
                class="max-w-fit"
                >
                <flux:option value="ANY" selected>ANY $</flux:option>
                <flux:option value="=">= $</flux:option>
                <flux:option value=">=">>= $</flux:option>
                <flux:option value="<="><= $</flux:option>
                <flux:option value=">">> $</flux:option>
                <flux:option value="<">< $</flux:option>
            </flux:select>

            <flux:input
                wire:model.live="form.amount"
                x-bind:disabled="{{$form->amount_type == 'ANY'}}"
                inputmode="decimal"
                step="0.01"
                placeholder="{{$form->amount_type == 'ANY' ? 'Any Amount' : '99.99'}}"
                />
        </flux:input.group>

        <flux:input wire:model.blur="form.desc" label="Description" placeholder="Description to Find(regex)" />

        <flux:input.group label="Distribution">
            <flux:select wire:model.live="form.distribution_id" variant="listbox"  placeholder="Choose distribution...">
                @foreach($this->distributions as $distribution)
                    <flux:option value="{{$distribution->id}}">{{$distribution->name}}</flux:option>
                @endforeach
            </flux:select>

            <flux:button>Split</flux:button>
        </flux:input.group>

    {{-- FOOTER --}}
    <div class="flex space-x-2 sticky bottom-0">
        <flux:spacer />

        {{-- <flux:button wire:click="remove" variant="danger">Remove</flux:button> --}}
        <flux:button type="submit" variant="primary">{{$view_text['button_text']}}</flux:button>
    </div>
    </form>
</flux:modal>
