<div class="grid grid-cols-5 gap-4 xl:relative sm:px-6 lg:max-w-7xl">
    {{-- LEFT SIDEBAR --}}
    <div class="col-span-5 space-y-4 lg:col-span-2 lg:h-32">
        {{-- Estimate Details skeleton --}}
        <flux:card class="space-y-2">
            <flux:skeleton.group animate="shimmer">
                <div class="flex items-center justify-between">
                    <flux:skeleton.line class="w-40" />
                    <flux:skeleton class="h-8 w-24 rounded-md" />
                </div>
                <flux:separator variant="subtle" />
                <div class="space-y-3 pt-1">
                    @for($i = 0; $i < 5; $i++)
                        <div class="flex justify-between">
                            <flux:skeleton.line class="w-24" />
                            <flux:skeleton.line class="w-32" />
                        </div>
                    @endfor
                </div>
            </flux:skeleton.group>
        </flux:card>
    </div>

    {{-- RIGHT CONTENT --}}
    <div class="col-span-5 space-y-2 lg:col-span-3 lg:col-start-3">
        {{-- Section card skeletons --}}
        @for($i = 0; $i < 3; $i++)
            <flux:card class="!py-3 !px-6">
                <flux:skeleton.group animate="shimmer">
                    <div class="flex items-center justify-between py-1.5">
                        <div class="flex items-center gap-2">
                            <flux:skeleton.line class="{{ ['w-48', 'w-36', 'w-56'][$i] }}" />
                            <flux:skeleton class="size-5 rounded" />
                            <flux:skeleton class="size-4 rounded" />
                        </div>
                        <flux:skeleton class="size-5 rounded" />
                    </div>
                </flux:skeleton.group>
            </flux:card>
        @endfor
    </div>
</div>
