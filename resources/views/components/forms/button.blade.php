{{-- button color --}}

<button
    {{ $attributes->merge([
        'type' => 'button',
        'class' => 'inline-flex items-center px-4 py-2 ml-3 text-sm text-white bg-indigo-600 border border-transparent rounded-md shadow-sm disabled:opacity-50 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500'
        ]) }}>
    {{ $slot }}
</button>
