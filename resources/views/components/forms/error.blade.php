@props(['errorName'])

{{-- x-transition.duration.150ms --}}
<div>
    @error($errorName)
        <span class="mt-2 text-sm text-red-600 error">{{$message}}</span>
    @enderror
</div>
