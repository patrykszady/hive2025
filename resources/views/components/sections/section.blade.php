@props([
    'cols' => '2'
])

<section aria-labelledby="applicant-information-title" {{ $attributes->merge(['class' => '']) }}>
    <div class="bg-white shadow sm:rounded-lg">
        @if(isset($heading))
            <div class="px-4 py-5 sm:px-6">
                {{$heading}}
            </div>
        @endif

        <div class="border-t border-gray-200 px-4 py-5 sm:px-6">
            <dl class="grid grid-cols-1 gap-x-4 gap-y-8 sm:grid-cols-{{$cols}}">
                {{$slot}}
            </dl>
        </div>

        @if(isset($footer))
            <div>
                {{$footer}}
            </div>
        @endif
    </div>
</section>
