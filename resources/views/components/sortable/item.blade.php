@props([
    'key'
])

<div {{$attributes}} x-sort:item="{{$key}}" wire:key="{{$key}}">
    {{$slot}}
</div>
