<x-form-modal name="vendors_form_modal" :title="$view_text['card_title']">
    <div x-data="{ 
        activeTab: 'details',
        business_type: @entangle('form.business_type'), 
        via_vendor: @entangle('via_vendor'),
        open_vendor_form: @entangle('open_vendor_form')
    }">
        <!-- Only show tabs when editing an existing vendor -->
        <div class="border-b border-gray-200 mb-4" x-show="$wire.view_text['form_submit'] === 'edit'">
            <nav class="-mb-px flex space-x-8">
                <button
                    @click="activeTab = 'details'"
                    :class="activeTab === 'details' ? 'border-accent text-accent' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="py-2 px-1 border-b-2 font-medium text-sm"
                >
                    Details
                </button>
                @if(!$isRegistration)
                <button
                    @click="activeTab = 'expenses'"
                    :class="activeTab === 'expenses' ? 'border-accent text-accent' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="py-2 px-1 border-b-2 font-medium text-sm"
                >
                    Categories
                </button>
                @endif
            </nav>
        </div>
        
        <!-- Details Tab Content -->
        <div x-show="activeTab === 'details'">
            <form id="vendors_form_modal_form" wire:submit="{{$view_text['form_submit']}}" class="space-y-4">
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
                    class="space-y-4"
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
                                        wire:click="openVendorForm"
                                        variant="primary"
                                        color="blue"
                                        >
                                        Create New Vendor
                                    </flux:button>
                                @else
                                    {{-- Show default button when there are existing vendors --}}
                                    <flux:button
                                        class="w-full font-extrabold"
                                        wire:click="openVendorForm"
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
                                wire:model.live="form.business_name"
                                label="Business Name"
                                type="text"
                                x-bind:disabled="$wire.form.business_name"
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
                            x-show="['Sub','DBA','1099'].includes(business_type)"
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

                        {{-- ADDRESS / BUSINESS EMAIL AND PHONE --}}
                        <div
                            x-show="business_type !== 'Retail' && ($wire.view_text['form_submit'] === 'edit' || $wire.has_user)"
                            x-transition
                            class="space-y-4"
                            >
                            
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
            </form>
        </div>
        
        <!-- Expenses Tab Content -->
        @if(!$isRegistration)
        <div x-show="activeTab === 'expenses'" class="space-y-4">
            <!-- Category Assignment Form -->
            <flux:card class="bg-white border border-gray-200">
                <div class="space-y-4">
                    <flux:heading size="sm">Update Expense Categories</flux:heading>
                    <flux:text class="text-sm text-gray-600">
                        Assigning a default category will update this vendor's primary category and all associated expenses.
                    </flux:text>
                    
                    <form wire:submit.prevent="updateVendorCategory" class="space-y-4">
                        <flux:select 
                            wire:model="selectedCategoryId" 
                            label="Default Category" 
                            searchable
                            placeholder="Select a category..."
                            required
                        >
                            <flux:select.option value="">No Category</flux:select.option>
                            @foreach($this->availableCategories as $category)
                                <flux:select.option value="{{ $category->id }}">
                                    {{ $category->friendly_primary }} - {{ $category->friendly_detailed }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                        
                        <div class="flex justify-end">
                            <flux:button 
                                type="submit" 
                                variant="primary" 
                                size="sm"
                                wire:loading.attr="disabled"
                            >
                                <span wire:loading.remove wire:target="updateVendorCategory">
                                    Update All Expenses
                                </span>
                                <span wire:loading wire:target="updateVendorCategory">
                                    Updating...
                                </span>
                            </flux:button>
                        </div>
                    </form>
                </div>
            </flux:card>
            
            <!-- Total Expenses -->
            <div class="mb-4">
                <flux:card class="bg-blue-50">
                    <div class="flex justify-between items-center">
                        <flux:heading size="sm">Total Expenses</flux:heading>
                        <flux:heading size="lg" class="text-blue-700">{{ money($this->totalExpenses) }}</flux:heading>
                    </div>
                </flux:card>
            </div>
            
            <!-- Categories Cards -->
            <div class="space-y-2">
                @forelse($this->vendorExpensesByCategory as $categoryName => $category)
                    <x-details.card 
                        title="{{ $category['name'] }}" 
                        :expanded="false" 
                        :details_text="false"
                    >
                        <x-slot:header_buttons>
                            <flux:badge color="gray">{{ $category['count'] }}</flux:badge>
                        </x-slot:header_buttons>
                        
                        <x-slot:details>
                            <div class="space-y-2">
                                @foreach($category['subcategories'] as $subcategory)
                                    <x-details.card 
                                        title="{{ $subcategory['name'] }}" 
                                        :expanded="false" 
                                        :details_text="false" 
                                        class="!shadow-none !border-none !bg-transparent"
                                    >
                                        <x-slot:header_buttons>
                                            <flux:badge color="gray" size="sm">{{ $subcategory['count'] }}</flux:badge>
                                        </x-slot:header_buttons>
                                        
                                        <x-slot:details>
                                            @foreach($subcategory['expenses'] as $expense)
                                                <x-details.row 
                                                    title="{{ $expense->date->format('M j, Y') }}"
                                                    :content="money($expense->amount)"
                                                    :right-align="true"
                                                />
                                            @endforeach
                                        </x-slot:details>
                                        
                                        <x-slot:footer>
                                            <flux:button
                                                size="sm"
                                                disabled
                                            >
                                                {{ money($subcategory['total']) }}
                                            </flux:button>
                                        </x-slot:footer>
                                    </x-details.card>
                                @endforeach
                            </div>
                        </x-slot:details>
                        
                        <x-slot:footer>
                            <flux:button
                                size="sm"
                                variant="primary"
                                disabled
                            >
                                {{ money($category['total']) }}
                            </flux:button>
                        </x-slot:footer>
                    </x-details.card>
                @empty
                    <flux:card>
                        <div class="text-center p-4 text-gray-500">
                            <div class="text-2xl mb-2">📊</div>
                            <div class="font-medium">No expenses found</div>
                            <div class="text-sm">This vendor has no recorded expenses.</div>
                        </div>
                    </flux:card>
                @endforelse
            </div>
        </div>
        @endif
    </div>

    <x-slot name="footer">
        <flux:spacer />
        <flux:button
            x-data="{ 
                businessType: @entangle('form.business_type'),
                zipCode: @entangle('zip_code')
            }"
            x-show="businessType === 'Retail' || (businessType !== 'Retail' && zipCode)"
            type="submit"
            form="vendors_form_modal_form"
            variant="primary"
            >
            {{$view_text['button_text']}}
        </flux:button>
    </x-slot>
</x-form-modal>
