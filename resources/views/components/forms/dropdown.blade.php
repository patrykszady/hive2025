@props([
    'name',
    'type' => 'text',
    'hint' => null,
    'textSize' => 'sm',
])

@php
    $input_classes = 'flex-1 block w-full min-w-0 rounded-none sm:text-' . $textSize . ' hover:bg-gray-50';

    if($hint){
        $input_classes .= ' rounded-r-md';
    }else{
        $input_classes .= ' rounded-md';
    }

    // 10-27-2021 Why cant we use @error here?
    if($errors->has($name)){
        $input_classes .= ' focus:ring-red-500 focus:border-red-500 border-red-300 text-red-900 placeholder-red-200';
        $label_text_color = 'red';
    }else{
        $input_classes .= ' focus:ring-indigo-500 focus:border-indigo-500 border-gray-300 placeholder-gray-200';
        $label_text_color  = 'gray';
    }
@endphp
{{-- divide-y divide-gray-200 --}}
<div class="px-6 lg:px-32 space-y-8 sm:space-y-5">
    {{-- <div>
        <h3 class="text-lg leading-6 font-medium text-gray-900">
            Profile
        </h3>
        <p class="mt-1 max-w-2xl text-sm text-gray-500">
            This information will be displayed publicly so be careful what you share.
        </p>
    </div> --}}

    <div class="sm:grid sm:grid-cols-3 sm:gap-4 sm:items-start pt-5">
        <label 
            for="{{ $name }}" 
            class="block text-sm font-medium text-{{$label_text_color}}-700 sm:mt-px sm:pt-2"
            >
            {{ucfirst( $name )}}
        </label>
        <div class="mt-1 sm:mt-0 sm:col-span-2">
            <div class="max-w-lg flex rounded-md shadow-sm">
                {{-- if props has hint --}}
                @if($hint)
                    <span
                        class="inline-flex items-center px-3 rounded-l-md border border-r-0 border-{{$label_text_color}}-300 bg-{{$label_text_color}}-50 text-{{$label_text_color}}-500 sm:text-sm">
                        {{$hint}}
                    </span>
                @endif
                
                @if($type === 'textarea')
                    <textarea
                        name="{{ $name }}"
                        id="{{ $name }}" 
                        autocomplete="{{ $name }}"
                        class="max-w-lg shadow-sm block w-full focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm border border-gray-300 rounded-md hover:bg-gray-50 placeholder-gray-200"
                        {{ $attributes() }}
                        >
                    </textarea>
                @else
                    <input 
                        {{-- wire:model.live.debounce.500ms="{{ $name }}"  --}}
                        type="{{ $type }}" 
                        name="{{ $name }}"
                        id="{{ $name }}" 
                        autocomplete="{{ $name }}"
                        class="{{ $input_classes }}"
                        
                        {{-- {{ $attributes(['value' => old($name)]) }} --}}
                        {{ $attributes() }}
                    >
                @endif

            </div>
            <x-forms.error name="{{ $name }}">
            
            </x-forms.error>
        </div>
    </div>
</div>