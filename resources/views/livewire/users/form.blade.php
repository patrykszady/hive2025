<div>
<x-form-modal name="user_form_modal" :title="$view_text['card_title']">
    <form id="user_form_modal_form" wire:submit="{{$view_text['form_submit']}}" class="space-y-4">
        {{-- PHONE - Use form.cell_phone for client_member edit, user_cell for other flows --}}
        @if($model['type'] === 'client_member')
            <flux:input
                wire:model.live.debounce.500ms="form.cell_phone"
                label="User Cell Phone"
                type="text"
                size="lg"
                maxlength="10"
                inputmode="numeric"
                placeholder="8474304439"
                mask="9999999999"
            />
        @else
            <flux:input
                wire:model.live.debounce.500ms="user_cell"
                x-bind:disabled="$wire.model.type == 'user'"
                label="User Cell Phone"
                type="number"
                size="lg"
                maxlength="10"
                minlength="10"
                inputmode="numeric"
                placeholder="8474304439"
                autofocus
            />
        @endif

        {{-- 1/12/2023 if no user_cell or if updated ONLY --}}
        <div
            x-data="{ user_cell: @entangle('user_cell'), user_form: @entangle('user_form') }"
            x-show="$wire.model.type !== 'client_member' && user_cell.length == 10 && !user_form"
            >
            <flux:button
                wire:click="user_cell_find"
                variant="primary"
                class="w-full"
                >
                Search User
            </flux:button>
            <flux:error name="user_exists_on_model" />
        </div>

        {{-- USER DETAILS --}}
        <div
            x-data="{ open: @entangle('user_form'), user: @entangle('form.user') }"
            x-show="open"
            class="space-y-4"
            >
            <flux:input
                wire:model.live.debounce.500ms="form.first_name"
                x-bind:disabled="$wire.model.type == 'client_member' || ($wire.model.type != 'user' && $wire.form.user_id)"
                label="First Name"
                type="text"
                placeholder="First Name"
            />
            <flux:input
                wire:model.live.debounce.500ms="form.last_name"
                x-bind:disabled="$wire.model.type == 'client_member' || ($wire.model.type != 'user' && $wire.form.user_id)"
                label="Last Name"
                type="text"
                placeholder="Last Name"
            />
            <flux:input
                wire:model.live.debounce.500ms="form.email"
                x-bind:disabled="$wire.model.type != 'user' && $wire.model.type != 'client_member' && $wire.form.user_id"
                label="Email"
                placeholder="Email"
            />

            {{-- save/create User here if not yet saved --}}
            <div
                x-show="!$wire.form.user_id && $wire.model.id != 'NEW'"
                class="my-4 space-y-4"
                >
                <flux:button
                    wire:click="save_user_only"
                    variant="primary"
                    class="w-full"
                    >
                    Save User
                </flux:button>
            </div>

            {{-- CREATE/ATTACH 1099 / SUB Vendor / PAYROLL --}}
            <div
                {{-- model_id: @entangle('model.id') --}}
                x-data="{ userId: @entangle('form.user_id') }"
                {{--  && model_id == 'NEW' --}}
                x-show="$wire.model.type == 'vendor' && $wire.model.id != 'NEW' && userId"
                class="my-4 space-y-4"
                >

                {{-- USER / VENDOR ROLE --}}
                <flux:radio.group wire:model.live="form.role" label="User Role" variant="segmented">
                    <flux:radio value="1" label="Admin" />
                    <flux:radio value="2" label="Team Member" />
                </flux:radio.group>

                <div
                    x-data="{ role: @entangle('form.role')}"
                    x-show="role == 2 ? true : false"
                    x-transition
                    class="my-4 space-y-4"
                    >

                    {{-- VIA VENDOR --}}
                    <flux:radio.group wire:model.live="form.via_vendor" class="flex-col" label="Member Type" variant="cards" :indicator="false">
                        <flux:radio value="PAYROLL" label="Payroll" disabled />

                        @foreach($via_vendors as $via_vendor)
                            <flux:radio value="{{$via_vendor->id}}" label="{!!$via_vendor->business_name!!} {{$via_vendor->business_type}}" description="{{$via_vendor->address . ', ' . $via_vendor->city . ', ' . $via_vendor->state . ' ' . $via_vendor->zip_code}}" />
                        @endforeach
                        
                        <flux:radio value="NEW_VIA" label="New Vendor" />
                    </flux:radio.group>

                    <div
                        x-show="$wire.form.via_vendor == 'NEW_VIA'"
                        x-transition
                        class="my-4 space-y-4"
                        >
                        {{-- create new 1099 / DBA / Sub Vendor for user (team member) being added to Vendor ... --}}
                        <flux:button
                            wire:click="create_via_vendor"
                            variant="primary"
                            class="w-full"
                            >
                            Create Vendor
                        </flux:button>
                    </div>
                </div>

                {{-- USER / VENDOR HOURLY PAY --}}
                <div
                    x-show="($wire.form.via_vendor && $wire.form.via_vendor != 'NEW_VIA') || $wire.form.role == 1"
                    class="my-4 space-y-4"
                    >
                    {{-- USER / VENDOR HOURLY PAY --}}
                    <flux:input
                        wire:model.live.debounce.500ms="form.hourly_rate"
                        label="User Hourly Pay"
                        type="number"
                        size="lg"
                        inputmode="numeric"
                        placeholder="10"
                    />
                </div>
            </div>
        </div>
    </form>

    <x-slot name="footer">
        <flux:spacer />
        <flux:button
            x-show="$wire.user_form && (
                ($wire.model.id == 'NEW' && $wire.form.first_name && $wire.form.last_name && $wire.form.email && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test($wire.form.email)) || 
                ($wire.form.user_id && $wire.model.type == 'client') ||
                ($wire.form.user_id && $wire.model.type == 'vendor' && (($wire.form.via_vendor && $wire.form.via_vendor != 'NEW_VIA') || $wire.form.role == 1)) ||
                ($wire.model.type == 'user') ||
                ($wire.model.type == 'client_member' && $wire.form.email && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test($wire.form.email))
            )"
            type="submit"
            form="user_form_modal_form"
            variant="primary"
            >
            {{$view_text['button_text']}}
        </flux:button>
    </x-slot>
</x-form-modal>
</div>
