@props([
    'label' => null
])

<flux:field>
    <div class="py-1 grid grid-cols-3 gap-4">
        <dt class="text-sm font-medium text-gray-900"><flux:label>{{ucfirst($label)}}</flux:label></dt>
        <dd class="text-sm text-gray-700 col-start-2 col-span-2">
            {{$slot}}
        </dd>
    </div>
</flux:field>
