@section('title', 'Hive Contractors | Login')
<x-guest-layout>

<!--
  This example requires some changes to your config:

  ```
  // tailwind.config.js
  module.exports = {
	// ...
	plugins: [
	  // ...
	  require('@tailwindcss/forms'),
	],
  }
  ```
-->
<!--
  This example requires updating your template:

  ```
  <html class="h-full bg-gray-50">
  <body class="h-full">
  ```
-->
<div class="flex flex-col justify-center min-h-full min-h-screen py-12 bg-gray-100 sm:px-6 lg:px-8">
	<div class="sm:mx-auto sm:w-full sm:max-w-md">
		<a href="{{route('welcome')}}"><img class="w-auto h-48 mx-auto" src="{{ asset('favicon.png') }}" alt="{{env('APP_NAME')}}"></a>
		<h2 class="mt-6 text-3xl font-bold tracking-tight text-center text-gray-900">Sign in to your hive</h2>
		<p class="mt-2 text-sm text-center text-gray-600">
			Or
			<a href="{{route('registration')}}" class="font-medium text-indigo-600 hover:text-indigo-500">create a new hive, free forever</a>
		</p>
	</div>

	<div class="m-4 mt-8 sm:mx-auto sm:w-full sm:max-w-md">
		<div class="px-4 py-8 bg-white shadow sm:rounded-lg sm:px-10">
			<form method="POST" action="{{ route('login') }}" class="space-y-6" >
				@csrf
                @if(session('error'))
                    {{-- <div class="flex items-center p-2 leading-none text-red-100 bg-red-800 lg:rounded-full lg:inline-flex" role="alert">
                        <span class="flex px-2 py-1 mr-3 text-xs font-bold uppercase bg-red-500 rounded-full">Error</span>
                        <span class="flex-auto mr-2 font-semibold text-left">{{ session('error') }}</span>
                    </div> --}}
                    <div class="p-4 text-red-700 bg-red-100 border-l-4 border-red-500" role="alert">
                        <p class="font-bold">Error</p>
                        <p>{{ session('error') }}</p>
                    </div>
                @endif

				<div>
					<label for="email" class="block text-sm font-medium text-gray-700">Email address</label>
					<div class="mt-1">
					<input id="email" name="email" type="email" autocomplete="email" required class="block w-full px-3 py-2 placeholder-gray-400 border border-gray-300 rounded-md shadow-sm appearance-none focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 sm:text-sm">
					</div>
					<x-forms.error errorName="email" />
				</div>

				<div>
					<label for="password" class="block text-sm font-medium text-gray-700">Password</label>
					<div class="mt-1">
					<input id="password" name="password" type="password" autocomplete="current-password" required class="block w-full px-3 py-2 placeholder-gray-400 border border-gray-300 rounded-md shadow-sm appearance-none focus:border-indigo-500 focus:outline-none focus:ring-indigo-500 sm:text-sm">
					</div>
					<x-forms.error errorName="password" />
				</div>

				<div class="flex items-center justify-between">
					<div class="flex items-center">
					<input id="remember-me" name="remember-me" type="checkbox" class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
					<label for="remember-me" class="block ml-2 text-sm text-gray-900">Remember me</label>
					</div>

					<div class="text-sm">
					<a href="{{ route('password.request') }}" class="font-medium text-indigo-600 hover:text-indigo-500">Forgot your password?</a>
					</div>
				</div>

				<div>
					<button id="submit" type="submit" class="flex justify-center w-full px-4 py-2 text-sm font-medium text-white bg-indigo-600 border border-transparent rounded-md shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">Sign in</button>
				</div>
			</form>

			<div class="mt-6">
				<div class="relative">
					<div class="absolute inset-0 flex items-center">
					<div class="w-full border-t border-gray-300"></div>
					</div>
					<div class="relative flex justify-center text-sm">
					<span class="px-2 text-gray-500 bg-white">Or create new hive</span>
					</div>
				</div>

				<div class="mt-3">
					<a href="{{ route('registration') }}" type="button" class="flex justify-center w-full px-4 py-2 font-medium text-white bg-indigo-600 border border-transparent rounded-md shadow-sm text-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
						Register
					</a>
				</div>
			</div>
		</div>
	</div>
</div>
</x-guest-layout>
