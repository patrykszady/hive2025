<div>
    <div class="grid max-w-3xl grid-cols-1 gap-6 mx-auto mt-8 sm:px-6 lg:max-w-7xl lg:grid-cols-5">
        {{-- PROGRESS TIMELINE --}}
        <div class="space-y-4 lg:col-start-1 lg:col-span-2">
            <flux:card class="sticky top-5 space-y-4">
                <div>
                    <flux:heading size="lg">Hive Registration for {{$user->vendor->business_name}}</flux:heading>
                    <flux:subheading>Registration Progress</flux:subheading>
                </div>

                <flux:separator variant="subtle" />

                {{-- TIMELINE --}}
                <div class="flow-root">
                    <ul role="list" class="space-y-6">
                        {{-- User registration step (always complete) --}}
                        <x-vendor-registration.step 
                            status="completed"
                            icon="user"
                            label="Owner"
                            description="{{ $user->full_name }}"
                            suffix="registration"
                            :is-last="false"
                        />

                        {{-- Dynamic Steps --}}
                        @foreach($this->getRegistrationSteps() as $index => $step)
                            <x-vendor-registration.step 
                                :status="$this->getStepStatus($step['name'])"
                                :icon="$step['icon']"
                                :label="$step['label']"
                                :description="$step['description']"
                                :suffix="$step['suffix']"
                                :is-last="$index === count($this->getRegistrationSteps()) - 1"
                            />
                        @endforeach
                    </ul>
                </div>
            </flux:card>
        </div>
        
        {{-- REGISTRATION CONTENT --}}
        <div class="space-y-4 lg:col-start-3 lg:col-span-3 xl:col-span-2">
            {{-- VENDOR DETAILS --}}
            <livewire:vendors.vendor-details :vendor="$vendor" :view="$view">

            @if($vendor->business_type !== '1099')
                {{-- TEAM MEMBERS SECTION (non-1099 only) --}}
                <div x-cloak x-show="$wire.registration.vendor_info" x-transition class="space-y-4">
                    <livewire:users.users-index 
                        :vendor="$vendor" 
                        :view="$view"
                    />

                    {{-- DISTRIBUTIONS & COMPANY EMAILS SECTION --}}
                    <div x-cloak x-show="$wire.registration.team_members" x-transition class="space-y-4">
                        {{-- DISTRIBUTIONS - Show when company email exists --}}
                        @if($vendor->company_emails()->exists())
                            <livewire:distributions.distributions-list :view="$view" />
                        @endif
                        
                        <livewire:company-emails.company-emails-index :view="$view" />
                    </div>

                    {{-- BANKS / TRANSACTION ACCOUNTS SECTION --}}
                    <div x-cloak x-show="$wire.registration.emails_registered" x-transition>
                        <livewire:banks.bank-index :view="$view" />
                    </div>

                    {{-- FINAL REGISTRATION (after banks for non-1099) --}}
                    <div x-cloak x-show="$wire.registration.banks_registered" x-transition>
                        <form wire:submit="store">
                            <button
                                type="submit"
                                class="inline-flex justify-center w-full px-4 py-2 text-lg font-medium text-white bg-indigo-600 border border-transparent rounded-md shadow-xs hover:bg-indigo-700 focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                            >
                                Register {{$user->vendor->business_name}}
                            </button>
                        </form>
                    </div>
                </div>
            @else
                {{-- 1099: Only vendor_info -> registered --}}
                <div x-cloak x-show="$wire.registration.vendor_info" x-transition>
                    <form wire:submit="store">
                        <button
                            type="submit"
                            class="inline-flex justify-center w-full px-4 py-2 text-lg font-medium text-white bg-indigo-600 border border-transparent rounded-md shadow-xs hover:bg-indigo-700 focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                        >
                            Register {{$user->vendor->business_name}}
                        </button>
                    </form>
                </div>
            @endif
        </div>
    </div>

    {{-- LOADING OVERLAY --}}
    <div wire:loading wire:target="store" class="flex justify-center">
        <div x-transition.opacity class="fixed inset-0 z-50 bg-black bg-opacity-50"></div>
        <div role="dialog" aria-modal="true" class="fixed inset-0 z-50 overflow-y-auto">
            <div x-transition class="relative flex items-center justify-center min-h-screen p-4">
                <button type="button" class="inline-flex items-center px-4 py-2 text-sm font-semibold leading-6 text-white bg-indigo-800 rounded-md shadow-sm" disabled>
                    <svg class="w-10 h-10 mr-3 -ml-1 text-white animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <div>
                        <h1>Registering {{$user->vendor->business_name}} ...</h1>
                        <span class="font-bold">Do Not Exit!</span>
                    </div>
                </button>
            </div>
        </div>
    </div>
</div>
