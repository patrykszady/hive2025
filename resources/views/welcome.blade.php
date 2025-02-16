@section('title', 'Hive Contractors')
<x-guest-layout>
    <div class="relative overflow-hidden bg-white isolate">
        {{-- <svg class="absolute inset-0 -z-10 h-full w-full stroke-gray-200 [mask-image:radial-gradient(100%_100%_at_top_right,white,transparent)]"
            aria-hidden="true">
            <defs>
                <pattern id="0787a7c5-978c-4f66-83c7-11c213f99cb7" width="200" height="200" x="50%" y="-1"
                    patternUnits="userSpaceOnUse">
                    <path d="M.5 200V.5H200" fill="none" />
                </pattern>
            </defs>
            <rect width="100%" height="100%" stroke-width="0" fill="url(#0787a7c5-978c-4f66-83c7-11c213f99cb7)" />
        </svg> --}}
        <div class="px-6 pt-10 pb-24 mx-auto max-w-7xl sm:pb-32 lg:flex lg:px-8 lg:py-40">
            <div class="max-w-2xl mx-auto lg:mx-0 lg:max-w-xl lg:flex-shrink-0 lg:pt-8">
                <a href="{{route('registration')}}">
                    <img class="h-36" src="{{ asset('favicon.png') }}" alt="{{env('APP_NAME')}}">
                </a>
                <div class="mt-24 sm:mt-32 lg:mt-16">
                    <a href="{{route('registration')}}" class="inline-flex space-x-6">
                        <span
                            class="px-3 py-1 text-sm font-semibold leading-6 text-indigo-600 rounded-full bg-indigo-600/10 ring-1 ring-inset ring-indigo-600/10">
                            Start Today
                        </span>
                        <span class="inline-flex items-center space-x-2 text-sm font-medium leading-6 text-gray-600">
                            <span>
                                See Why
                            </span>
                            <svg class="w-5 h-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z"
                                    clip-rule="evenodd" />
                            </svg>
                        </span>
                    </a>
                </div>
                <h1 class="mt-10 text-4xl font-bold tracking-tight text-gray-900 sm:text-6xl">
                    Join Hive Contractors
                </h1>
                <p class="mt-6 text-lg leading-8 text-gray-600">
                    Welcome to Hive Contractors! Our goal is to maximize your bottom line without interrupting what you do best - moving your construction projects forward.
                    We understand your time is valuable, that's why we seamlessly integrate with your existing workflow, empowering you to focus on your team & homeowners while we take care of the details.
                    <br>
                    <b>Made by Contractors. For Contractors.<b>
                </p>
                <div class="flex items-center mt-10 gap-x-6">
                    <a href="{{route('registration')}}" class="rounded-md bg-indigo-600 px-3.5 py-1.5 text-base font-semibold leading-7 text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                        Create your Hive
                    </a>
                    <a href="{{route('login')}}" class="text-base font-semibold leading-7 text-gray-900">Log in<span aria-hidden="true">→</span></a>
                </div>
            </div>
            <div class="flex max-w-2xl mx-auto mt-16 sm:mt-24 lg:ml-10 lg:mr-0 lg:mt-0 lg:max-w-none lg:flex-none xl:ml-32">
                <div class="flex-none max-w-3xl sm:max-w-5xl lg:max-w-none">
                    <div
                        class="p-2 -m-2 rounded-xl bg-gray-900/5 ring-1 ring-inset ring-gray-900/10 lg:-m-4 lg:rounded-2xl lg:p-4">

                        <img
                            src="{{ asset('hive_expenses_1.png') }}"
                            alt="App screenshot"
                            width="850"
                            class="w-[76rem] rounded-md shadow-2xl ring-1 ring-gray-900/10"
                            >
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- SECTIONS --}}
    <div class="py-24 overflow-hidden bg-gray-100 sm:py-32">
        <div class="px-6 mx-auto max-w-7xl lg:px-8">
            <div
                class="grid max-w-2xl grid-cols-1 mx-auto gap-x-8 gap-y-16 sm:gap-y-20 lg:mx-0 lg:max-w-none lg:grid-cols-2">
                <div class="lg:ml-auto lg:pl-4 lg:pt-4">
                    <div class="lg:max-w-lg">
                        <a href="{{route('registration')}}" class="inline-flex space-x-6">
                            <h2 class="text-base font-semibold leading-7 text-indigo-600">
                                Join Hive Contractors
                            </h2>
                        </a>

                        <p class="mt-2 text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">Projects automated</p>
                        <p class="mt-6 text-lg font-normal leading-8 text-gray-600">
                            {{-- Automate your construction project finances. Your project finances sorted & organized automatically. Understand your project finances with ease. --}}
                            Simplify and streamline your construction project (finances, scheduling, estimates) with Hive Contractors. Let Hive take care of sorting and organizing your project finances automatically, giving you a clear understanding of your financials effortlessly so you can focus on your projects.
                        </p>
                        <dl class="max-w-xl mt-10 space-y-8 text-base leading-7 text-gray-600 lg:max-w-none">
                            <div class="relative pl-9">
                                <dt class="inline font-semibold text-gray-900">
                                    <svg class="absolute w-5 h-5 text-indigo-600 left-1 top-1" viewBox="0 0 20 20"
                                        fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                                    </svg>
                                    Receipts & invoices.
                                </dt>
                                <dd class="inline font-normal">
                                    No more sifting through piles of paperwork. Hive intelligently sorts and links your email and physical receipts with your bank transactions, ensuring all your financial records are in sync.
                                    {{-- Your email and physical receipts sorted and linked with your bank transactions. --}}
                                </dd>
                            </div>
                            <div class="relative pl-9">
                                <dt class="inline font-semibold text-gray-900">
                                    <svg class="absolute w-5 h-5 text-indigo-600 left-1 top-1" viewBox="0 0 20 20"
                                        fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                                    </svg>
                                    Subcontractor bids & payments.
                                </dt>
                                <dd class="inline font-normal">
                                    Stay ahead of the game with seamless interconnectivity between General Contractors and Subcontractors. Hive allows everyone involved in each project to update bids with change orders, keep everyone informed about upcoming payments and know exactly who is owed what.
                                    {{-- Interconnectivity between General Contractors and Subcontractors so everyone involved on each project is able to stay ahead, know what's coming in and who is owed. --}}
                                </dd>
                            </div>
                            <div class="relative pl-9">
                                <dt class="inline font-semibold text-gray-900">
                                    <svg class="absolute w-5 h-5 text-indigo-600 left-1 top-1" viewBox="0 0 20 20"
                                        fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                                    </svg>
                                    Estimates, Invoices, and Change Orders.
                                </dt>
                                <dd class="inline font-normal">
                                    Create estimates, change orders, and invoices in a flash on the go from your phone.
                                </dd>
                            </div>
                            <div class="relative pl-9">
                                <dt class="inline font-semibold text-gray-900">
                                    <svg class="absolute w-5 h-5 text-indigo-600 left-1 top-1" viewBox="0 0 20 20"
                                        fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                                    </svg>
                                    Scheduling and Team Member Managment.
                                </dt>
                                <dd class="inline font-normal">
                                    {{-- Put an end to countless of calls.  --}}
                                </dd>
                            </div>
                            <div class="relative pl-9">
                                <dt class="inline font-semibold text-gray-900">
                                    <svg class="absolute w-5 h-5 text-indigo-600 left-1 top-1" viewBox="0 0 20 20"
                                        fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                                    </svg>
                                    Employee timesheets & payments.
                                </dt>
                                <dd class="inline font-normal">
                                    Track your employee's time dedicated to each project effortlessly. With just a click, you can generate accurate timesheets and pay your employees and subcontractors promptly.
                                </dd>
                            </div>
                            <div class="relative pl-9">
                                <dt class="inline font-semibold text-gray-900">
                                    <svg class="absolute w-5 h-5 text-indigo-600 left-1 top-1" viewBox="0 0 20 20"
                                        fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                                    </svg>
                                    Audits & taxes.
                                </dt>
                                <dd class="inline font-normal">
                                    Say goodbye to the stress of preparing for audits and taxes. Hive automates the process by keeping track of certificates of insurance and payments, bringing accurate audits and taxes to you with just a click.
                                </dd>
                            </div>
                        </dl>
                    </div>
                    <div class="mt-8">
                        <a href="{{route('registration')}}" class="inline-flex rounded-md bg-indigo-600 px-3.5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                            Create you Hive
                        </a>
                    </div>
                </div>
                <div class="flex items-start justify-end lg:order-first">
                    <div class="flex-none max-w-3xl sm:max-w-5xl lg:max-w-none">
                        <div
                            class="p-2 -m-2 rounded-xl bg-gray-900/5 ring-1 ring-inset ring-gray-900/10 lg:-m-4 lg:rounded-2xl lg:p-4">

                            <img
                                src="{{ asset('hive_expenses_2.png') }}"
                                alt="Project details screenshot"
                                width="850"
                                class="w-[76rem] rounded-md shadow-2xl ring-1 ring-gray-900/10"
                                >
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
    {{-- SECTIONS --}}
    {{-- <div class="py-24 bg-white sm:py-32">
        <div class="px-6 mx-auto max-w-7xl lg:px-8">
            <div class="max-w-2xl mx-auto lg:text-center">
                <h2 class="text-base font-semibold leading-7 text-indigo-600">
                    Automate your construction project finances.
                </h2>
                <p class="mt-2 text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">
                    Everything you need to automate your project finances.
                </p>
                <p class="mt-6 text-lg leading-8 text-gray-600">
                    <i>Your project finances sorted & organized automatically.</i>
                    <br>
                    Subcontractor bids & payments. Receipts & invoices.
                    <br>
                    Employee timesheets & payments. Bank transactions & expenses.
                    <br>Audits & taxes. Client & employee reimburesements.
                </p>
                <h2 class="text-base font-semibold leading-7 text-indigo-600">
                    All automated specifically for your construction business.
                </h2>
            </div>

            <div class="max-w-2xl m-10 mx-auto lg:text-center gap-x-6">
                <a href="{{route('registration')}}" class="px-4 py-3 text-base font-semibold leading-7 text-white bg-indigo-600 rounded-md shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                    Create your Hive
                </a>
            </div>
            <div class="max-w-2xl mx-auto mt-16 sm:mt-20 lg:mt-24 lg:max-w-4xl">
                <dl class="grid max-w-xl grid-cols-1 gap-x-8 gap-y-10 lg:max-w-none lg:grid-cols-2 lg:gap-y-16">
                    <div class="relative pl-16">
                        <dt class="text-base font-semibold leading-7 text-gray-900">
                            <div
                                class="absolute top-0 left-0 flex items-center justify-center w-10 h-10 bg-indigo-600 rounded-lg">
                                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                    stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z" />
                                </svg>
                            </div>
                            Receipts Automated
                        </dt>
                        <dd class="mt-2 text-base leading-7 text-gray-600">
                            Your email and uploaded receipts sorted and linked with your bank transactions.
                        </dd>
                    </div>
                    <div class="relative pl-16">
                        <dt class="text-base font-semibold leading-7 text-gray-900">
                            <div
                                class="absolute top-0 left-0 flex items-center justify-center w-10 h-10 bg-indigo-600 rounded-lg">
                                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                    stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                </svg>
                            </div>
                            Your transactions matched with expenses.
                        </dt>
                        <dd class="mt-2 text-base leading-7 text-gray-600">
                            Your transactions on business accounts are automatically linked with expenses and catagorized.
                        </dd>
                    </div>
                    <div class="relative pl-16">
                        <dt class="text-base font-semibold leading-7 text-gray-900">
                            <div
                                class="absolute top-0 left-0 flex items-center justify-center w-10 h-10 bg-indigo-600 rounded-lg">
                                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                    stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                                </svg>
                            </div>
                            Your employees track thier Hours and Timesheets.
                        </dt>
                        <dd class="mt-2 text-base leading-7 text-gray-600">
                            Pay your employees with a click.
                        </dd>
                    </div>
                    <div class="relative pl-16">
                        <dt class="text-base font-semibold leading-7 text-gray-900">
                            <div
                                class="absolute top-0 left-0 flex items-center justify-center w-10 h-10 bg-indigo-600 rounded-lg">
                                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                    stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M7.864 4.243A7.5 7.5 0 0119.5 10.5c0 2.92-.556 5.709-1.568 8.268M5.742 6.364A7.465 7.465 0 004.5 10.5a7.464 7.464 0 01-1.15 3.993m1.989 3.559A11.209 11.209 0 008.25 10.5a3.75 3.75 0 117.5 0c0 .527-.021 1.049-.064 1.565M12 10.5a14.94 14.94 0 01-3.6 9.75m6.633-4.596a18.666 18.666 0 01-2.485 5.33" />
                                </svg>
                            </div>
                            Interconectivyy between you and you GCs and Subcontractors So everyone involved on each project is able to stay ahead, know what's coming in and who is owed.
                        </dt>
                        <dd class="mt-2 text-base leading-7 text-gray-600">So everyone involved on each project is able to stay ahead, know what's coming in and who is owed.</dd>
                    </div>
                </dl>
            </div>
        </div>
    </div> --}}

    {{-- CALL TO ACTION DARK --}}
    <div class="bg-indigo-700">
        <div class="px-6 py-24 sm:px-6 sm:py-32 lg:px-8">
            <div class="max-w-2xl mx-auto text-center">
                <h2 class="text-3xl font-bold tracking-tight text-white sm:text-4xl">
                    Focus on your projects.
                    <br>
                    Leave bookkeeping to us.
                    {{-- We will take care of your bookkeeping. --}}
                </h2>
                <p class="max-w-xl mx-auto mt-6 text-lg font-normal leading-8 text-indigo-200">
                    Managing projects is hard enough. Let us take care of the details.
                </p>
                {{-- <p class="max-w-xl mx-auto mt-6 text-lg leading-8 text-indigo-200">
                    Managing projects is hard enough. Let us take care of the details.
                </p> --}}
                <div class="flex items-center justify-center mt-10 gap-x-6">
                    <a href="{{route('registration')}}"
                        class="rounded-md bg-white px-3.5 py-2.5 text-sm font-semibold text-indigo-600 shadow-sm hover:bg-indigo-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                        Create your Hive
                    </a>
                    <a href="#" class="text-sm font-semibold leading-6 text-white">
                        Learn more
                        <span
                            aria-hidden="true">→
                        </span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-guest-layout>

