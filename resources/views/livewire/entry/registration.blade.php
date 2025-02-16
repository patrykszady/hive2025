<div class="flex flex-col justify-center min-h-full min-h-screen py-12 bg-gray-100 sm:px-6 lg:px-8" x-cloak>
    <div class="sm:mx-auto sm:w-full sm:max-w-md">
        <a href="{{route('welcome')}}"><img class="w-auto mx-auto h-36" src="{{ asset('favicon.png') }}" alt="{{env('APP_NAME')}}"></a>
        <h2 class="mt-6 text-3xl font-bold tracking-tight text-center text-gray-900">Register your Hive</h2>
        <p class="mt-2 text-sm text-center text-gray-600">
            First,
            <a href="#" class="font-medium text-indigo-600 hover:text-indigo-500">just some basic personal info.</a>
        </p>
    </div>

    <div class="mx-4 mt-8 sm:mx-auto sm:w-full sm:max-w-md">
        <div class="px-4 py-8 bg-white shadow sm:rounded-lg sm:px-10">
                {{-- CELL PHONE --}}
                <div>
                    <label for="user_cell" class="block text-sm font-medium text-gray-700">Cell Phone Number</label>
                    <div class="mt-1">
                        <input
                            wire:model.live.debounce.250ms="user_cell"
                            id="user_cell"
                            name="user_cell"
                            type="tel"
                            {{-- autocomplete="user_cell_required1432" --}}
                            inputmode="numeric"
                            placeholder="8470004000"
                            required
                            x-bind:disabled="{{isset($user) ? isset($user['id']) || isset($user['cell_phone']) && !$errors->has('user_cell') ? true : false : false}}"
                            class="block w-full px-3 py-2 placeholder-gray-400 border border-gray-300 rounded-md shadow-sm appearance-none disabled:opacity-50 focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 sm:text-sm"
                        >
                    </div>

                    <x-forms.error errorName="user_cell" />
                    {{-- <x-forms.error errorName="invalid_user_cell" /> --}}
                </div>

                <div
                    x-data="{ validate_number: @entangle('validate_number'), show_email: @entangle('show_email') }"
                    x-show="!validate_number && !show_email"
                    x-transition
                    class="my-4 space-y-4"
                    >
                    <div>
                        {{-- only enable when user_cell passes validation.. --}}
                        <button
                            wire:click="user_cell_confirm"
                            type="button"
                            class="flex justify-center w-full px-4 py-2 text-sm text-white bg-indigo-600 border border-transparent rounded-md shadow-sm font-small hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            >
                            Confirm Number
                        </button>
                    </div>
                </div>

                {{-- CELL VERIFICATION CODE --}}
                <div
                    x-data="{ validate_number: @entangle('validate_number'), show_email: @entangle('show_email') }"
                    x-show="validate_number && !show_email"
                    x-transition
                    class="my-4 space-y-4"
                    >

                    <div>
                        <label for="cell_verification_code" class="block text-sm font-medium text-gray-700">Cell Phone Verification Code</label>
                        <div class="mt-1">
                            <input
                                wire:model.live.debounce.1000ms="cell_verification_code"
                                id="cell_verification_code"
                                name="cell_verification_code"
                                type="numeric"
                                {{-- autocomplete="user_cell_required1432" --}}
                                inputmode="numeric"
                                placeholder="123456"
                                required
                                class="block w-full px-3 py-2 placeholder-gray-400 border border-gray-300 rounded-md shadow-sm appearance-none focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 sm:text-sm"
                            >
                            <span class="text-sm"><i>Enter the 6 digit code we texted you. If you refresh this page you will need to request a new code.</i></span>
                        </div>
                        <x-forms.error errorName="cell_verification_code" />
                    </div>
                    <div>
                        {{-- only enable when user_cell passes validation.. --}}
                        <button
                            wire:click="cell_verification_code_confirm"
                            type="button"
                            class="flex justify-center w-full px-4 py-2 text-sm font-medium text-white bg-indigo-600 border border-transparent rounded-md shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            >
                            Verify Cell Phone Number
                        </button>
                    </div>
                </div>

                {{-- USER EMAIL --}}
                <div
                    x-data="{ validate_email: @entangle('validate_email'), validate_number: @entangle('validate_number'), show_email: @entangle('show_email'), show_name: @entangle('show_name') }"
                    x-show="!validate_number && show_email"
                    x-transition
                    class="my-4 space-y-4"
                    >
                    <div>
                        <label for="user.email" class="block text-sm font-medium text-gray-700">Email Address</label>
                        <div class="mt-1">
                            <input
                                wire:model.live.debounce.1000ms="user.email"
                                id="user.email"
                                name="user.email"
                                type="email"
                                required
                                x-bind:disabled="{{$show_name == TRUE || $validate_email == TRUE ? true : false}}"
                                {{-- x-bind:disabled="{{isset($user) ? isset($user['id']) || isset($user['email']) ? true : false : false}}" --}}
                                class="block w-full px-3 py-2 placeholder-gray-400 border border-gray-300 rounded-md shadow-sm appearance-none disabled:opacity-50 focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 sm:text-sm"
                            >
                            <span class="text-sm" x-show="!show_name && !validate_email"><i><b>Personal email</b> NOT your business email.</i></span>
                        </div>
                        <x-forms.error errorName="user.email" />
                    </div>
                    <div
                        x-data="{ validate_email: @entangle('validate_email'), show_name: @entangle('show_name') }"
                        x-show="!validate_email && !show_name"
                        x-transition
                        class="my-4 space-y-4"
                        >
                        <div>
                            {{-- only enable when user_cell passes validation.. --}}
                            <button
                                wire:click="user_email"
                                type="button"
                                class="flex justify-center w-full px-4 py-2 text-sm font-medium text-white bg-indigo-600 border border-transparent rounded-md shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                >
                                Confirm Email
                            </button>
                        </div>
                    </div>
                </div>

                {{-- EMAIL VERIFICATION CODE --}}
                <div
                    x-data="{ validate_email: @entangle('validate_email') }"
                    x-show="validate_email"
                    x-transition
                    class="my-4 space-y-4"
                    >

                    <div>
                        <label for="email_verification_code" class="block text-sm font-medium text-gray-700">Email Verification Code</label>
                        <div class="mt-1">
                            <input
                                wire:model.live.debounce.1000ms="email_verification_code"
                                id="email_verification_code"
                                name="email_verification_code"
                                type="numeric"
                                inputmode="numeric"
                                required
                                class="block w-full px-3 py-2 placeholder-gray-400 border border-gray-300 rounded-md shadow-sm appearance-none focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 sm:text-sm"
                            >
                            <span class="text-sm"><i>Enter the 6 digit code we emailed you. If you refresh this page you will need to request a new code.</i></span>
                        </div>
                        <x-forms.error errorName="email_verification_code" />
                    </div>

                    <div
                        x-data="{ validate_email: @entangle('validate_email'), show_name: @entangle('show_name') }"
                        x-show="validate_email && !show_name"
                        x-transition
                        class="my-4 space-y-4"
                        >
                        <div>
                            {{-- only enable when user_cell passes validation.. --}}
                            <button
                                wire:click="email_verification_code_confirm"
                                type="button"
                                class="flex justify-center w-full px-4 py-2 text-sm font-medium text-white bg-indigo-600 border border-transparent rounded-md shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                >
                                Verify Email
                            </button>
                        </div>
                    </div>
                </div>

            {{-- USER NAMES AND SUBMIT... --}}
            <form wire:submit="register_user" class="space-y-6">
                <div
                    x-data="{ show_name: @entangle('show_name') }"
                    x-show="show_name"
                    x-transition
                    class="my-4 space-y-4"
                    >
                    <div>
                        <label for="user.first_name" class="block text-sm font-medium text-gray-700">First Name</label>
                        <div class="mt-1">
                            <input
                                wire:model.live.debounce.1000ms="user.first_name"
                                id="user.first_name"
                                name="user.first_name"
                                required
                                x-bind:disabled="{{isset($user) ? isset($user['id']) ? true : false : false}}"
                                class="block w-full px-3 py-2 placeholder-gray-400 border border-gray-300 rounded-md shadow-sm appearance-none disabled:opacity-50 focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 sm:text-sm"
                            >
                        </div>
                        <x-forms.error errorName="user.first_name" />
                    </div>

                    <div>
                        <label for="user.last_name" class="block text-sm font-medium text-gray-700">Last Name</label>
                        <div class="mt-1">
                            <input
                                wire:model.live.debounce.1000ms="user.last_name"
                                id="user.last_name"
                                name="user.last_name"
                                required
                                x-bind:disabled="{{isset($user) ? isset($user['id']) ? true : false : false}}"
                                class="block w-full px-3 py-2 placeholder-gray-400 border border-gray-300 rounded-md shadow-sm appearance-none disabled:opacity-50 focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 sm:text-sm"
                            >
                        </div>
                        <x-forms.error errorName="user.last_name" />
                    </div>

                    {{-- password --}}
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700">New Password</label>
                        <div class="mt-1">
                            <input
                                wire:model.live.debounce.1000ms="password"
                                id="password"
                                name="password"
                                type="password"
                                required
                                class="block w-full px-3 py-2 placeholder-gray-400 border border-gray-300 rounded-md shadow-sm appearance-none focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 sm:text-sm"
                            >
                        </div>
                        <x-forms.error errorName="password" />
                    </div>

                    <div>
                        <label for="password_confirmation" class="block text-sm font-medium text-gray-700">Password Confirmation</label>
                            <div class="mt-1">
                                <input
                                    wire:model.live.debounce.1000ms="password_confirmation"
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    type="password"
                                    required
                                    class="block w-full px-3 py-2 placeholder-gray-400 border border-gray-300 rounded-md shadow-sm appearance-none focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 sm:text-sm"
                                >
                            </div>
                        <x-forms.error errorName="password_confirmation" />
                    </div>

                    <button
                        type="submit"
                        class="flex justify-center w-full px-4 py-2 text-sm font-medium text-white bg-indigo-600 border border-transparent rounded-md shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                        >
                        Register
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>




