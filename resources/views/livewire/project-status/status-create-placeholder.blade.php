<flux:card class="space-y-6" wire:transition>
    <flux:accordion transition>
        <flux:accordion.item expanded>
            <flux:accordion.heading>
                <flux:heading size="lg" class="mb-0">Project Lifespan</flux:heading>
            </flux:accordion.heading>

            <flux:accordion.content>
                <flux:skeleton.group animate="shimmer">
                    <ul role="list" class="mt-6">
                        @for ($i = 0; $i < 4; $i++)
                            <li class="relative flex gap-x-4 pb-1">
                                @if($i < 3)
                                    <div class="absolute top-0 left-0 flex justify-center w-6 -bottom-1">
                                        <div class="w-px bg-gray-200"></div>
                                    </div>
                                @endif
                                <div class="relative flex items-center justify-center flex-none w-6 h-6 bg-white">
                                    @if($i === 3)
                                        <flux:skeleton class="size-6 rounded-full" />
                                    @else
                                        <div class="h-1.5 w-1.5 rounded-full bg-gray-100 ring-1 ring-gray-300"></div>
                                    @endif
                                </div>
                                <div class="flex-auto py-0.5">
                                    <div class="flex items-center gap-2">
                                        <flux:skeleton class="h-5 w-16 rounded-full" />
                                        <flux:skeleton.line class="w-14" />
                                    </div>
                                    @if($i < 3)
                                        <flux:skeleton.line class="w-16 mt-1" />
                                    @else
                                        <flux:skeleton.line class="w-20 mt-1" />
                                    @endif
                                </div>
                                <div class="flex-none py-0.5">
                                    <flux:skeleton.line class="w-20" />
                                </div>
                            </li>
                        @endfor
                    </ul>
                </flux:skeleton.group>
            </flux:accordion.content>
        </flux:accordion.item>
    </flux:accordion>
</flux:card>
