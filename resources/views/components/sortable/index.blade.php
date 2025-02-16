@props(['handler', 'group'])

<div
    {{$attributes}}
    x-sort="$wire.{{ $handler }}($key, $position)"
    @if($group) x-sort:group="{{$group}}" @endif
    >
    {{$slot}}
</div>
