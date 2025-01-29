<flux:modal name="vendors_form_modal" class="space-y-2 min-w-2xl">
    <div class="flex justify-between">
        <flux:heading size="lg">{{$view_text['card_title']}}</flux:heading>
    </div>

    <flux:separator variant="subtle" />

    <form wire:submit="{{$view_text['form_submit']}}" class="grid gap-6">
        {{-- BIZ NAME TEXT--}}
        @if($view_text['card_title'] != 'Update Vendor')
            <div
                x-data="{via_vendor: @entangle('via_vendor')}"
                x-transition
                >
                <flux:input
                    wire:model.live.debounce.1000ms="business_name_text"
                    label="New Vendor Business Name"
                    type="text"
                    x-bind:disabled="via_vendor"
                    placeholder="Business Name"
                />
            </div>
        @endif

        @if(!$errors->has('business_name_text'))
            <div
                x-data="{business_name_text: @entangle('business_name_text')}"
                x-show="business_name_text"
                x-transition
                >
                @if(!is_null($existing_vendors))
                    @if(!$existing_vendors->isEmpty())
                        <flux:radio.group label="Existing Vendors" variant="cards" class="flex-col" :indicator="false">
                            @foreach($existing_vendors as $vendor_found)
                                <flux:radio
                                    value="{{$vendor_found->id}}"
                                    label="{!!$vendor_found->business_name!!}"
                                    description="{{$vendor_found->business_type}}"
                                />
                            @endforeach
                        </flux:radio.group>
                    @endif
                @endif

                @if(!is_null($add_vendors_vendor))
                    @if(!$add_vendors_vendor->isEmpty())
                        <flux:label>Add Vendor</flux:label>
                        @foreach($add_vendors_vendor as $vendor_found)
                            <flux:command.items>
                                <flux:command.item
                                    {{-- wire:click="..." --}}
                                    >
                                    <div>
                                        {{$vendor_found->business_name}}
                                        <br>
                                        <i>{{$vendor_found->business_type}}</i>
                                    </div>
                                </flux:command.item>
                            </flux:command.items>
                        @endforeach
                    @endif
                @endif

                <div
                    x-data="{open_vendor_form: @entangle('open_vendor_form'), business_name_text: @entangle('business_name_text')}"
                    {{--  && open_vendor_form == false --}}
                    x-show="business_name_text"
                    class="mt-4"
                    >
                    @if($view_text['card_title'] != 'Update Vendor')
                        <flux:button
                            class="w-full"
                            {{-- Open form below (New bendor form) --}}
                            wire:click="open_vendor_form = true"
                            >

                            <b>Create New Vendor</b>
                        </flux:button>
                    @endif
                </div>

                <div
                    x-data="{business_name_text: @entangle('business_name_text')}"
                    x-show="business_name_text"
                    >

                    {{-- BUSINESS NAME & TYPE --}}
                    <div
                        {{-- business_name = business_name_text --}}
                        x-data="{open_vendor_form: @entangle('open_vendor_form'), vendor: @entangle('vendor.id'), user: @entangle('user'), business_type: @entangle('form.business_type'), business_name: @entangle('form.business_name'), via_vendor: @entangle('via_vendor')}"
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
                            x-bind:disabled="via_vendor || user || vendor"
                            >
                            <flux:radio x-bind:disabled="via_vendor || user || vendor" value="Sub" label="Sub" />
                            <flux:radio x-bind:disabled="via_vendor || user || vendor" value="DBA" label="DBA" />
                            <flux:radio x-bind:disabled="via_vendor || user || vendor" value="Retail" label="Retail" />
                            <flux:radio x-bind:disabled="via_vendor || user || vendor" value="1099" label="1099" />
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

                        {{--  || $via_vendor --}}
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
                        x-data="{business_type: @entangle('form.business_type'), address: @entangle('address') }"
                        x-show="(business_type == 'Sub' || business_type == '1099' || business_type == 'DBA') && address"
                        x-transition
                        class="my-4 space-y-4"
                        >
                        @include('components.forms._address_form', ['model' => 'vendor'])

                        <flux:input
                            wire:model.live.debounce.500ms="form.business_email"
                            label="Business Email"
                            type="email"
                            placeholder="Business Email"
                        />

                        <flux:input
                            wire:model.live.debounce.500ms="form.business_phone"
                            label="Business Phone"
                            type="numeric"
                            placeholder="Business Phone"
                        />
                    </div>
                </div>
            </div>
        @endif

        {{-- FOOTER --}}
        <div
            x-data="{business_name_text: @entangle('business_name_text'), business_type: @entangle('form.business_type'), zip_code: @entangle('form.zip_code')}"
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
