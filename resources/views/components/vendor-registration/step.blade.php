{{-- filepath: /home/patryk/web/hive/resources/views/components/vendor-registration/step.blade.php --}}
@props([
    'status', 
    'icon',
    'label', 
    'description',
    'suffix' => null,
    'isLast' => false
])

<li class="relative flex gap-x-4">
    {{-- Vertical Line --}}
    <div class="absolute {{ $isLast ? 'top-0 h-6' : 'top-0 -bottom-6' }} left-0 flex w-6 justify-center">
        <div class="w-px bg-zinc-200"></div>
    </div>
    
    {{-- Status Circle --}}
    <div class="relative flex {{ $status === 'current' ? 'size-8 -ml-1' : 'size-6' }} flex-none items-center justify-center bg-white">
        @if($status === 'upcoming')
            <div class="size-1.5 rounded-full bg-zinc-100 ring-1 ring-zinc-300"></div>
        @else
            <span class="flex {{ $status === 'current' ? 'size-8' : 'size-6' }} items-center justify-center rounded-full {{ $status === 'completed' ? 'bg-green-500' : 'bg-accent' }} ring-1 ring-white">
                <x-dynamic-component 
                    :component="'flux::icon.'.$icon" 
                    variant="solid" 
                    class="{{ $status === 'current' ? 'size-5' : 'size-4' }} text-white" 
                />
            </span>
        @endif
    </div>
    
    {{-- Label --}}
    <p class="flex-auto py-0.5 text-sm text-zinc-600">
        @if($label)
            {{ $label }} 
        @endif
        <span class="font-medium text-zinc-900">{{ $description }}</span>
        @if($suffix)
            {{ $suffix }}
        @endif
    </p>
</li>