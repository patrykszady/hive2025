<x-modals.modal>
    <form wire:submit="save">
        {{-- HEADER --}}
        <x-cards.heading>
            <x-slot name="left">
                <h1>Finalize This Estimate</h1>
            </x-slot>
        </x-cards.heading>

        {{-- Estimate Sections: --}}
        <hr class="border-indigo-700 border-b-1">
        <x-cards.heading>
            <x-slot name="left">
                <h1>Estimate Sections:</h1>
                <span><i>Select which Bid each Section belongs to.</i></span>
            </x-slot>
        </x-cards.heading>
        <x-cards.body>
            {{-- <x-cards class="col-span-4 lg:col-span-2">
                <x-cards.heading>
                    <x-slot name="left">
                        <h1><b>{{ $user->first_name }}</b>'s Timesheets</h1>
                    </x-slot>
                </x-cards.heading> --}}

                <div class="px-4 sm:px-6 lg:px-8">
                    {{-- <div class="sm:flex sm:items-center">
                        <div class="sm:flex-auto">
                            <h1 class="text-base font-semibold leading-6 text-gray-900">Users</h1>
                            <p class="mt-2 text-sm text-gray-700">A list of all the users in your account including their name, title,
                                email and role.</p>
                        </div>
                        <div class="mt-4 sm:ml-16 sm:mt-0 sm:flex-none">
                            <button type="button"
                                class="block px-3 py-2 text-sm font-semibold text-center text-white bg-indigo-600 rounded-md shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">Add
                                user</button>
                        </div>
                    </div> --}}

                    <table class="min-w-full divide-y divide-gray-300">
                        <thead>
                            <tr>
                                <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-md font-semibold text-gray-900 sm:pl-0">Section Name
                                </th>
                                {{-- <th scope="col"
                                    class="hidden px-3 py-3.5 text-left text-sm font-semibold text-gray-900 lg:table-cell">Title
                                </th> --}}
                                <th scope="col"
                                    class="hidden px-3 py-3.5 text-left text-md font-semibold text-gray-900 sm:table-cell">Bid
                                </th>
                                <th scope="col" class="px-3 py-3.5 text-md font-semibold text-gray-900 text-right">Amount</th>
                                {{-- <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-0">
                                    <span class="sr-only">Edit</span>
                                </th> --}}
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200"
                            wire:target="newEstimateBid"
                            wire:loading.attr="disabled"
                            wire:loading.class="opacity-50 text-opacity-40"
                            >

                            @foreach($sections as $index => $section)
                                <tr>
                                    <td
                                        class="w-full py-4 pl-4 pr-3 font-medium text-gray-900 text-md max-w-0 sm:w-auto sm:max-w-none sm:pl-0">
                                        {{$section->name}}
                                        <dl class="font-normal lg:hidden">
                                            {{-- <dt class="sr-only">Title</dt>
                                            <dd class="mt-1 text-gray-700 truncate">Front-end Developer</dd> --}}
                                            <dt class="sr-only sm:hidden">Bid</dt>
                                            <dd class="mt-1 text-gray-500 truncate sm:hidden">
                                                @include('livewire.estimates.accept_bids_dropdown')
                                            </dd>
                                        </dl>
                                    </td>
                                    {{-- <td class="hidden px-3 py-4 text-sm text-gray-500 lg:table-cell">Front-end Developer</td> --}}
                                    <td class="hidden px-3 py-4 ml-2 text-gray-500 text-md sm:table-cell">
                                        @include('livewire.estimates.accept_bids_dropdown')
                                    </td>
                                    <td class="px-3 py-4 text-right text-gray-500 text-md">{{money($section->total)}}</td>
                                    {{-- <td class="py-4 pl-3 pr-4 text-sm font-medium text-right sm:pr-0">
                                        <a href="#" class="text-indigo-600 hover:text-indigo-900">Edit<span class="sr-only">, Lindsay
                                                Walton</span></a>
                                    </td> --}}
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            {{-- </x-cards> --}}
        </x-cards.body>

        {{-- Reimbursements: --}}
        <hr class="border-indigo-700 border-b-1">
        <x-cards.heading>
            <x-slot name="left">
                <h1>Reimbursements:</h1>
                <span><i>Include Project reimbursements in Estimate</i></span>
            </x-slot>
        </x-cards.heading>
        <x-cards.body>
            <div class="px-4 sm:px-6 lg:px-8">
                <table class="min-w-full divide-y divide-gray-300">
                    <tbody class="bg-white divide-y divide-gray-200"
                        wire:target="newEstimateBid"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-50 text-opacity-40"
                        >

                        <tr>
                            <td
                                class="w-full py-4 pl-4 pr-3 font-medium text-gray-900 text-md max-w-0 sm:w-auto sm:max-w-none sm:pl-0"
                                >
                                Reimbursement
                                <dl class="font-normal lg:hidden">
                                    {{-- <dt class="sr-only">Title</dt>
                                    <dd class="mt-1 text-gray-700 truncate">Front-end Developer</dd> --}}
                                    <dt class="sr-only sm:hidden">Reimbursement checkbox</dt>
                                    <dd class="mt-1 text-gray-500 truncate sm:hidden">
                                        @include('livewire.estimates.accept_bids_reimbursement')
                                    </dd>
                                </dl>
                            </td>
                            {{-- <td class="hidden px-3 py-4 text-sm text-gray-500 lg:table-cell">Front-end Developer</td> --}}
                            <td class="hidden px-3 py-4 text-gray-500 text-md sm:table-cell">
                                @include('livewire.estimates.accept_bids_reimbursement')
                            </td>
                            <td class="px-3 py-4 text-right text-gray-500 text-md">{{money($project->finances['reimbursments'])}}</td>
                            {{-- <td class="py-4 pl-3 pr-4 text-sm font-medium text-right sm:pr-0">
                                <a href="#" class="text-indigo-600 hover:text-indigo-900">Edit<span class="sr-only">, Lindsay
                                        Walton</span></a>
                            </td> --}}
                        </tr>
                    </tbody>
                </table>
            </div>
        </x-cards.body>

        {{-- Payment Schedule: --}}
        {{-- List your project progressive payments. --}}
        <hr class="border-indigo-700 border-b-1">
        <x-cards.heading>
            <x-slot name="left">
                <h1>Project Payments:</h1>
                <span><i>List your project progressive payments for the Original Bid of this Estimate.</i></span>
            </x-slot>

            <x-slot name="right">
                <h1>{{ money($this->sections->where('bid_index', 0)->sum('total')) }}</h1>
                {{-- <span><i>List your project progressive payments.</i></span> --}}
            </x-slot>
        </x-cards.heading>
        <x-cards.body>
            {{-- <x-cards class="col-span-4 lg:col-span-2">
                <x-cards.heading>
                    <x-slot name="left">
                        <h1><b>{{ $user->first_name }}</b>'s Timesheets</h1>
                    </x-slot>
                </x-cards.heading> --}}

                <div class="px-4 sm:px-6 lg:px-8">
                    <table class="min-w-full divide-y divide-gray-300">
                        <thead>
                            <tr>
                                <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-md font-semibold text-gray-900 sm:pl-0">Payment
                                </th>
                                {{-- <th scope="col"
                                    class="hidden px-3 py-3.5 text-left text-sm font-semibold text-gray-900 lg:table-cell">Title
                                </th> --}}
                                <th scope="col"
                                    class="hidden px-3 py-3.5 text-left text-md font-semibold text-gray-900 sm:table-cell">Payment Description
                                </th>
                                <th scope="col" class="px-3 py-3.5 text-md font-semibold text-gray-900 text-right">Amount</th>
                                {{-- <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-0">
                                    <span class="sr-only">Edit</span>
                                </th> --}}
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200"
                            wire:target="newEstimateBid"
                            wire:loading.attr="disabled"
                            wire:loading.class="opacity-50 text-opacity-40"
                            >

                            @foreach($payments as $index => $payment)
                                <tr>
                                    <td
                                        class="w-full py-4 pl-4 pr-3 font-medium text-gray-900 text-md max-w-0 sm:w-auto sm:max-w-none sm:pl-0"
                                        >
                                        Payment {{$index + 1}}
                                        @if($payments->count() > 1)
                                            <button
                                                wire:click="removePayment({{$index}})"
                                                type="button"
                                                class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-red-300 rounded-md shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                                                >
                                                Remove
                                            </button>
                                        @endif
                                        <dl class="font-normal lg:hidden">
                                            {{-- <dt class="sr-only sm:hidden">Bid</dt> --}}
                                            <dd class="mt-1 text-gray-900 truncate sm:hidden">
                                                @include('livewire.estimates.accept_bids_payments')
                                            </dd>
                                        </dl>
                                    </td>
                                    {{-- <td class="hidden px-3 py-4 text-sm text-gray-500 lg:table-cell">Front-end Developer</td> --}}
                                    <td class="hidden px-3 py-4 text-gray-900 text-md sm:table-cell">
                                        @include('livewire.estimates.accept_bids_payments')
                                    </td>
                                    <td class="px-3 py-4 text-right text-gray-900 text-md">
                                        <input
                                            type="text"
                                            wire:model.live="payments.{{$index}}.amount"
                                            name="payments.{{$index}}.amount"
                                            id="payments.{{$index}}.amount"
                                            autocomplete="payments.{{$index}}.amount"
                                            placeholder="1000"
                                            class="flex-1 block w-full min-w-0 placeholder-gray-200 border-gray-300 rounded-md sm:text-sm hover:bg-gray-50 focus:ring-indigo-500 focus:border-indigo-500"
                                        >
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            {{-- </x-cards> --}}
        </x-cards.body>
        <hr>
        <x-cards.heading>
            <x-slot name="left">
                <button
                    wire:click="addPayment"
                    type="button"
                    class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                    >
                    Add Payment
                </button>
            </x-slot>
            <x-slot name="right">
                <h1>Remaining: {{ money($this->payments_remaining) }}</h1>
                <x-forms.error errorName="payments_remaining_error" />
            </x-slot>
        </x-cards.heading>

        <hr class="border-indigo-700 border-b-1">
        <x-cards.heading>
            <x-slot name="left">
                <h1>Estimate Duration:</h1>
                <span><i>Start and End date to include in contract.</i></span>
            </x-slot>
        </x-cards.heading>
        <x-cards.body>
            <div class="px-4 sm:px-6 lg:px-8">
                <table class="min-w-full divide-y divide-gray-300">
                    <tbody class="bg-white divide-y divide-gray-200">
                        <tr>
                            <td
                                class="w-full py-4 pl-4 pr-3 font-medium text-gray-900 text-md max-w-0 sm:w-auto sm:max-w-none sm:pl-0"
                                >
                                Start Date
                                <dl class="font-normal lg:hidden">
                                    <dt class="sr-only sm:hidden">Bid</dt>
                                    <dd class="mt-1 text-gray-500 truncate sm:hidden">
                                        <input
                                            type="date"
                                            wire:model.live="start_date"
                                            name="start_date"
                                            id="start_date"
                                            autocomplete="start_date"
                                            placeholder="1000"
                                            class="flex-1 block w-full min-w-0 placeholder-gray-200 border-gray-300 rounded-md sm:text-sm hover:bg-gray-50 focus:ring-indigo-500 focus:border-indigo-500"
                                        >
                                    </dd>
                                </dl>
                            </td>
                            <td class="hidden px-3 py-4 ml-2 text-gray-500 text-md sm:table-cell">
                                <input
                                    type="date"
                                    wire:model.live="start_date"
                                    name="start_date"
                                    id="start_date"
                                    autocomplete="start_date"
                                    placeholder="1000"
                                    class="flex-1 block w-full min-w-0 placeholder-gray-200 border-gray-300 rounded-md sm:text-sm hover:bg-gray-50 focus:ring-indigo-500 focus:border-indigo-500"
                                >
                            </td>
                        </tr>
                        <tr>
                            <td
                                class="w-full py-4 pl-4 pr-3 font-medium text-gray-900 text-md max-w-0 sm:w-auto sm:max-w-none sm:pl-0"
                                >
                                End Date
                                <dl class="font-normal lg:hidden">
                                    <dt class="sr-only sm:hidden">Bid</dt>
                                    <dd class="mt-1 text-gray-500 truncate sm:hidden">
                                        <input
                                            type="date"
                                            wire:model.live="end_date"
                                            name="end_date"
                                            id="end_date"
                                            autocomplete="end_date"
                                            placeholder="1000"
                                            class="flex-1 block w-full min-w-0 placeholder-gray-200 border-gray-300 rounded-md sm:text-sm hover:bg-gray-50 focus:ring-indigo-500 focus:border-indigo-500"
                                        >
                                    </dd>
                                </dl>
                            </td>
                            <td class="hidden px-3 py-4 ml-2 text-gray-500 text-md sm:table-cell">
                                <input
                                    type="date"
                                    wire:model.live="end_date"
                                    name="end_date"
                                    id="end_date"
                                    autocomplete="end_date"
                                    placeholder="1000"
                                    class="flex-1 block w-full min-w-0 placeholder-gray-200 border-gray-300 rounded-md sm:text-sm hover:bg-gray-50 focus:ring-indigo-500 focus:border-indigo-500"
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </x-cards.body>

        <x-cards.footer>
            <button
                type="button"
                x-on:click="open = false"
                class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                >
                Cancel
            </button>
            <button
                type="submit"
                class="inline-flex items-center px-4 py-2 ml-3 text-sm font-medium text-white bg-indigo-600 border border-transparent rounded-md shadow-sm disabled:opacity-50 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                >
                Finalize
            </button>
        </x-cards.footer>
    </form>
</x-modals.modal>
