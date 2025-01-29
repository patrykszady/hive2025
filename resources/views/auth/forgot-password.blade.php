<x-guest-layout>
    <div class="flex flex-col justify-center min-h-full min-h-screen py-12 bg-gray-100 sm:px-6 lg:px-8">
        <div class="mx-4 sm:mx-auto sm:w-full sm:max-w-md">

            <a href="{{route('welcome')}}"><img class="w-auto mx-auto h-36" src="{{ asset('favicon.png') }}" alt="{{env('APP_NAME')}}"></a>
            <h2 class="mt-6 text-3xl font-bold tracking-tight text-center text-gray-900">Forgot your password?</h2>
            <p class="mt-2 text-center text-gray-600 text-md">
                Just let us know your email address and we will email you a password reset link that will allow you to choose a new one.
            </p>
        </div>

        <div class="mx-4 mt-8 sm:mx-auto sm:w-full sm:max-w-md">
            <div class="px-4 pt-1 pb-4 bg-white shadow sm:rounded-lg sm:px-10">
                <form method="POST" action="{{ route('password.email') }}" class="space-y-6" >
                @csrf

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700">Email address</label>
                    <div>
                    <input id="email" name="email" type="email" autocomplete="email" required class="block w-full px-3 py-2 placeholder-gray-400 border border-gray-300 rounded-md shadow-sm appearance-none focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 sm:text-sm">
                    </div>
                    <x-forms.error errorName="email" />
                </div>

                <div>
                    <button type="submit" class="flex justify-center w-full px-4 py-2 mt-1 text-sm font-medium text-white bg-indigo-600 border border-transparent rounded-md shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                        Email Password Reset Link
                    </button>
                </div>
                </form>

                {{-- <div class="mt-6">
                    <div class="relative">
                        <div class="absolute inset-0 flex items-center">
                            <div class="w-full border-t border-gray-300"></div>
                        </div>
                        <div class="relative flex justify-center text-sm">
                            <span class="px-2 text-gray-500 bg-white"></span>
                        </div>
                    </div>
                </div> --}}
                <div>
                    <!-- Session Status -->
                    <x-auth-session-status class="mb-4" :status="session('status')" />

                    <!-- Validation Errors -->
                    {{-- <x-auth-validation-errors class="mb-4" :errors="$errors" /> --}}
                    <x-forms.error errorName="email_not_registered" />
                </div>
                @if($errors->has('email_not_registered'))
                    <x-misc.hr :padding="''">
                        Register Instead
                    </x-misc.hr>
                    <div>
                        <a href="{{ route('registration') }}" type="button" class="flex justify-center w-full px-4 py-2 mt-6 text-white bg-indigo-600 border border-transparent rounded-md shadow-sm text-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                            Register
                        </a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-guest-layout>
