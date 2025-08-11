<flux:modal name="vendors_form_modal" class="space-y-2 md:w-96">
    <div class="flex justify-between">
        <flux:heading size="lg">{{$view_text['card_title']}}</flux:heading>
    </div>

    <flux:separator variant="subtle" />

    <form wire:submit="{{$view_text['form_submit']}}" class="grid gap-6">
        {{-- BUSINESS NAME TEXT/SEARCH--}}
        <div x-show="$wire.view_text.form_submit != 'edit' && !$wire.via_vendor">
            <flux:input
                wire:model.live.debounce.500ms="business_name_text"
                label="New Vendor Business Name"
                type="text"
                x-bind:disabled="$wire.via_vendor"
                placeholder="Business Search"
                autofocus
            />
        </div>

        <div
            x-show="$wire.business_name_text && $wire.business_name_text.length >= 3"
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
                        <div class="flex justify-between items-center w-full">
                            <div class="flex-1 min-w-0 mr-2"> <!-- Added min-w-0 to allow truncation -->
                                <flux:heading class="truncate text-zinc-700 hover:text-zinc-900">
                                    <a href="{{route('vendors.show', $vendor_found->id)}}" target="_blank">
                                        {{$vendor_found->name}}
                                    </a>
                                </flux:heading>
                            </div>
                            <div class="flex-shrink-0 flex items-center gap-2 whitespace-nowrap"> <!-- Added flex-shrink-0 and whitespace-nowrap -->
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
                x-show="!$wire.via_vendor"
                x-transition
                class="mt-4"
                >

                {{-- 2025-6-22 change this to $view != ... --}}
                @if($view_text['card_title'] != 'Update Vendor')
                    {{-- Show button when: Has business_name_text --}}
                    <div x-show="$wire.business_name_text" class="space-y-2">
                        {{-- Only show separator with "or" text if there are existing or add vendors --}}
                        @if(optional($existing_vendors)->isNotEmpty() || optional($new_vendors_for_company)->isNotEmpty())
                            <flux:separator text="or" />
                        @endif

                        {{-- Show primary button when no existing vendors --}}
                        @if(optional($existing_vendors)->isEmpty() && optional($new_vendors_for_company)->isEmpty())
                            <flux:button
                                class="w-full font-extrabold"
                                @click="$wire.open_vendor_form = true"
                                variant="primary"
                                color="blue"
                                >
                                Create New Vendor
                            </flux:button>
                        @else
                            {{-- Show default button when there are existing vendors --}}
                            <flux:button
                                class="w-full font-extrabold"
                                @click="$wire.open_vendor_form = true"
                                >
                                Create New Vendor
                            </flux:button>
                        @endif
                    </div>
                @endif
            </div>

            <div
                x-show="$wire.business_name_text"
                >

                {{-- BUSINESS NAME & TYPE --}}
                <div
                    x-show="$wire.open_vendor_form"
                    class="my-4 space-y-4"
                    x-transition
                    >
                    <flux:input
                        {{-- business_name = business_name_text if new vendor, $form->business_name if not--}}
                        wire:model.live="form.business_name"
                        label="Business Name"
                        type="text"
                        {{-- 4-28-23 disabled only on new vendor, not on editVendor --}}
                        {{-- $wire.via_vendor ||  --}}
                        x-bind:disabled="$wire.form.business_name"
                        {{-- 3-21-23 if you need to change business name, undo and reset component --}}
                        {{-- 3-21-23 (side button) radioHint="Change Name" --}}
                        placeholder="Business Name"
                    />

                    <flux:radio.group
                        wire:model.live="form.business_type"
                        label="Business Type"
                        variant="segmented"
                        size="sm"
                        :disabled="$view_text['form_submit'] === 'edit'"
                    >
                        <flux:radio value="Sub" label="Sub" :disabled="$via_vendor || $view_text['form_submit'] === 'edit'" />
                        <flux:radio value="DBA" label="DBA" :disabled="$via_vendor || $view_text['form_submit'] === 'edit'" />
                        <flux:radio value="Retail" label="Retail" :disabled="$via_vendor || $view_text['form_submit'] === 'edit'" />
                        <flux:radio value="1099" label="1099" :disabled="$view_text['form_submit'] === 'edit'" />
                    </flux:radio.group>
                </div>

                {{-- USER --}}
                <div
                    x-data="{ user: @entangle('user'), business_type: @entangle('form.business_type'), via_vendor: @entangle('via_vendor') }"
                    x-show="business_type == 'Sub' || business_type == '1099' || business_type == 'DBA'"
                    x-transition
                    >

                    {{-- USER MODAL --}}
                    <flux:button
                        class="w-full"
                        wire:click="$dispatchTo('users.user-create', 'newMember', { model: 'vendor', model_id: '{{$vendor_add_type}}' })"
                        :disabled="$view_text['form_submit'] === 'edit' || $via_vendor"
                        >

                        {{$user->full_name ?? 'Add Owner'}}
                    </flux:button>

                    @if($vendor_add_type === 'NEW')
                        <livewire:users.user-create />
                    @endif
                </div>

                {{-- existing Vendors found for User  --}}
                {{-- <div
                    x-data="{}business_type: @entangle('form.business_type')}"
                    x-show="(business_type == 'Sub' || business_type == '1099' || business_type == 'DBA')"
                    x-transition
                    >
                    <flux:radio.group label="{{$user->first_name}}'s Existing Vendors" variant="cards" class="flex-col" :indicator="false">
                        @foreach($user->vendors as $user_vendor_found)
                            <flux:radio
                                value="{{$user_vendor_found->id}}"
                                label="{!!$user_vendor_found->business_name!!}"
                                description="{{$user_vendor_found->business_type}}"
                            />
                        @endforeach
                    </flux:radio.group>
                </div> --}}

                {{-- ADDRESS / BUSINESS EMAIL AND PHONE--}}
                <div
                    x-show="$wire.user && $wire.form.business_type != 'Retail'"
                    x-transition
                    class="space-y-4"
                    >
                    
                    {{-- <livewire:address.address-create /> --}}                 
                    {{-- ADDRESS --}}
                    @include('components.forms._address_form', ['address_suggestions' => $address_suggestions])

                    <div x-show="!$wire.via_vendor && ['Sub','DBA'].includes($wire.form.business_type)">
                        <flux:input
                            wire:model.live.debounce.500ms="form.business_email"
                            label="Business Email"
                            type="email"
                            placeholder="Business Email"
                        />

                        <flux:input
                            wire:model.live.debounce.500ms="form.business_phone"
                            label="Business Phone"
                            type="tel"
                            x-data
                            x-mask="(999) 999-9999"
                            placeholder="Business Phone"
                        />
                    </div>
                </div>
            </div>
        </div>

        {{-- FOOTER --}}
        <div 
            class="flex space-x-2 sticky bottom-0 justify-end"             
            x-show="$wire.form.business_type === 'Retail' || ($wire.form.business_type !== 'Retail' && $wire.zip_code)"
            x-transition
            >

            <flux:button
                wire:click="{{$view_text['form_submit']}}"
                type="submit"
                variant="primary"
                >
                {{$view_text['button_text']}}
            </flux:button>
        </div>
    </form>
</flux:modal>
