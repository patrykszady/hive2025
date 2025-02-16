<div>
    <div class="grid max-w-3xl grid-cols-1 gap-6 mx-auto mt-8 sm:px-6 lg:max-w-7xl lg:grid-cols-5">
        {{-- PROGRESS --}}
        <div class="space-y-4 lg:col-start-1 lg:col-span-2">
            <x-sections.section cols="1" class="sticky top-5">
                <x-slot name="heading">
                    <h2
                        id="applicant-information-title"
                        class="text-lg font-medium leading-6 text-gray-900"
                        >
                        Hive Registration for {{$user->vendor->business_name}}
                    </h2>
                    <p
                        class="max-w-2xl mt-1 text-sm text-gray-500"
                        >
                        Registration Progress
                    </p>
                </x-slot>

                {{-- MAIN SLOT --}}
                <div class="flow-root">
                    <ul role="list" class="-mb-8">
                        <li>
                        <div class="relative pb-8">
                            <span class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>
                            <div class="relative flex space-x-3">
                                <div>
                                    <span class="flex items-center justify-center w-8 h-8 bg-green-500 rounded-full ring-8 ring-white">
                                        <flux:icon.user variant="solid" class="text-white dark:text-white" />
                                    </span>
                                </div>
                                <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                                    <div>
                                        <p class="text-sm text-gray-500">Owner <a href="#" class="font-medium text-gray-900">{{$user->full_name}}</a> registration</p>
                                    </div>
                                    {{-- <div class="text-sm text-right text-gray-500 whitespace-nowrap">
                                        <time datetime="2020-09-20">Sep 20</time>
                                    </div> --}}
                                </div>
                            </div>
                        </div>
                        </li>

                        <li>
                            <div class="relative pb-8">
                                <span class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>
                                <div class="relative flex space-x-3">
                                <div>
                                    <span class="flex items-center justify-center w-8 h-8 {{$this->registration['vendor_info'] === false && $this->registration['team_members'] === false ? 'bg-indigo-500' : ($this->registration['vendor_info'] === true ? 'bg-green-500' : 'bg-gray-500')}} rounded-full ring-8 ring-white">
                                        <flux:icon.briefcase variant="solid" class="text-white dark:text-white" />
                                    </span>
                                </div>
                                <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                                    <div>
                                    <p class="text-sm text-gray-500">Confirm <a href="#" class="font-medium text-gray-900">{{$user->vendor->name}}, {{$user->vendor->business_type}}</a> details</p>
                                    </div>
                                </div>
                                </div>
                            </div>
                        </li>

                        <li>
                            <div class="relative pb-8">
                                <span class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>
                                <div class="relative flex space-x-3">
                                <div>
                                    {{-- $this->registration['team_members'] === false &&  --}}
                                    <span class="flex items-center justify-center w-8 h-8 {{$this->registration['vendor_info'] === false ? 'bg-gray-500' : ($this->registration['vendor_info'] === true && $this->registration['team_members'] === true ? 'bg-green-500' : 'bg-indigo-500')}} rounded-full ring-8 ring-white">
                                        <flux:icon.user-plus variant="solid" class="text-white dark:text-white" />
                                    </span>
                                </div>
                                <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                                    <div>
                                    <p class="text-sm text-gray-500">Add <a href="#" class="font-medium text-gray-900">Team Members</a></p>
                                    </div>
                                </div>
                                </div>
                            </div>
                        </li>

                        @if(in_array($vendor->business_type, ['Sub', 'DBA']))
                        <li>
                            <div class="relative pb-8">
                                <span class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>
                                <div class="relative flex space-x-3">
                                <div>
                                    <span class="flex items-center justify-center w-8 h-8 {{$this->registration['team_members'] === false ? 'bg-gray-500' : ($this->registration['vendor_info'] === true && $this->registration['team_members'] === true ? 'bg-green-500' : 'bg-indigo-500')}} rounded-full ring-8 ring-white">
                                        <flux:icon.receipt-percent variant="solid" class="text-white dark:text-white" />
                                    </span>
                                </div>
                                <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                                    <div>
                                    <p class="text-sm text-gray-500">Add <a href="#" class="font-medium text-gray-900">Distributions</a></p>
                                    </div>
                                </div>
                                </div>
                            </div>
                        </li>

                        <li>
                            <div class="relative pb-8">
                                <span class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>
                                <div class="relative flex space-x-3">
                                <div>
                                    <span class="flex items-center justify-center w-8 h-8 {{$this->registration['emails_registered'] === false && $this->registration['team_members'] === false ? 'bg-gray-500' : ($this->registration['team_members'] === true && $this->registration['emails_registered'] === true ? 'bg-green-500' : 'bg-indigo-500')}} rounded-full ring-8 ring-white">
                                        <flux:icon.envelope variant="solid" class="text-white dark:text-white" />
                                    </span>
                                </div>
                                <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                                    <div>
                                    <p class="text-sm text-gray-500">Add Receipt <a href="#" class="font-medium text-gray-900">Emails</a></p>
                                    </div>
                                </div>
                                </div>
                            </div>
                        </li>

                        <li>
                        <div class="relative pb-8">
                            <span class="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200" aria-hidden="true"></span>
                            <div class="relative flex space-x-3">
                            <div>
                                <span class="flex items-center justify-center w-8 h-8 {{$this->registration['banks_registered'] === false && $this->registration['emails_registered'] === false ? 'bg-gray-500' : ($this->registration['emails_registered'] === true && $this->registration['banks_registered'] === true ? 'bg-green-500' : 'bg-indigo-500')}} rounded-full ring-8 ring-white">
                                    <flux:icon.credit-card variant="solid" class="text-white dark:text-white" />
                                </span>
                            </div>
                            <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                                <div>
                                <p class="text-sm text-gray-500">Add Company <a href="#" class="font-medium text-gray-900">Transaction</a> Accounts</p>
                                </div>
                            </div>
                            </div>
                        </div>
                        </li>
                        @endif

                        <li>
                        <div class="relative pb-8">
                            <div class="relative flex space-x-3">
                                <div>
                                    <span class="flex items-center justify-center w-8 h-8 {{$this->registration['registered'] === false && ($this->registration['banks_registered'] === false || $this->registration['vendor_info'] === false) ? 'bg-gray-500' : ($this->registration['banks_registered'] === true && $this->registration['registered'] === true ? 'bg-green-500' : 'bg-indigo-500')}} rounded-full ring-8 ring-white">
                                        <flux:icon.check-circle variant="solid" class="text-white dark:text-white" />
                                    </span>
                                </div>
                            <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                                <div>
                                <p class="text-sm text-gray-500"><a href="#" class="font-medium text-gray-900">{{$user->vendor->name}}</a> registration complete</p>
                                </div>
                            </div>
                            </div>
                        </div>
                        </li>
                    </ul>
                </div>
            </x-sections.section>
        </div>
        {{-- REGISTRATION ITEMS --}}
        <div class="space-y-4 lg:col-start-3 lg:col-span-3 xl:col-span-2">
            {{-- VENDOR DETAILS --}}
            <livewire:vendors.vendor-details :vendor="$vendor" :registration="!$registration['vendor_info']">

            <div
                x-data="{ showMembers: @entangle('registration.vendor_info') }"
                x-show="showMembers"
                x-transition
                class="space-y-4"
                >

                {{-- VENDOR TEAM MEMBERS --}}
                <livewire:users.users-index :vendor="$vendor" :view="'vendors.show'" :registration="!$registration['team_members']"/>
                <livewire:users.user-create />
                <livewire:clients.client-create />

                {{-- VENDOR COMPANY EMAILS --}}
                {{-- DISTRIBUTION LIST --}}
                {{-- @if(in_array($vendor->business_type, ['Sub', 'DBA'])) --}}
                    <div
                        x-data="{ showEmails: @entangle('registration.team_members') }"
                        x-show="showEmails"
                        x-transition
                        >

                        <div class="space-y-4">
                            <livewire:distributions.distributions-list :registration="TRUE">
                            <livewire:company-emails.company-emails-index :view="'vendor-registration'">
                        </div>
                    </div>

                    {{-- VENDOR BANKS --}}
                    <div
                        x-data="{ showBanks: @entangle('registration.emails_registered') }"
                        x-show="showBanks"
                        x-transition
                        >
                        <div>
                            <livewire:banks.bank-index :view="'vendor-registration'">
                        </div>
                    </div>
                {{-- @endif --}}
                {{-- VENDOR REGISTRATION FORM --}}
                <div
                    x-data="{ showRegister: @entangle('registration.banks_registered') }"
                    x-show="showRegister"
                    x-transition
                    >
                    <form wire:submit="store" x-show="showRegister">
                        <button
                            x-show="showRegister"
                            type="sbumit"
                            {{-- x-bind:disabled="store" --}}
                            {{-- wire:click="showmodal" --}}
                            {{-- wire:loading.attr="disabled"
                            disabled --}}
                            {{-- wire:target="{{$view_text['form_submit']}}, 'expense', 'createExpenseFromTransaction'" --}}
                            {{-- x-bind:disabled="expense.project_id" --}}
                            class="inline-flex justify-center w-full px-4 py-2 text-lg font-medium text-white bg-indigo-600 border border-transparent rounded-md shadow-sm disabled:opacity-50 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                            >
                            Register {{$user->vendor->business_name}}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- REGISTER OVERLAY --}}
    <div
        {{-- x-data="{open: @entangle('modal_show').live}"
        x-show="open" --}}
        wire:loading
        wire:target="store"
        class="flex justify-center"
        >

        <!-- Overlay -->
        <div
            {{-- x-show="open"  --}}
            x-transition.opacity
            class="fixed inset-0 z-50 bg-black bg-opacity-50"
            >
        </div>

        <!-- Modal -->
        <div
            {{-- x-show="open" --}}
            {{-- style="display: none" --}}
            {{-- x-on:keydown.escape.prevent.stop="open = false" --}}
            role="dialog"
            aria-modal="true"
            {{-- x-id="['modaltitle{{ Str::random() }}']" --}}
            {{-- :aria-labelledby="$id(title)" --}}
            class="fixed inset-0 z-50 overflow-y-auto"
            >

            <!-- Panel -->
            <div
                {{-- x-show="open" --}}
                x-transition
                {{-- x-on:click="open = false" --}}
                class="relative flex items-center justify-center min-h-screen p-4"
                >
                <button type="button" class="inline-flex items-center px-4 py-2 text-sm font-semibold leading-6 text-white transition duration-150 ease-in-out bg-indigo-800 rounded-md shadow hover:bg-indigo-600" disabled="">
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
