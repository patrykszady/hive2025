<div {{ $attributes->merge(['class' => 'sm:col-span-1']) }}>
    <dt class="text-sm font-medium text-gray-500">{{$title}}</dt>
    <dd class="mt-1 text-md text-gray-900">
        @if(isset($href))
            <a href="{{$href}}">
                {{$details}}
            </a>
        @else
            {{$details}}
        @endif
    </dd>
</div>