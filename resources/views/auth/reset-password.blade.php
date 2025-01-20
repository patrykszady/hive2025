@section('title', 'Hive Contractors | Forgot Password')
<x-guest-layout>
    <div class="flex flex-col justify-center min-h-full min-h-screen py-12 bg-gray-100 sm:px-6 lg:px-8">
        <div class="sm:mx-auto sm:w-full sm:max-w-md">

            <a href="{{route('welcome')}}"><img class="w-auto h-24 mx-auto" src="{{ asset('favicon.png') }}" alt="{{env('APP_NAME')}}"></a>
            <h2 class="mt-6 text-3xl font-bold tracking-tight text-center text-gray-900">Reset your password.</h2>
        </div>

        <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
            <div class="px-4 py-8 bg-white shadow sm:rounded-lg sm:px-10">
                <form method="POST" action="{{ route('password.update') }}" class="space-y-6">

                @csrf
                <!-- Password Reset Token -->
                <input type="hidden" name="token" value="{{ $request->route('token') }}">

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700">Email address</label>
                    <div class="mt-1">
                    <input id="email" readonly name="email" type="email" value="{{old('email', $request->email)}}" autocomplete="email" required autofocus class="block w-full px-3 py-2 placeholder-gray-400 border border-gray-300 rounded-md shadow-sm appearance-none focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 sm:text-sm">
                    </div>
                    <x-forms.error errorName="email" />
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700">New Password</label>
                    <div class="mt-1">
                    <input id="password" name="password" type="password" required class="block w-full px-3 py-2 placeholder-gray-400 border border-gray-300 rounded-md shadow-sm appearance-none focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 sm:text-sm">
                    </div>
                    <x-forms.error errorName="password" />
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-gray-700">Password Confirmation</label>
                    <div class="mt-1">
                    <input id="password_confirmation" name="password_confirmation" type="password" required class="block w-full px-3 py-2 placeholder-gray-400 border border-gray-300 rounded-md shadow-sm appearance-none focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 sm:text-sm">
                    </div>
                    <x-forms.error errorName="password_confirmation" />
                </div>

                <div>
                    <button type="submit" class="flex justify-center w-full px-4 py-2 mt-1 text-sm font-medium text-white bg-indigo-600 border border-transparent rounded-md shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                        Reset Password
                    </button>
                </div>
                </form>

                <div class="mt-6">
                    <div class="relative">
                        <div class="absolute inset-0 flex items-center">
                            <div class="w-full border-t border-gray-300"></div>
                        </div>
                        <div class="relative flex justify-center text-sm">
                            <span class="px-2 text-gray-500 bg-white"></span>
                        </div>
                    </div>
                </div>
                <div>
                    <!-- Session Status -->
                    <x-auth-session-status class="mb-4" :status="session('status')" />

                    <!-- Validation Errors -->
                    <x-auth-validation-errors class="mb-4" :errors="$errors" />
                </div>
            </div>
        </div>
    </div>
</x-guest-layout>
