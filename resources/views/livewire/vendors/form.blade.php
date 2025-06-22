<flux:modal name="vendors_form_modal" class="space-y-2 md:w-96">
    <div class="flex justify-between">
        <flux:heading size="lg">{{$view_text['card_title']}}</flux:heading>
    </div>

    <flux:separator variant="subtle" />

    <form wire:submit="{{$view_text['form_submit']}}" class="space-y-2">
        {{-- BUSINESS NAME TEXT--}}
        {{-- 2025-6-22 change this to $view != ... --}}
        @if($view_text['card_title'] != 'Update Vendor')
            <flux:input
                wire:model.live.debounce.500ms="business_name_text"
                label="New Vendor Business Name"
                type="text"
                x-bind:disabled="$wire.via_vendor"
                placeholder="Business Name"
                autofocus
            />
        @endif
            <div
                x-show="$wire.business_name_text"
                x-transition
                class="space-y-2"
                >

                {{-- Existing Vendors that belong to the logged in Hive Vendor --}}
                @if(optional($existing_vendors)->isNotEmpty())
                    <flux:heading class="!mb-0">Your Existing {{ Str::plural('Vendor', $existing_vendors->count()) }}</flux:heading>
                    <flux:text>
                        {{ $existing_vendors->count() === 1 ? 'This vendor is' : 'These vendors are' }} already connected to your company.
                    </flux:text>

                    @foreach($existing_vendors as $vendor_found)
                        <flux:card class="!border !border-blue-300 !p-1 !pl-2 hover:!border-blue-400 !bg-blue-50/75 hover:!bg-blue-100/75">
                            <div class="flex justify-between items-center">
                                <flux:heading class="truncate text-gray-700 hover:text-gray-900">
                                    <a href="{{route('vendors.show', $vendor_found->id)}}" target="_blank">
                                        {{$vendor_found->name}}
                                    </a>
                                </flux:heading>
                                <div>
                                    <flux:badge color="blue" inset="top bottom">{{$vendor_found->business_type}}</flux:badge>
                                    <flux:button size="sm" class="m-0" href="{{route('vendors.show', $vendor_found->id)}}" target="_blank">
                                        View
                                    </flux:button>
                                </div>
                            </div>
                        </flux:card>
                    @endforeach
                @endif

                {{-- Existing Vendors that DO NOT belong to the logged in Hive Vendor --}}
                @if(optional($new_vendors_for_company)->isNotEmpty())
                    <flux:heading class="!mb-0">Add Existing Vendors</flux:heading>
                    <flux:text>
                        These vendors are available to add but not yet connected to your company.
                        Click <span class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded bg-gray-100 text-gray-700 border border-gray-200">Add</span> to connect them to your company.
                    </flux:text>

                    @foreach($new_vendors_for_company as $new_vendor_found)
                        <flux:card class="!border !border-blue-300 !p-1 !pl-2 hover:!border-blue-400 !bg-blue-50/75 hover:!bg-blue-100/75">
                            <div class="flex justify-between items-center">
                                <flux:heading class="truncate text-gray-700">
                                    {{$new_vendor_found->name}}
                                </flux:heading>

                                <div>
                                    <flux:badge color="blue" inset="top bottom">{{$new_vendor_found->business_type}}</flux:badge>
                                    {{-- wire:click="$dispatchTo('vendors.vendor-create', 'newVendor')" --}}
                                    <flux:button size="sm" class="m-0" wire:click="addVendorToCompany({{$new_vendor_found->id}})">
                                        Add
                                    </flux:button>
                                </div>
                            </div>
                        </flux:card>
                    @endforeach
                @endif

                {{-- Create New Vendor BUTTON --}}
                <div
                    x-data="{open_vendor_form: @entangle('open_vendor_form')}"
                    x-transition
                    class="mt-4"
                    >

                    {{-- 2025-6-22 change this to $view != ... --}}
                    @if($view_text['card_title'] != 'Update Vendor')
                        {{-- Show button when: Has business_name_text AND has searched at least once --}}
                        <div x-show="$wire.business_name_text && $wire.hasSearched" class="space-y-2">
                            {{-- Only show separator with "or" text if there are existing or add vendors --}}
                            @if(optional($existing_vendors)->isNotEmpty() || optional($new_vendors_for_company)->isNotEmpty())
                                <flux:separator text="or" />
                            @endif

                            {{-- Show primary button when no existing vendors --}}
                            @if(optional($existing_vendors)->isEmpty() && optional($new_vendors_for_company)->isEmpty())
                                <flux:button
                                    class="w-full font-extrabold"
                                    wire:click="open_vendor_form = true"
                                    variant="primary"
                                    color="blue"
                                    >
                                    Create New Vendor
                                </flux:button>
                            @else
                                {{-- Show default button when there are existing vendors --}}
                                <flux:button
                                    class="w-full font-extrabold"
                                    wire:click="open_vendor_form = true"
                                    >
                                    Create New Vendor
                                </flux:button>
                            @endif
                        </div>
                    @endif
                </div>

                <div
                    x-data="{business_name_text: @entangle('business_name_text')}"
                    x-show="business_name_text"
                    >

                    {{-- BUSINESS NAME & TYPE --}}
                    <div
                        {{-- business_name = business_name_text --}}
                        x-data="{open_vendor_form: @entangle('open_vendor_form'), user: @entangle('user'), business_type: @entangle('form.business_type'), business_name: @entangle('form.business_name'), via_vendor: @entangle('via_vendor')}"
                        x-show="open_vendor_form"
                        class="my-4 space-y-4"
                        x-transition
                        >
                        <flux:input
                            wire:model.live="form.business_name"
                            label="Business Name"
                            type="text"
                            x-bind:disabled="via_vendor"
                            placeholder="Business Name"
                            x-bind:disabled="business_name"
                            {{-- 4-28-23 disabled only on new vendor, not on editVendor --}}
                            {{-- x-bind:disabled="!vendor_id_disabled || business_type_disabled == '1099'" --}}
                            {{--3-21-23 if you need to change business name, undo and reset component --}}
                            {{-- 3-21-23 (side button) radioHint="Change Name" --}}
                        />

                        <flux:radio.group
                            wire:model.live="form.business_type"
                            label="Business Type"
                            {{-- disabled only on editVendor, not on new vendor --}}
                            x-bind:disabled="via_vendor || user"
                            >
                            <flux:radio x-bind:disabled="via_vendor || user" value="Sub" label="Sub" />
                            <flux:radio x-bind:disabled="via_vendor || user" value="DBA" label="DBA" />
                            <flux:radio x-bind:disabled="via_vendor || user" value="Retail" label="Retail" />
                            <flux:radio x-bind:disabled="via_vendor || user" value="1099" label="1099" />
                        </flux:radio.group>
                    </div>

                    {{-- USER --}}
                    <div
                        x-data="{ user: @entangle('user'), team_member: @entangle('team_member'), business_type: @entangle('form.business_type'), via_vendor: @entangle('via_vendor') }"
                        x-show="business_type == 'Sub' || business_type == '1099' || business_type == 'DBA'"
                        x-transition
                        >

                        {{-- USER MODAL --}}
                        <flux:button
                            class="w-full"
                            wire:click="$dispatchTo('users.user-create', 'newMember', { model: 'vendor', model_id: '{{$vendor_add_type}}' })"
                            x-bind:disabled="team_member != 'index' || via_vendor"
                            >

                            <b>{{isset($user->first_name) ? $user->full_name : 'Add Owner'}}</b>
                        </flux:button>

                        @if($team_member === 'index')
                            <livewire:users.user-create />
                        @endif
                    </div>

                    {{-- existing Vendors found for User  --}}
                    <div
                        x-data="{team_member: @entangle('team_member'), business_type: @entangle('form.business_type')}"
                        x-show="team_member && (business_type == 'Sub' || business_type == '1099' || business_type == 'DBA')"
                        x-transition
                        >

                        @if(!is_null($user_vendors))
                            @if(!$user_vendors->isEmpty())
                                <flux:radio.group label="{{$user->first_name}}'s Existing Vendors" variant="cards" class="flex-col" :indicator="false">
                                    @foreach($user_vendors as $user_vendor_found)
                                        <flux:radio
                                            value="{{$user_vendor_found->id}}"
                                            label="{!!$user_vendor_found->business_name!!}"
                                            description="{{$user_vendor_found->business_type}}"
                                        />
                                    @endforeach
                                </flux:radio.group>
                            @endif
                        @endif
                    </div>

                    {{-- ADDRESS / BUSINESS EMAIL AND PHONE--}}
                    <div
                        x-data="{business_type: @entangle('form.business_type'), address: @entangle('address_isset') }"
                        x-show="(business_type == 'Sub' || business_type == '1099' || business_type == 'DBA') && address"
                        x-transition
                        class="my-4 space-y-4"
                        >
                        {{-- @include('components.forms._address_form', ['model' => 'vendor']) --}}
                        {{-- <livewire:address.address-create /> --}}
                        {{-- ADDRESS --}}
                        @include('components.forms._address_form', ['address_suggestions' => $address_suggestions])

                        <flux:input
                            wire:model.live.debounce.500ms="form.business_email"
                            label="Business Email"
                            type="email"
                            placeholder="Business Email"
                        />

                        <flux:input
                            wire:model.live.debounce.500ms="form.business_phone"
                            label="Business Phone"
                            type="phone"
                            mask="(999) 999-9999"
                            placeholder="Business Phone"
                        />
                    </div>
                </div>
            </div>


        {{-- FOOTER --}}
        <div
            x-data="{business_name_text: @entangle('business_name_text'), business_type: @entangle('form.business_type'), zip_code: @entangle('zip_code')}"
            x-show="business_name_text && business_type"
            x-transition
            >
            <div class="flex space-x-2 sticky bottom-0">
                <flux:spacer />
                <flux:button
                    wire:click="{{$view_text['form_submit']}}"
                    x-bind:disabled="!zip_code && business_type != 'Retail'"
                    :loading="false"
                    type="submit"
                    variant="primary"
                    >
                    {{$view_text['button_text']}}
                </flux:button>
            </div>
        </div>
    </form>
</flux:modal>
